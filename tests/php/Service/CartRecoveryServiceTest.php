<?php

declare(strict_types=1);

namespace SilverShop\AbandonedCart\Tests\Service;

use SilverShop\AbandonedCart\Model\CartRecovery;
use SilverShop\AbandonedCart\Service\CartRecoveryService;
use SilverShop\AbandonedCart\Tests\Fixture\CartRecoveryTestOrderItem;
use SilverShop\AbandonedCart\Tests\Fixture\CartRecoveryTestProduct;
use SilverShop\Cart\ShoppingCart;
use SilverShop\Model\Order;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Member;

class CartRecoveryServiceTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        CartRecoveryTestProduct::class,
        CartRecoveryTestOrderItem::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        ShoppingCart::singleton()->clear();
        // Deterministic default sequence for the scheduling / capture tests.
        Config::modify()->set(CartRecoveryService::class, 'steps', [60, 1440, 4320]);
        Config::modify()->set(CartRecoveryService::class, 'max_age_mins', 20160);
    }

    // ---- Detection / eligibility gate -------------------------------------------------------------------------

    public function testGuestWithEmailIsCaptured(): void
    {
        $this->makeCart('guest@example.com', 2, null, 12.50, 120);

        $created = CartRecoveryService::create()->createMissingRows();

        $this->assertSame(1, $created);
        $recovery = CartRecovery::get()->first();
        $this->assertSame('guest@example.com', $recovery->Email);
        $this->assertSame(CartRecovery::STATUS_ACTIVE, $recovery->Status);
    }

    public function testMemberWithoutOrderEmailIsCaptured(): void
    {
        $member = Member::create();
        $member->Email = 'member@example.com';
        $member->write();

        // No Order.Email, but a member cart resolves getLatestEmail() to the member address.
        $this->makeCart('', 1, $member, 20.0, 120);

        $this->assertSame(1, CartRecoveryService::create()->createMissingRows());
        $this->assertSame('member@example.com', CartRecovery::get()->first()->Email);
    }

    public function testGuestWithoutEmailIsSkipped(): void
    {
        $this->makeCart('', 1, null, 12.50, 120);

        $this->assertSame(0, CartRecoveryService::create()->createMissingRows(), 'No address → not recoverable.');
        $this->assertSame(0, CartRecovery::get()->count());
    }

    public function testEmptyCartIsSkipped(): void
    {
        $order = Order::create();
        $order->Status = 'Cart';
        $order->Email = 'empty@example.com';
        $order->write();
        $this->ageOrder($order, 120);

        $this->assertSame(0, CartRecoveryService::create()->createMissingRows(), 'An empty cart is nothing to recover.');
    }

    public function testFreshCartBelowStepOneIsSkipped(): void
    {
        $this->makeCart('fresh@example.com', 1, null, 12.50, 10); // only 10 min old; step one is 60 min

        $this->assertSame(0, CartRecoveryService::create()->createMissingRows());
    }

    public function testTooOldCartIsSkipped(): void
    {
        $this->makeCart('old@example.com', 1, null, 12.50, 60 * 24 * 15); // 15 days > max_age 14 days

        $this->assertSame(0, CartRecoveryService::create()->createMissingRows());
    }

    public function testRescanDoesNotDuplicateRows(): void
    {
        $this->makeCart('once@example.com', 1, null, 12.50, 120);
        $service = CartRecoveryService::create();

        $this->assertSame(1, $service->createMissingRows());
        $this->assertSame(0, $service->createMissingRows(), 'A second scan does not re-capture the same cart.');
        $this->assertSame(1, CartRecovery::get()->count());
    }

    // ---- Snapshot capture -------------------------------------------------------------------------------------

    public function testSnapshotCapturesLineIdentityAndTotal(): void
    {
        $order = $this->makeCart('snap@example.com', 3, null, 12.50, 120);
        CartRecoveryService::create()->createMissingRows();

        $snapshot = CartRecovery::get()->first()->getSnapshot();
        $this->assertCount(1, $snapshot['Items']);
        $line = $snapshot['Items'][0];
        $this->assertSame(CartRecoveryTestProduct::class, $line['BuyableClass']);
        $this->assertSame(3, $line['Quantity']);
        $this->assertEqualsWithDelta(12.50, (float) $line['UnitPrice'], 0.001);
        $this->assertEqualsWithDelta(37.50, (float) $snapshot['Total'], 0.001, 'Total is the line subtotal (3 * 12.50).');
        $this->assertSame((int) $order->ID, (int) $snapshot['OrderID']);
        $this->assertNotEmpty($snapshot['AbandonedAt'], 'The abandonment baseline is captured.');
    }

    // ---- Scheduling -------------------------------------------------------------------------------------------

    public function testDueStepIndexFollowsTheSchedule(): void
    {
        Config::modify()->set(CartRecoveryService::class, 'steps', [60, 1440]);
        $service = CartRecoveryService::create();

        $recovery = CartRecovery::create();
        $recovery->Email = 'due@example.com';
        $recovery->CartData = (string) json_encode([
            'AbandonedAt' => date('Y-m-d H:i:s', DBDatetime::now()->getTimestamp() - 120 * 60), // 2 h ago
            'Items' => [['BuyableClass' => 'X', 'BuyableID' => 1, 'Quantity' => 1]],
        ]);
        $recovery->write();

        $this->assertSame(0, $service->dueStepIndex($recovery), 'Step 0 (60 min) is due at 2 h.');

        $recovery->LastStepSent = 0;
        $this->assertNull($service->dueStepIndex($recovery), 'Step 1 (1440 min / 24 h) is not due yet at 2 h.');
    }

    // ---- Claim-before-send one-shot ---------------------------------------------------------------------------

    public function testSendStepClaimsBeforeSendAndIsOneShot(): void
    {
        $recovery = $this->makeActiveRecovery('oneshot@example.com');
        $service = CartRecoveryService::create();

        $this->assertTrue($service->sendStep((int) $recovery->ID, 0), 'First send goes out.');
        $this->assertEmailSent('oneshot@example.com');

        $reloaded = CartRecovery::get()->byID($recovery->ID);
        $this->assertSame(0, (int) $reloaded->LastStepSent, 'The step is claimed (written).');
        $this->assertNotEmpty($reloaded->LastSentAt);

        // A duplicate/retried job for the same step is a no-op (idempotent claim).
        $this->assertFalse($service->sendStep((int) $recovery->ID, 0), 'The same step never sends twice.');
        $this->assertSame(0, (int) CartRecovery::get()->byID($recovery->ID)->LastStepSent);
    }

    public function testSendStepStampsNotificationHeaders(): void
    {
        $recovery = $this->makeActiveRecovery('headers@example.com', 4242);

        $email = CartRecoveryService::create()->buildRecoveryEmail($recovery, 0);
        $headers = $email->getHeaders();

        $this->assertSame('cart.recovery', trim((string) $headers->get('X-SilverShop-Notification-Key')->getBodyAsString()));
        $this->assertSame('4242', trim((string) $headers->get('X-SilverShop-Order-ID')->getBodyAsString()));
    }

    // ---- Consent modes ----------------------------------------------------------------------------------------

    public function testConsentLegitimateInterestAllows(): void
    {
        Config::modify()->set(CartRecoveryService::class, 'consent_mode', 'legitimate_interest');
        $this->assertTrue(CartRecoveryService::create()->consentAllows('li@example.com'));
    }

    public function testConsentRequireConsentBlocksWithoutNewsletter(): void
    {
        Config::modify()->set(CartRecoveryService::class, 'consent_mode', 'require_consent');
        $service = CartRecoveryService::create();

        // No silvershop/newsletter in the clean-room → no positive-consent source → blocked.
        $this->assertFalse($service->consentAllows('nc@example.com'));

        $recovery = $this->makeActiveRecovery('nc@example.com');
        $this->assertFalse($service->sendStep((int) $recovery->ID, 0), 'require_consent blocks the send.');
        $this->assertSame(CartRecovery::STATUS_STOPPED, CartRecovery::get()->byID($recovery->ID)->Status);
    }

    // ---- Unsubscribe ------------------------------------------------------------------------------------------

    public function testUnsubscribeByToken(): void
    {
        $recovery = $this->makeActiveRecovery('unsub@example.com');
        $token = (string) $recovery->Token;

        $out = CartRecoveryService::create()->unsubscribeByToken($token);
        $this->assertNotNull($out);
        $this->assertSame(CartRecovery::STATUS_UNSUBSCRIBED, $out->Status);
        $this->assertEmpty($out->Token, 'Unsubscribe kills the token.');

        // The now-nulled token no longer resolves.
        $this->assertNull(CartRecoveryService::create()->byToken($token));
    }

    public function testUnsubscribedRowIsNeverMailed(): void
    {
        $recovery = $this->makeActiveRecovery('skip@example.com');
        $recovery->markUnsubscribed();
        $recovery->write();

        $this->assertFalse(CartRecoveryService::create()->sendStep((int) $recovery->ID, 0), 'A terminal row short-circuits.');
    }

    // ---- Hybrid restore ---------------------------------------------------------------------------------------

    public function testResumeLiveOrderFastPath(): void
    {
        $order = $this->makeCart('live@example.com', 2, null, 12.50, 120);
        CartRecoveryService::create()->createMissingRows();
        $recovery = CartRecovery::get()->first();

        $result = CartRecoveryService::create()->resume($recovery);

        $this->assertSame('restored', $result['outcome'], 'A still-live cart uses the exact fast path.');
        $this->assertSame((int) $order->ID, (int) $result['order']->ID);
    }

    public function testResumeRedirectsWhenOrderAlreadyPlaced(): void
    {
        $order = $this->makeCart('placed@example.com', 1, null, 12.50, 120);
        CartRecoveryService::create()->createMissingRows();
        $recovery = CartRecovery::get()->first();

        // Simulate the order being placed between capture and the resume click.
        $order->Status = 'Unpaid';
        $order->write();

        $result = CartRecoveryService::create()->resume($recovery);
        $this->assertSame('placed', $result['outcome'], 'A placed order is not restored as a cart (the IsCart guard).');
    }

    public function testResumeRebuildsFromSnapshotAtCurrentPriceAndSkipsUnavailable(): void
    {
        // Two products in the snapshot; one is removed before the resume.
        $keep = $this->makeProduct(12.50, 'Keeper');
        $gone = $this->makeProduct(9.00, 'Goner');

        $order = Order::create();
        $order->Status = 'Cart';
        $order->Email = 'rebuild@example.com';
        $order->write();
        foreach ([$keep, $gone] as $product) {
            $item = $product->createItem(1);
            $item->OrderID = $order->ID;
            $item->write();
        }
        $this->ageOrder($order, 120);

        CartRecoveryService::create()->createMissingRows();
        $recovery = CartRecovery::get()->first();

        // The live order is deleted (as CartCleanupTask would) AND one product goes away + the kept price rises.
        $order->delete();
        $gone->delete();
        $keep->Price = 20.00;
        $keep->write();

        $result = CartRecoveryService::create()->resume($recovery);

        $this->assertSame('rebuilt', $result['outcome'], 'With no live order, recovery rebuilds from the snapshot.');
        $this->assertSame(1, (int) $result['restored'], 'Only the still-available product is rebuilt.');
        $this->assertContains('Goner', $result['skipped'], 'The removed product is surfaced as unavailable.');

        $rebuilt = $result['order'];
        $this->assertInstanceOf(Order::class, $rebuilt);
        $item = $rebuilt->Items()->first();
        $this->assertEqualsWithDelta(20.00, (float) $item->UnitPrice(), 0.001, 'Rebuild uses the CURRENT price, not the 12.50 snapshot price.');
    }

    // ---- Retention prune --------------------------------------------------------------------------------------

    public function testPruneExpiredDeletesStaleTerminalRows(): void
    {
        Config::modify()->set(CartRecoveryService::class, 'retention_days', 30);

        $recovery = CartRecovery::create();
        $recovery->Email = 'stale@example.com';
        $recovery->markCompleted();
        $recovery->write();
        // Age it beyond the retention window.
        DB::prepared_query(
            'UPDATE "SilverShop_CartRecovery" SET "LastEdited" = ? WHERE "ID" = ?',
            [date('Y-m-d H:i:s', DBDatetime::now()->getTimestamp() - 40 * 86400), $recovery->ID]
        );

        $this->assertSame(1, CartRecoveryService::create()->pruneExpired());
        $this->assertNull(CartRecovery::get()->byID($recovery->ID));
    }

    // ---- Helpers ----------------------------------------------------------------------------------------------

    private function makeProduct(float $price = 12.50, string $title = 'Test product'): CartRecoveryTestProduct
    {
        $product = CartRecoveryTestProduct::create();
        $product->Title = $title;
        $product->Price = $price;
        $product->write();

        return $product;
    }

    private function makeCart(string $email, int $qty, ?Member $member, float $price, int $ageMins): Order
    {
        $product = $this->makeProduct($price);

        $order = Order::create();
        $order->Status = 'Cart';
        if ($email !== '') {
            $order->Email = $email;
        }
        if ($member instanceof Member) {
            $order->MemberID = $member->ID;
        }
        $order->write();

        $item = $product->createItem($qty);
        $item->OrderID = $order->ID;
        $item->write();

        $this->ageOrder($order, $ageMins);

        return $order;
    }

    private function ageOrder(Order $order, int $ageMins): void
    {
        $past = date('Y-m-d H:i:s', DBDatetime::now()->getTimestamp() - $ageMins * 60);
        DB::prepared_query('UPDATE "SilverShop_Order" SET "LastEdited" = ? WHERE "ID" = ?', [$past, $order->ID]);
    }

    private function makeActiveRecovery(string $email, int $orderID = 0): CartRecovery
    {
        $recovery = CartRecovery::create();
        $recovery->Email = $email;
        $recovery->OrderID = $orderID;
        $recovery->CartData = (string) json_encode([
            'OrderID' => $orderID,
            'Total' => 12.50,
            'AbandonedAt' => date('Y-m-d H:i:s', DBDatetime::now()->getTimestamp() - 120 * 60),
            'Items' => [['BuyableClass' => CartRecoveryTestProduct::class, 'BuyableID' => 1, 'Quantity' => 1, 'Title' => 'Widget', 'UnitPrice' => 12.50]],
        ]);
        $recovery->write();

        return $recovery;
    }
}
