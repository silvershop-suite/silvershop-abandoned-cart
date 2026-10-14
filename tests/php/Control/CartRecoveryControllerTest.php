<?php

declare(strict_types=1);

namespace SilverShop\AbandonedCart\Tests\Control;

use SilverShop\AbandonedCart\Model\CartRecovery;
use SilverShop\AbandonedCart\Service\CartRecoveryService;
use SilverShop\AbandonedCart\Tests\Fixture\CartRecoveryTestOrderItem;
use SilverShop\AbandonedCart\Tests\Fixture\CartRecoveryTestProduct;
use SilverShop\Model\Order;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDatetime;

class CartRecoveryControllerTest extends FunctionalTest
{
    protected $usesDatabase = true;

    // Don't follow the resume redirect — the clean-room DB has no home page, so a followed redirect to "/" 404s.
    // We assert the redirect (302) itself and its attribution side effect instead.
    protected $autoFollowRedirection = false;

    protected static $extra_dataobjects = [
        CartRecoveryTestProduct::class,
        CartRecoveryTestOrderItem::class,
    ];

    public function testResumeStampsClickAndRedirects(): void
    {
        $recovery = $this->makeCapturedRecovery('resume@example.com');

        $response = $this->get('cart-recovery/resume/' . $recovery->Token);

        $this->assertContains($response->getStatusCode(), [301, 302], 'A resume click redirects to the cart.');
        $this->assertNotEmpty(CartRecovery::get()->byID($recovery->ID)->ClickedAt, 'The attribution click is stamped.');
    }

    public function testUnsubscribeMarksRowAndShowsLanding(): void
    {
        $recovery = $this->makeCapturedRecovery('bye@example.com');

        $response = $this->get('cart-recovery/unsubscribe/' . $recovery->Token);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsStringIgnoringCase('unsubscribed', (string) $response->getBody());
        $this->assertSame(CartRecovery::STATUS_UNSUBSCRIBED, CartRecovery::get()->byID($recovery->ID)->Status);
    }

    public function testInvalidTokenShowsInvalidLanding(): void
    {
        $response = $this->get('cart-recovery/resume/not-a-real-token');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsStringIgnoringCase('not valid', (string) $response->getBody());
    }

    private function makeCapturedRecovery(string $email): CartRecovery
    {
        $product = CartRecoveryTestProduct::create();
        $product->Title = 'Controller product';
        $product->Price = 12.50;
        $product->write();

        $order = Order::create();
        $order->Status = 'Cart';
        $order->Email = $email;
        $order->write();
        $item = $product->createItem(1);
        $item->OrderID = $order->ID;
        $item->write();

        DB::prepared_query(
            'UPDATE "SilverShop_Order" SET "LastEdited" = ? WHERE "ID" = ?',
            [date('Y-m-d H:i:s', DBDatetime::now()->getTimestamp() - 120 * 60), $order->ID]
        );

        CartRecoveryService::create()->createMissingRows();

        return CartRecovery::get()->filter('Email', $email)->first();
    }
}
