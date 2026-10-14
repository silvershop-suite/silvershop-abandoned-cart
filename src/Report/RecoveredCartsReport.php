<?php

declare(strict_types=1);

namespace SilverShop\AbandonedCart\Report;

use SilverShop\AbandonedCart\Model\CartRecovery;
use SilverShop\Reports\ShopPeriodReport;
use SilverShop\Reports\ShopReportQuery;
use SilverStripe\ORM\Queries\SQLSelect;

/**
 * Recovered-carts report (§6): the recovered count + recovered value by period — the attribution core's count-only
 * {@link \SilverShop\Reports\AbandonedCartReport} lacks. Extends core's {@link ShopPeriodReport} exactly like the
 * abandoned-cart report, but over the {@link CartRecovery} table keyed on when the cart was recovered.
 */
class RecoveredCartsReport extends ShopPeriodReport
{
    protected $title = 'Recovered Carts';

    protected $description = 'Carts recovered by the abandoned-cart recovery emails, by period. Group by year, month, or day.';

    protected $dataClass = CartRecovery::class;

    protected $periodfield = '"SilverShop_CartRecovery"."RecoveredAt"';

    protected $grouping = true;

    public function columns(): array
    {
        $period = isset($_GET['filters']['Grouping']) ? $_GET['filters']['Grouping'] : 'Month';

        return [
            'FilterPeriod' => $period,
            'Count' => 'Recovered',
            'RecoveredValue' => [
                'title' => 'Recovered Value',
                'casting' => 'Currency->Nice',
            ],
        ];
    }

    public function query(array $params): ShopReportQuery|SQLSelect
    {
        return parent::query($params)
            ->selectField('COUNT("SilverShop_CartRecovery"."ID")', 'Count')
            ->selectField('SUM("SilverShop_CartRecovery"."RecoveredValue")', 'RecoveredValue')
            ->addWhere(['"Status" = ?' => CartRecovery::STATUS_RECOVERED]);
    }
}
