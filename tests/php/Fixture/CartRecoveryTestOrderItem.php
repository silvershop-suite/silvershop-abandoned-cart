<?php

declare(strict_types=1);

namespace SilverShop\AbandonedCart\Tests\Fixture;

use SilverShop\Model\OrderItem;
use SilverStripe\Dev\TestOnly;

/**
 * The order-item for {@link CartRecoveryTestProduct}: its buyable relationship points at the TestOnly buyable, so
 * {@link OrderItem::Buyable()} resolves without a real Product.
 *
 * @property int $BuyableTestID
 * @method CartRecoveryTestProduct BuyableTest()
 */
class CartRecoveryTestOrderItem extends OrderItem implements TestOnly
{
    private static string $table_name = 'AbandonedCart_TestOrderItem';

    private static array $has_one = [
        'BuyableTest' => CartRecoveryTestProduct::class,
    ];

    private static string $buyable_relationship = 'BuyableTest';
}
