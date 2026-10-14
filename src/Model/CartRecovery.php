<?php

declare(strict_types=1);

namespace SilverShop\AbandonedCart\Model;

use SilverShop\Model\Order;
use SilverShop\ORM\FieldType\ShopCurrency;
use SilverStripe\Control\Director;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;

/**
 * One abandoned cart's recovery record: a tokenised, self-contained snapshot of a {@link Order} that was left in
 * `Cart` status, so the shop can email the customer a one-click "return to your cart" link and restore the cart even
 * after core's {@link \SilverShop\Tasks\CartCleanupTask} has deleted the live order.
 *
 * The row is deliberately self-contained (the §3 snapshot decision): it keeps the recipient {@link $Email} and a JSON
 * {@link $CartData} snapshot of the cart's reconstructable identity (per line: BuyableClass/BuyableID/Quantity, plus
 * title/price for display), captured at scan time. Recovery therefore never depends on the ephemeral Cart order
 * surviving — a deleted cart is rebuilt from the snapshot at current prices/stock.
 *
 * It borrows {@link \SilverShop\StockAlerts\Model\StockAlert}'s idioms: a unique {@link $Token}
 * (`bin2hex(random_bytes(16))`) backing the one-click resume/unsubscribe links, and email masking on any display
 * surface. Lifecycle: Active → (Recovered | Unsubscribed | Stopped | Completed); every terminal state nulls the token
 * immediately (killing the live resume link) and is pruned after a retention window.
 *
 * @property string $Token
 * @property string $Email
 * @property string $CartData
 * @property string $Status
 * @property int $LastStepSent
 * @property ?string $LastSentAt
 * @property ?string $ClickedAt
 * @property ?string $RecoveredAt
 * @property float $RecoveredValue
 * @property string $Source
 * @property int $OrderID
 * @method Order Order()
 */
class CartRecovery extends DataObject
{
    public const STATUS_ACTIVE = 'Active';

    public const STATUS_RECOVERED = 'Recovered';

    public const STATUS_UNSUBSCRIBED = 'Unsubscribed';

    public const STATUS_STOPPED = 'Stopped';

    public const STATUS_COMPLETED = 'Completed';

    /**
     * Sentinel for {@link $LastStepSent} meaning "no step sent yet" (step indexes are 0-based).
     */
    public const STEP_NONE = -1;

    private static string $table_name = 'SilverShop_CartRecovery';

    private static string $singular_name = 'Cart recovery';

    private static string $plural_name = 'Cart recoveries';

    /**
     * Mask the recipient's address (m•••@example.com) wherever it is shown back on an admin/report surface. The
     * real {@link $Email} is always used internally for sending; this is display-only privacy hardening. Default on.
     */
    private static bool $mask_displayed_email = true;

    private static array $db = [
        // Plain Varchar (not DBEmail) so a snapshot write never trips SS6's email FieldValidator.
        'Email' => 'Varchar(254)',
        // JSON snapshot of the cart's reconstructable identity (see CartRecoveryService::captureSnapshot()).
        'CartData' => 'Text',
        'Status' => "Enum('Active,Recovered,Unsubscribed,Stopped,Completed','Active')",
        // 0-based index of the last recovery step emailed; -1 = none sent yet.
        'LastStepSent' => 'Int',
        'LastSentAt' => 'Datetime',
        'ClickedAt' => 'Datetime',
        'RecoveredAt' => 'Datetime',
        // ShopCurrency so Nice() renders the shop's currency on the report (plain DBCurrency defaults to $).
        'RecoveredValue' => ShopCurrency::class . '(19,4)',
        'Token' => 'Varchar(64)',
        'Source' => 'Varchar(50)',
    ];

    private static array $has_one = [
        'Order' => Order::class,
    ];

    private static array $indexes = [
        'Token' => ['type' => 'unique', 'columns' => ['Token']],
        // Attribution lookup on placement: "the recovery row for this order".
        'OrderStatus' => ['type' => 'index', 'columns' => ['OrderID', 'Status']],
        'Status' => ['type' => 'index', 'columns' => ['Status']],
    ];

    private static string $default_sort = 'Created DESC';

    private static array $summary_fields = [
        'DisplayEmail' => 'Email',
        'Status' => 'Status',
        'ItemCount' => 'Items',
        'CartTotal' => 'Cart value',
        'LastSentAt.Nice' => 'Last sent',
        'RecoveredValue' => 'Recovered value',
        'Created.Nice' => 'Captured',
    ];

    private static array $searchable_fields = [
        'Email',
        'Status',
    ];

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();

        if (!$this->Status) {
            $this->Status = self::STATUS_ACTIVE;
        }
        // Default the step sentinel on a brand-new row only (an explicit 0+ must survive).
        if (!$this->isInDB() && $this->getField('LastStepSent') === null) {
            $this->LastStepSent = self::STEP_NONE;
        }
        // Mint a token for an active row that lacks one; never re-mint for a terminal row (whose token was
        // nulled on purpose), so a resurrected write can't bring the resume link back from the dead.
        if (!$this->Token && !$this->isTerminal()) {
            $this->Token = bin2hex(random_bytes(16));
        }
        $this->Email = trim(mb_strtolower((string) $this->Email));
    }

    /**
     * Absolute one-click URL that restores this cart into the visitor's session.
     */
    public function ResumeLink(): string
    {
        return Director::absoluteURL('cart-recovery/resume/' . $this->Token);
    }

    /**
     * Absolute one-click URL that unsubscribes this recovery (suppresses all further mail).
     */
    public function UnsubscribeLink(): string
    {
        return Director::absoluteURL('cart-recovery/unsubscribe/' . $this->Token);
    }

    public function isActive(): bool
    {
        return $this->Status === self::STATUS_ACTIVE;
    }

    public function isTerminal(): bool
    {
        return in_array($this->Status, [
            self::STATUS_RECOVERED,
            self::STATUS_UNSUBSCRIBED,
            self::STATUS_STOPPED,
            self::STATUS_COMPLETED,
        ], true);
    }

    /**
     * Mark this recovery converted: a placed order attributed to the recovery click. Stamps the recovered value
     * and kills the token. Caller writes.
     */
    public function markRecovered(float $value): void
    {
        $this->Status = self::STATUS_RECOVERED;
        $this->RecoveredAt = DBDatetime::now()->getValue();
        $this->RecoveredValue = $value;
        $this->setField('Token', null);
    }

    /**
     * Mark unsubscribed (one-click). Terminal; kills the token. Caller writes.
     */
    public function markUnsubscribed(): void
    {
        $this->Status = self::STATUS_UNSUBSCRIBED;
        $this->setField('Token', null);
    }

    /**
     * Mark stopped — the cart was placed-without-click, emptied, or consent was withdrawn. Terminal; kills the
     * token. Caller writes.
     */
    public function markStopped(): void
    {
        $this->Status = self::STATUS_STOPPED;
        $this->setField('Token', null);
    }

    /**
     * Mark the whole sequence sent with no conversion. Terminal; kills the token. Caller writes.
     */
    public function markCompleted(): void
    {
        $this->Status = self::STATUS_COMPLETED;
        $this->setField('Token', null);
    }

    /**
     * Stamp the first resume click (for attribution). Caller writes. No-op once already stamped.
     */
    public function markClicked(): void
    {
        if (!$this->ClickedAt) {
            $this->ClickedAt = DBDatetime::now()->getValue();
        }
    }

    /**
     * The decoded cart snapshot as an associative array (empty on absent/corrupt JSON).
     *
     * @return array<string, mixed>
     */
    public function getSnapshot(): array
    {
        $json = (string) $this->getField('CartData');
        if ($json === '') {
            return [];
        }
        $data = json_decode($json, true);

        return is_array($data) ? $data : [];
    }

    /**
     * The snapshot line items as an {@link ArrayList} of {@link ArrayData} for the recovery email template
     * (Title, Quantity, a ShopCurrency UnitPrice and LineTotal for display).
     */
    public function CartItems(): ArrayList
    {
        $list = ArrayList::create();
        $snapshot = $this->getSnapshot();
        $items = $snapshot['Items'] ?? [];
        if (!is_array($items)) {
            return $list;
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $qty = (int) ($item['Quantity'] ?? 0);
            $unit = (float) ($item['UnitPrice'] ?? 0);
            $list->push(ArrayData::create([
                'Title' => (string) ($item['Title'] ?? ''),
                'Quantity' => $qty,
                'UnitPrice' => ShopCurrency::create()->setValue($unit),
                'LineTotal' => ShopCurrency::create()->setValue($unit * $qty),
            ]));
        }

        return $list;
    }

    /**
     * Total number of units across the snapshot lines.
     */
    public function getItemCount(): int
    {
        $count = 0;
        foreach ($this->getSnapshot()['Items'] ?? [] as $item) {
            if (is_array($item)) {
                $count += (int) ($item['Quantity'] ?? 0);
            }
        }

        return $count;
    }

    /**
     * The snapshot cart total as a ShopCurrency field (renders the shop currency via Nice()).
     */
    public function getCartTotal(): ShopCurrency
    {
        return ShopCurrency::create()->setValue((float) ($this->getSnapshot()['Total'] ?? 0));
    }

    /**
     * The moment the cart was last edited before abandonment (the fixed baseline for the step schedule),
     * read from the snapshot; falls back to the row's own Created.
     */
    public function getAbandonedAt(): string
    {
        $at = (string) ($this->getSnapshot()['AbandonedAt'] ?? '');

        return $at !== '' ? $at : (string) $this->Created;
    }

    /**
     * The address to show on an admin/report surface — masked (m•••@example.com) when {@link $mask_displayed_email}
     * is on (default), the full address otherwise.
     */
    public function getDisplayEmail(): string
    {
        return (bool) static::config()->get('mask_displayed_email')
            ? self::maskEmail((string) $this->Email)
            : (string) $this->Email;
    }

    /**
     * Mask an email's local part to its first character (m•••@example.com).
     */
    public static function maskEmail(string $email): string
    {
        $email = trim($email);
        $at = strpos($email, '@');
        if ($at === false || $at === 0) {
            return $email === '' ? '' : '•••';
        }

        return mb_substr($email, 0, 1) . '•••' . substr($email, $at);
    }

    public function getStatusNice(): string
    {
        return match ($this->Status) {
            self::STATUS_ACTIVE => _t(self::class . '.StatusActive', 'Active'),
            self::STATUS_RECOVERED => _t(self::class . '.StatusRecovered', 'Recovered'),
            self::STATUS_UNSUBSCRIBED => _t(self::class . '.StatusUnsubscribed', 'Unsubscribed'),
            self::STATUS_STOPPED => _t(self::class . '.StatusStopped', 'Stopped'),
            self::STATUS_COMPLETED => _t(self::class . '.StatusCompleted', 'Completed'),
            default => (string) $this->Status,
        };
    }

    public function canView($member = null): bool
    {
        return $this->canModerate($member);
    }

    public function canEdit($member = null): bool
    {
        return $this->canModerate($member);
    }

    public function canCreate($member = null, $context = []): bool
    {
        return $this->canModerate($member);
    }

    public function canDelete($member = null): bool
    {
        return $this->canModerate($member);
    }

    public function canModerate(?Member $member = null): bool
    {
        return Permission::check('CMS_ACCESS_SilverShop\AbandonedCart\Admin\AbandonedCartAdmin', 'any', $member)
            || Permission::check('ADMIN', 'any', $member);
    }
}
