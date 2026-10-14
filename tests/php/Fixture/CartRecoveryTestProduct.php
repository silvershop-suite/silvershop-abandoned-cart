<?php

declare(strict_types=1);

namespace SilverShop\AbandonedCart\Tests\Fixture;

use SilverShop\Model\Buyable;
use SilverShop\Model\OrderItem;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/**
 * A minimal {@link Buyable} stand-in for the tests — a plain {@link DataObject}, not a SiteTree {@link
 * \SilverShop\Page\Product}, so the clean-room never has to publish a page to exercise cart capture/rebuild
 * (mirrors silvershop/stock-alerts' TestOnly buyable). Its price and availability are directly settable so the
 * "rebuild at current price" and "skip unavailable" paths can be asserted deterministically.
 *
 * @property string $Title
 * @property float $Price
 * @property bool $Available
 */
class CartRecoveryTestProduct extends DataObject implements Buyable, TestOnly
{
    private static string $table_name = 'AbandonedCart_TestProduct';

    private static array $db = [
        'Title' => 'Varchar',
        'Price' => 'Currency',
        'Available' => 'Boolean',
    ];

    private static array $defaults = [
        'Available' => true,
    ];

    /**
     * The order-item class the cart creates for this buyable (so ShoppingCart::findLineItem()/add() resolve it).
     */
    private static string $order_item = CartRecoveryTestOrderItem::class;

    public function createItem(int $quantity = 1, array $filter = []): OrderItem
    {
        $item = CartRecoveryTestOrderItem::create();
        $item->BuyableTestID = $this->ID;
        $item->Quantity = $quantity;
        if ($filter !== []) {
            $item->update($filter);
        }

        return $item;
    }

    public function canPurchase(?Member $member = null, int $quantity = 1): bool
    {
        return (bool) $this->Available;
    }

    public function sellingPrice(): float
    {
        return (float) $this->Price;
    }

    public function getTitle(): string
    {
        return (string) $this->getField('Title');
    }
}
