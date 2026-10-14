<?php

declare(strict_types=1);

namespace SilverShop\AbandonedCart\Tests\Extension;

use SilverShop\AbandonedCart\Model\CartRecovery;
use SilverShop\AbandonedCart\Service\CartRecoveryService;
use SilverShop\AbandonedCart\Tests\Fixture\CartRecoveryTestOrderItem;
use SilverShop\AbandonedCart\Tests\Fixture\CartRecoveryTestProduct;
use SilverShop\Model\Order;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;

class OrderRecoveryExtensionTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        CartRecoveryTestProduct::class,
        CartRecoveryTestOrderItem::class,
    ];

    public function testPlacementWithClickIsAttributedAsRecovered(): void
    {
        [$order, $recovery] = $this->makeOrderAndRecovery(clicked: true);

        $order->extend('onPlaceOrder');

        $reloaded = CartRecovery::get()->byID($recovery->ID);
        $this->assertSame(CartRecovery::STATUS_RECOVERED, $reloaded->Status);
        $this->assertNotEmpty($reloaded->RecoveredAt);
        $this->assertEqualsWithDelta((float) $order->Total(), (float) $reloaded->RecoveredValue, 0.001, 'The order total is recorded as recovered value.');
    }

    public function testPlacementWithoutClickStopsTheSequence(): void
    {
        Config::modify()->set(CartRecoveryService::class, 'attribute_on_place_without_click', false);
        [$order, $recovery] = $this->makeOrderAndRecovery(clicked: false);

        $order->extend('onPlaceOrder');

        $reloaded = CartRecovery::get()->byID($recovery->ID);
        $this->assertSame(CartRecovery::STATUS_STOPPED, $reloaded->Status, 'No click → stopped, not over-claimed as recovered.');
    }

    public function testPlacementWithoutClickAttributedWhenConfigured(): void
    {
        Config::modify()->set(CartRecoveryService::class, 'attribute_on_place_without_click', true);
        [$order, $recovery] = $this->makeOrderAndRecovery(clicked: false);

        $order->extend('onPlaceOrder');

        $this->assertSame(CartRecovery::STATUS_RECOVERED, CartRecovery::get()->byID($recovery->ID)->Status);
    }

    /**
     * @return array{0: Order, 1: CartRecovery}
     */
    private function makeOrderAndRecovery(bool $clicked): array
    {
        $product = CartRecoveryTestProduct::create();
        $product->Title = 'Attributed';
        $product->Price = 12.50;
        $product->write();

        $order = Order::create();
        $order->Status = 'Cart';
        $order->Email = 'buyer@example.com';
        $order->write();
        $item = $product->createItem(2);
        $item->OrderID = $order->ID;
        $item->write();
        $order->calculate();

        $recovery = CartRecovery::create();
        $recovery->OrderID = (int) $order->ID;
        $recovery->Email = 'buyer@example.com';
        $recovery->LastStepSent = 0;
        if ($clicked) {
            $recovery->ClickedAt = DBDatetime::now()->getValue();
        }
        $recovery->write();

        return [$order, $recovery];
    }
}
