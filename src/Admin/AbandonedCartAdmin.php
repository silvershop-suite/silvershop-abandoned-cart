<?php

declare(strict_types=1);

namespace SilverShop\AbandonedCart\Admin;

use SilverShop\AbandonedCart\Model\CartRecovery;
use SilverStripe\Admin\ModelAdmin;

/**
 * CMS area listing cart-recovery rows — see which carts were captured, how far the sequence got, and which
 * recovered (and for how much). The recurring scan + per-cart sends are visible in QueuedJobAdmin; the recovered
 * figures by period live in {@link \SilverShop\AbandonedCart\Report\RecoveredCartsReport}.
 */
class AbandonedCartAdmin extends ModelAdmin
{
    private static array $managed_models = [
        CartRecovery::class,
    ];

    private static string $url_segment = 'abandoned-carts';

    private static string $menu_title = 'Abandoned carts';

    private static string $menu_icon_class = 'font-icon-cart';
}
