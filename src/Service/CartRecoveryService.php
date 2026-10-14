<?php

declare(strict_types=1);

namespace SilverShop\AbandonedCart\Service;

use SilverShop\AbandonedCart\Job\SendCartRecoveryJob;
use SilverShop\AbandonedCart\Model\CartRecovery;
use SilverShop\Cart\ShoppingCart;
use SilverShop\Model\Buyable;
use SilverShop\Model\Order;
use SilverShop\Model\OrderItem;
use SilverShop\Newsletter\Service\NewsletterConsent;
use SilverShop\Page\CartPage;
use SilverStripe\Control\Director;
use SilverStripe\Control\Email\Email;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Model\ArrayData;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Security;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * The policy + orchestration layer for abandoned-cart recovery. It owns the detection query (§1), the self-contained
 * snapshot capture (§3), the claim-before-send sequence (§5), the consent gate (§4), the hybrid cart restore (§3),
 * and the retention prune (§7). A single home shared by the recurring {@link \SilverShop\AbandonedCart\Job\ScanAbandonedCartsJob},
 * the per-cart {@link SendCartRecoveryJob}, the {@link \SilverShop\AbandonedCart\Task\SendAbandonedCartRecoveryTask}
 * CLI runner and the {@link \SilverShop\AbandonedCart\Control\CartRecoveryController}.
 *
 * Mirrors {@link \SilverShop\StockAlerts\Service\StockAlertService}: the atomic "flip state *before* sending so a
 * crashed/retried job never double-sends" claim, the sender-identity fallback chain, and best-effort (never-throw)
 * email sends.
 */
class CartRecoveryService
{
    use Configurable;
    use Extensible;
    use Injectable;

    /**
     * The recovery sequence: minutes after abandonment at which each step is sent. The first value also defines
     * when a cart first counts as abandoned.
     *
     * @var array<int, int>
     */
    private static array $steps = [60, 1440, 4320];

    /**
     * Lawful basis: 'legitimate_interest' (default) | 'require_consent'. See §4 / the README.
     */
    private static string $consent_mode = 'legitimate_interest';

    /**
     * Ignore carts whose last edit is older than this many minutes (don't chase stale husks). 0 disables the floor.
     */
    private static int $max_age_mins = 20160;

    /**
     * Safety cap on how many recovery emails a single inline (CLI) pass sends.
     */
    private static int $max_per_run = 100;

    /**
     * Delete terminal rows this many days after they end (their token is nulled immediately on going terminal).
     */
    private static int $retention_days = 30;

    /**
     * Delivery channel: 'native' (v1). 'esp'/'both' are reserved for the v2 ESP-export seam.
     */
    private static string $delivery = 'native';

    /**
     * Attribute a placement even without a recorded resume click (covers typed-not-clicked returns). Off by default.
     */
    private static bool $attribute_on_place_without_click = false;

    /**
     * Sender address for the recovery emails; falls back to store-profile / SiteConfig / the framework admin.
     */
    private static string $from_email = '';

    /**
     * The configured step delays (minutes after abandonment), normalised to a list of positive ints.
     *
     * @return array<int, int>
     */
    public function steps(): array
    {
        $steps = [];
        foreach ((array) static::config()->get('steps') as $mins) {
            $steps[] = max(0, (int) $mins);
        }

        return $steps !== [] ? $steps : [60, 1440, 4320];
    }

    /**
     * The cheap indexed candidate scan (§1): Cart-status orders whose last edit is older than the step-1 delay and
     * newer than the max-age floor. The per-row item/email/no-row guards are applied in {@link createMissingRows()}.
     */
    public function eligibleCarts(): DataList
    {
        $steps = $this->steps();
        $stepOneMins = (int) ($steps[0] ?? 60);
        $now = DBDatetime::now()->getTimestamp();

        $stepOneCutoff = date('Y-m-d H:i:s', $now - $stepOneMins * 60);
        $maxAgeMins = (int) static::config()->get('max_age_mins');
        $floor = $maxAgeMins > 0
            ? date('Y-m-d H:i:s', $now - $maxAgeMins * 60)
            : '1970-01-01 00:00:00';

        return Order::get()->filter([
            'Status' => 'Cart',
            'LastEdited:LessThanOrEqual' => $stepOneCutoff,
            'LastEdited:GreaterThan' => $floor,
        ]);
    }

    /**
     * Create a {@link CartRecovery} row (capturing the email + the JSON cart snapshot) for every newly-eligible
     * cart that does not have one yet. Returns the number created.
     */
    public function createMissingRows(): int
    {
        $created = 0;
        foreach ($this->eligibleCarts() as $order) {
            if (!$order instanceof Order || !$order->Items()->exists()) {
                continue;
            }
            $email = trim(mb_strtolower((string) $order->getLatestEmail()));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            if (CartRecovery::get()->filter('OrderID', (int) $order->ID)->exists()) {
                continue;
            }

            $recovery = CartRecovery::create();
            $recovery->OrderID = (int) $order->ID;
            $recovery->Email = $email;
            $recovery->CartData = $this->captureSnapshot($order);
            $recovery->Source = 'scan';
            $recovery->write();
            $created++;
        }

        return $created;
    }

    /**
     * Capture the cart's reconstructable identity as a JSON snapshot (§3): per line the buyable polymorph +
     * quantity (+ title/price for display), plus the fixed abandonment baseline. Soft-dep modules round-trip
     * their own per-line data via the updateSnapshotLine / updateSnapshot extension hooks.
     */
    public function captureSnapshot(Order $order): string
    {
        $items = [];
        $total = 0.0;
        foreach ($order->Items() as $item) {
            $buyable = $item->Buyable();
            if (!$buyable instanceof DataObject || !$buyable->exists()) {
                continue;
            }
            $qty = (int) $item->Quantity;
            $unit = round((float) $item->UnitPrice(), 4);
            $total += $unit * $qty;
            $line = [
                'BuyableClass' => (string) $buyable->ClassName,
                'BuyableID' => (int) $buyable->ID,
                'Quantity' => $qty,
                'Title' => $buyable->hasMethod('getTitle') ? (string) $buyable->getTitle() : (string) $buyable->Title,
                'UnitPrice' => $unit,
                'Options' => [],
            ];
            $this->extend('updateSnapshotLine', $line, $item);
            $items[] = $line;
        }

        $snapshot = [
            'OrderID' => (int) $order->ID,
            'Currency' => (string) $order->Currency,
            // Line-item subtotal (display only); computed here to avoid an order calculate()/write side effect.
            'Total' => round($total, 4),
            // The fixed schedule baseline — survives the live order being deleted by CartCleanup.
            'AbandonedAt' => (string) $order->LastEdited,
            'CapturedAt' => DBDatetime::now()->getValue(),
            'Items' => $items,
        ];
        $this->extend('updateSnapshot', $snapshot, $order);

        return (string) json_encode($snapshot);
    }

    /**
     * Scan + enqueue (the queued-worker path): prune terminal rows, capture any newly-eligible carts, then for every
     * Active row whose next step is due enqueue a per-cart {@link SendCartRecoveryJob}. Rows that are no longer
     * eligible (placed/emptied) are stopped; a fully-sequenced row is completed.
     *
     * @return array{created:int, enqueued:int, pruned:int, stopped:int, completed:int}
     */
    public function scanAndEnqueue(): array
    {
        $pruned = $this->pruneExpired();
        $created = $this->createMissingRows();

        $enqueued = 0;
        $stopped = 0;
        $completed = 0;

        foreach (CartRecovery::get()->filter('Status', CartRecovery::STATUS_ACTIVE) as $recovery) {
            if (!$recovery instanceof CartRecovery) {
                continue;
            }
            if (!$this->stillEligible($recovery)) {
                $recovery->markStopped();
                $recovery->write();
                $stopped++;
                continue;
            }
            $step = $this->dueStepIndex($recovery);
            if ($step === null) {
                if ($this->isSequenceExhausted($recovery)) {
                    $recovery->markCompleted();
                    $recovery->write();
                    $completed++;
                }
                continue;
            }

            SendCartRecoveryJob::enqueue((int) $recovery->ID, $step);
            $enqueued++;
        }

        return compact('created', 'enqueued', 'pruned', 'stopped', 'completed');
    }

    /**
     * Scan + send inline (the CLI / no-worker path): same as {@link scanAndEnqueue()} but calls {@link sendStep()}
     * directly instead of queuing, bounded by max_per_run.
     *
     * @return array{created:int, sent:int, pruned:int, stopped:int, completed:int}
     */
    public function processDueNow(): array
    {
        self::ensureRequest();

        $pruned = $this->pruneExpired();
        $created = $this->createMissingRows();

        $cap = max(1, (int) static::config()->get('max_per_run'));
        $sent = 0;
        $stopped = 0;
        $completed = 0;

        foreach (CartRecovery::get()->filter('Status', CartRecovery::STATUS_ACTIVE) as $recovery) {
            if (!$recovery instanceof CartRecovery) {
                continue;
            }
            if ($sent >= $cap) {
                break;
            }
            if (!$this->stillEligible($recovery)) {
                $recovery->markStopped();
                $recovery->write();
                $stopped++;
                continue;
            }
            $step = $this->dueStepIndex($recovery);
            if ($step === null) {
                if ($this->isSequenceExhausted($recovery)) {
                    $recovery->markCompleted();
                    $recovery->write();
                    $completed++;
                }
                continue;
            }

            if ($this->sendStep((int) $recovery->ID, $step)) {
                $sent++;
            }
        }

        return compact('created', 'sent', 'pruned', 'stopped', 'completed');
    }

    /**
     * Send one recovery step for one row — the atomic claim-before-send (§5). Re-fetches to claim, short-circuits a
     * duplicate/retried job (LastStepSent already at/past this step), re-checks eligibility + consent, then writes
     * the claim BEFORE sending so a crashed/retried job can never double-send. Returns whether an email was sent.
     */
    public function sendStep(int $recoveryID, int $stepIndex): bool
    {
        $recovery = CartRecovery::get()->byID($recoveryID);
        if (!$recovery instanceof CartRecovery || !$recovery->isActive()) {
            return false;
        }
        // Idempotent claim: this step (or a later one) was already sent.
        if ((int) $recovery->LastStepSent >= $stepIndex) {
            return false;
        }
        if (!$this->stillEligible($recovery)) {
            $recovery->markStopped();
            $recovery->write();
            return false;
        }
        if (!$this->consentAllows((string) $recovery->Email)) {
            $recovery->markStopped();
            $recovery->write();
            return false;
        }

        // CLAIM: write the step + timestamp BEFORE sending. If the send throws/retries, the step is already
        // claimed, so it is never re-sent. Completion (and token kill) is left to the next scan so the final
        // email's links stay live.
        $recovery->LastStepSent = $stepIndex;
        $recovery->LastSentAt = DBDatetime::now()->getValue();
        $recovery->write();

        return $this->sendRecoveryEmail($recovery, $stepIndex);
    }

    /**
     * Whether a row is still worth mailing: if the live order exists it must still be an unplaced, non-empty Cart;
     * if the live order is gone (cleanup) the snapshot alone keeps it recoverable provided it has lines.
     */
    public function stillEligible(CartRecovery $recovery): bool
    {
        $order = $recovery->Order();
        if ($order instanceof Order && $order->exists()) {
            if (!$order->IsCart()) {
                return false; // placed / cancelled — stop the sequence
            }
            if (!$order->Items()->exists()) {
                return false; // emptied
            }
        }

        $items = $recovery->getSnapshot()['Items'] ?? [];

        return is_array($items) && $items !== [];
    }

    /**
     * The next step index due to send now, or null when nothing is due yet (or the whole sequence is sent).
     */
    public function dueStepIndex(CartRecovery $recovery): ?int
    {
        $steps = $this->steps();
        $next = (int) $recovery->LastStepSent + 1;
        if ($next < 0) {
            $next = 0;
        }
        if ($next >= count($steps)) {
            return null;
        }

        $dueAt = strtotime($recovery->getAbandonedAt()) + (int) $steps[$next] * 60;

        return DBDatetime::now()->getTimestamp() >= $dueAt ? $next : null;
    }

    /**
     * Whether every configured step has already been sent (so the row should complete).
     */
    public function isSequenceExhausted(CartRecovery $recovery): bool
    {
        return ((int) $recovery->LastStepSent + 1) >= count($this->steps());
    }

    /**
     * The consent gate (§4). legitimate_interest (default): allowed (the row only ever exists for a
     * checkout-email/member address, and every mail carries one-click unsubscribe + our own suppression).
     * require_consent: only when the address has an explicit NewsletterConsent opt-in.
     */
    public function consentAllows(string $email): bool
    {
        $email = trim(mb_strtolower($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        if ((string) static::config()->get('consent_mode') === 'require_consent') {
            if (class_exists(NewsletterConsent::class)) {
                return NewsletterConsent::has($email);
            }

            return false; // hard opt-in required but no consent source available → don't send
        }

        return true;
    }

    /**
     * Restore a cart for a resume click — hybrid (§3): the exact live-order fast path when the Cart order still
     * exists (core reprices + prunes unavailable on load), else rebuild a fresh cart from the snapshot at current
     * price/stock. Returns the outcome, the resulting cart order, the titles skipped as unavailable, and a redirect.
     *
     * @return array{outcome:string, order:?Order, skipped:array<int,string>, redirect:string}
     */
    public function resume(CartRecovery $recovery): array
    {
        $order = $recovery->Order();
        if ($order instanceof Order && $order->exists()) {
            if (!$order->IsCart()) {
                // Already placed (e.g. recovered on another device) — send them to the order itself.
                return ['outcome' => 'placed', 'order' => $order, 'skipped' => [], 'redirect' => $this->orderLink($order)];
            }
            if ($order->Items()->exists()) {
                // GUARD satisfied (IsCart) — the exact restore. setCurrent() E_USER_ERRORs on a non-cart.
                ShoppingCart::singleton()->setCurrent($order);

                return ['outcome' => 'restored', 'order' => $order, 'skipped' => [], 'redirect' => $this->cartLink()];
            }
        }

        return $this->rebuildFromSnapshot($recovery);
    }

    /**
     * Rebuild a fresh cart from the snapshot at current price/stock (§3). Resolves each buyable, skips anything
     * deleted/unpublished/unpurchasable (surfaced for a "no longer available" message), and adds the rest.
     *
     * @return array{outcome:string, order:?Order, skipped:array<int,string>, redirect:string}
     */
    public function rebuildFromSnapshot(CartRecovery $recovery): array
    {
        self::ensureRequest();

        $cart = ShoppingCart::singleton();
        $restored = 0;
        $skipped = [];
        $order = null;

        foreach ($recovery->getSnapshot()['Items'] ?? [] as $line) {
            if (!is_array($line)) {
                continue;
            }
            $title = (string) ($line['Title'] ?? '');
            $qty = max(1, (int) ($line['Quantity'] ?? 1));
            $buyable = $this->resolveBuyable((string) ($line['BuyableClass'] ?? ''), (int) ($line['BuyableID'] ?? 0));

            if (!$buyable instanceof Buyable || !$this->isPurchasable($buyable, $qty)) {
                $skipped[] = $title;
                continue;
            }

            $options = (array) ($line['Options'] ?? []);
            $this->extend('updateRebuildOptions', $options, $line);

            $added = $cart->add($buyable, $qty, $options);
            if ($added instanceof OrderItem) {
                $restored++;
                $order = $added->Order();
            } else {
                $skipped[] = $title;
            }
        }

        return [
            'outcome' => $restored > 0 ? 'rebuilt' : 'empty',
            'order' => ($order instanceof Order && $order->exists()) ? $order : $cart->current(),
            'restored' => $restored,
            'skipped' => $skipped,
            'redirect' => $this->cartLink(),
        ];
    }

    /**
     * Unsubscribe a recovery by its token (one-click). Marks it Unsubscribed (suppresses all further mail).
     */
    public function unsubscribeByToken(string $token): ?CartRecovery
    {
        $recovery = $this->byToken($token);
        if (!$recovery) {
            return null;
        }
        if ($recovery->Status !== CartRecovery::STATUS_UNSUBSCRIBED) {
            $recovery->markUnsubscribed();
            $recovery->write();
        }

        return $recovery;
    }

    /**
     * Look up an Active (resume-able) recovery by its token. Terminal rows have a nulled token, so a non-empty
     * token only ever resolves an Active row.
     */
    public function byToken(string $token): ?CartRecovery
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        return CartRecovery::get()->filter('Token', $token)->first();
    }

    /**
     * Prune terminal rows past the retention window. Returns the number deleted.
     */
    public function pruneExpired(): int
    {
        $days = (int) static::config()->get('retention_days');
        if ($days <= 0) {
            return 0;
        }
        $cutoff = date('Y-m-d H:i:s', DBDatetime::now()->getTimestamp() - $days * 86400);

        $stale = CartRecovery::get()
            ->exclude('Status', CartRecovery::STATUS_ACTIVE)
            ->filter('LastEdited:LessThan', $cutoff);

        $count = 0;
        foreach ($stale as $recovery) {
            $recovery->delete();
            $count++;
        }

        return $count;
    }

    /**
     * Send the recovery email for one step. Best-effort: returns false (without throwing) on any failure, so a
     * mail error never breaks the claim/job. Stamps the notification-log key + order headers.
     */
    public function sendRecoveryEmail(CartRecovery $recovery, int $stepIndex): bool
    {
        try {
            $this->buildRecoveryEmail($recovery, $stepIndex)->send();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Build the recovery {@link Email} for one step — templates, data, sender identity, subject, and the
     * notification-log header stamps. Separated from the send so the composition (notably the header stamps) is
     * unit-testable.
     */
    public function buildRecoveryEmail(CartRecovery $recovery, int $stepIndex): Email
    {
        $email = Email::create()
            ->setHTMLTemplate('SilverShop\\AbandonedCart\\Email\\CartRecoveryEmail')
            ->setPlainTemplate('SilverShop\\AbandonedCart\\Email\\CartRecoveryEmailPlain')
            ->setData($this->emailData($recovery, $stepIndex))
            ->setTo($recovery->Email)
            ->setFrom($this->senderEmail(), $this->senderName())
            ->setSubject($this->subjectForStep($recovery, $stepIndex));

        // notification-log contract (soft dep): stamp an exact key + order attribution. These are the literal
        // header names NotificationCapture reads; referencing them as strings keeps notification-log optional.
        $headers = $email->getHeaders();
        $headers->addTextHeader('X-SilverShop-Notification-Key', 'cart.recovery');
        $headers->addTextHeader('X-SilverShop-Order-ID', (string) (int) $recovery->OrderID);

        return $email;
    }

    /**
     * Shared template data for the recovery email.
     */
    public function emailData(CartRecovery $recovery, int $stepIndex): ArrayData
    {
        $data = ArrayData::create([
            'ShopName' => $this->senderName(),
            'Email' => $recovery->Email,
            'ResumeLink' => $recovery->ResumeLink(),
            'UnsubscribeLink' => $recovery->UnsubscribeLink(),
            'Items' => $recovery->CartItems(),
            'ItemCount' => $recovery->getItemCount(),
            'CartTotal' => $recovery->getCartTotal(),
            'Step' => $stepIndex,
            'StepNumber' => $stepIndex + 1,
        ]);
        $this->extend('updateEmailData', $data, $recovery, $stepIndex);

        return $data;
    }

    /**
     * Per-step subject line (gentle nudge → reminder → last call), falling back to the first-step copy.
     */
    public function subjectForStep(CartRecovery $recovery, int $stepIndex): string
    {
        return match ($stepIndex) {
            0 => _t(self::class . '.Subject1', 'You left something in your cart'),
            1 => _t(self::class . '.Subject2', 'Still thinking it over? Your cart is waiting'),
            default => _t(self::class . '.Subject3', 'Your cart is waiting — complete your order'),
        };
    }

    public function senderEmail(): string
    {
        $configured = (string) static::config()->get('from_email');
        if ($configured) {
            return $configured;
        }

        $siteConfig = SiteConfig::current_site_config();
        if ($siteConfig->hasField('ContactEmail') && $siteConfig->ContactEmail) {
            return (string) $siteConfig->ContactEmail;
        }

        $admin = Email::config()->get('admin_email');
        if (is_array($admin)) {
            $admin = array_key_first($admin) ?: '';
        }

        return $admin ?: ('no-reply@' . (parse_url(Director::absoluteBaseURL(), PHP_URL_HOST) ?: 'example.com'));
    }

    public function senderName(): string
    {
        $siteConfig = SiteConfig::current_site_config();
        if ($siteConfig->hasMethod('StoreProfileName')) {
            $name = $siteConfig->StoreProfileName();
            if ($name) {
                return (string) $name;
            }
        }

        return (string) $siteConfig->Title;
    }

    /**
     * The shop's cart page link (fallback: the site root).
     */
    public function cartLink(): string
    {
        if (class_exists(CartPage::class)) {
            try {
                $link = (string) CartPage::find_link();
                if ($link !== '') {
                    return $link;
                }
            } catch (\Throwable) {
                // A missing/misconfigured cart page must never break the recovery redirect — fall back to home.
            }
        }

        return Director::baseURL();
    }

    /**
     * The link to an already-placed order (checkout/order for a guest, account/order for a member).
     */
    protected function orderLink(Order $order): string
    {
        try {
            $link = (string) $order->Link();
            if ($link !== '') {
                return $link;
            }
        } catch (\Throwable) {
            // Fall back to home if the order/checkout page link cannot be resolved.
        }

        return Director::baseURL();
    }

    /**
     * Resolve a snapshot line's buyable polymorph to a live, existing Buyable, or null.
     */
    protected function resolveBuyable(string $class, int $id): ?Buyable
    {
        if ($class === '' || $id < 1 || !is_subclass_of($class, DataObject::class)) {
            return null;
        }
        $record = $class::get()->byID($id);

        return ($record instanceof Buyable && $record->exists()) ? $record : null;
    }

    /**
     * Whether a buyable can currently be purchased at the requested quantity (published + canPurchase gate).
     */
    protected function isPurchasable(Buyable $buyable, int $quantity): bool
    {
        // Launder to mixed so the optional Versioned isPublished() (not on the Buyable interface), reached via
        // hasMethod()/__call, analyses cleanly — mirrors silvershop/stock-alerts' StockAlertService::asDynamic().
        $dynamic = $this->asDynamic($buyable);
        if ($dynamic->hasMethod('isPublished') && !$dynamic->isPublished()) {
            return false;
        }

        return $buyable->canPurchase(Security::getCurrentUser(), $quantity);
    }

    /**
     * Launder a buyable to mixed so its optional Versioned/Extensible methods (hasMethod, isPublished), reached via
     * __call, analyse cleanly whether or not the concrete type declares them.
     *
     * @param mixed $buyable
     * @return mixed
     */
    protected function asDynamic($buyable)
    {
        return $buyable;
    }

    /**
     * Ensure a current HTTPRequest is registered. Rebuilding a cart calls canPurchase()/sellingPrice(), which some
     * shops hook with request-dependent code (e.g. silvershop/discounts member pricing calling
     * Security::getCurrentUser()), fataling from the CLI where no request exists. A no-op in a web context.
     */
    public static function ensureRequest(): void
    {
        if (!Director::is_cli()) {
            return;
        }
        try {
            $request = new HTTPRequest('GET', '/');
            $request->setSession(new Session([]));
            Injector::inst()->registerService($request, HTTPRequest::class);
        } catch (\Throwable) {
            // Best-effort — the per-line add() still guards its own calls.
        }
    }
}
