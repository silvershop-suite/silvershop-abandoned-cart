<?php

declare(strict_types=1);

namespace SilverShop\AbandonedCart\Extension;

use SilverShop\AbandonedCart\Model\CartRecovery;
use SilverShop\AbandonedCart\Service\CartRecoveryService;
use SilverShop\Model\Order;
use SilverStripe\Core\Extension;

/**
 * Recovery attribution (§6). Hooks {@link \SilverShop\Checkout\OrderProcessor::placeOrder()}'s `onPlaceOrder`
 * extension point — fired while the order is still the same record the {@link CartRecovery} row points at (the cart
 * *is* the order that gets placed; OrderID never changes across Cart→Unpaid/Paid), and before the cart session is
 * cleared.
 *
 * If an Active recovery exists for this order and it recorded a resume click (`ClickedAt`), the placement is
 * attributed: the row is marked Recovered with the order total. A placement without a recorded click stops the
 * sequence (we don't over-claim) — unless `attribute_on_place_without_click` is on. Entirely best-effort: a failure
 * here never breaks placing an order.
 *
 * @extends Extension<Order>
 */
class OrderRecoveryExtension extends Extension
{
    public function onPlaceOrder(): void
    {
        try {
            $order = $this->getOwner();
            if (!$order instanceof Order || !$order->ID) {
                return;
            }

            $recovery = CartRecovery::get()->filter([
                'OrderID' => (int) $order->ID,
                'Status' => CartRecovery::STATUS_ACTIVE,
            ])->first();
            if (!$recovery instanceof CartRecovery) {
                return;
            }

            $requireClick = !(bool) CartRecoveryService::config()->get('attribute_on_place_without_click');
            if ($requireClick && !$recovery->ClickedAt) {
                // Placed, but not via a recovery click → stop the sequence rather than over-claim the conversion.
                $recovery->markStopped();
                $recovery->write();
                return;
            }

            $recovery->markRecovered((float) $order->Total());
            $recovery->write();
        } catch (\Throwable) {
            // Attribution is best-effort — never break placing an order.
        }
    }
}
