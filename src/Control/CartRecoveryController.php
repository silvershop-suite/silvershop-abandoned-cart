<?php

declare(strict_types=1);

namespace SilverShop\AbandonedCart\Control;

use SilverShop\AbandonedCart\Model\CartRecovery;
use SilverShop\AbandonedCart\Service\CartRecoveryService;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;

/**
 * Public endpoint for abandoned-cart recovery: the tokenised one-click resume (restore the cart) and unsubscribe
 * landings. Routed at `cart-recovery` (see _config/routes.yml), mirroring {@link \SilverShop\StockAlerts\Control\StockAlertController}.
 *
 * resume stamps the attribution click, restores the cart (live-order fast path or snapshot rebuild) and redirects to
 * the cart page; unsubscribe marks the recovery Unsubscribed and shows a thin confirmation landing.
 */
class CartRecoveryController extends Controller
{
    private static string $url_segment = 'cart-recovery';

    private static array $allowed_actions = [
        'resume',
        'unsubscribe',
        'index',
    ];

    private static array $url_handlers = [
        'resume/$Token!' => 'resume',
        'unsubscribe/$Token!' => 'unsubscribe',
        '' => 'index',
    ];

    public function Link($action = null): string
    {
        return Controller::join_links(Director::baseURL(), 'cart-recovery', $action);
    }

    public function index(HTTPRequest $request): HTTPResponse
    {
        return $this->renderState('invalid');
    }

    /**
     * Restore the cart behind a recovery token into this visitor's session and redirect to the cart page. Stamps
     * the first-click attribution before restoring. An unknown/expired token shows the invalid landing.
     */
    public function resume(HTTPRequest $request): HTTPResponse
    {
        $service = CartRecoveryService::create();
        $recovery = $service->byToken((string) $request->param('Token'));
        if (!$recovery) {
            return $this->renderState('invalid');
        }

        // Attribution: record the (first) click before restoring.
        $recovery->markClicked();
        $recovery->write();

        $result = $service->resume($recovery);

        // Stash a one-shot flash (what we restored + anything no longer available) for a theme that wants to show it.
        $request->getSession()->set('CartRecoveryFlash', [
            'Outcome' => (string) $result['outcome'],
            'Skipped' => array_values($result['skipped']),
        ]);

        $redirect = (string) ($result['redirect'] ?: Director::baseURL());

        return $this->redirect($redirect);
    }

    /**
     * One-click unsubscribe: suppress all further recovery mail for this row.
     */
    public function unsubscribe(HTTPRequest $request): HTTPResponse
    {
        $recovery = CartRecoveryService::create()->unsubscribeByToken((string) $request->param('Token'));

        return $this->renderState($recovery ? 'unsubscribed' : 'invalid', $recovery);
    }

    protected function renderState(string $state, ?CartRecovery $recovery = null): HTTPResponse
    {
        $html = $this->customise([
            'Title' => _t(self::class . '.Title', 'Cart recovery'),
            'ShopName' => CartRecoveryService::create()->senderName(),
            'State' => $state,
            'IsUnsubscribed' => $state === 'unsubscribed',
            'IsInvalid' => $state === 'invalid',
            'CartLink' => CartRecoveryService::create()->cartLink(),
        ])->renderWith('SilverShop\\AbandonedCart\\CartRecoveryPage');

        return HTTPResponse::create((string) $html);
    }
}
