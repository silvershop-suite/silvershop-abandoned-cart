<?php

declare(strict_types=1);

namespace SilverShop\AbandonedCart\Job;

use SilverShop\AbandonedCart\Service\CartRecoveryService;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\ORM\FieldType\DBDatetime;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * Recurring abandoned-cart scan: captures newly-eligible carts into {@link \SilverShop\AbandonedCart\Model\CartRecovery}
 * rows, enqueues a per-cart {@link SendCartRecoveryJob} for every row whose next step is due, prunes terminal rows,
 * then re-queues itself `scan_interval_mins` later (one scan scheduled at a time).
 *
 * Mirrors {@link \SilverShop\StockAlerts\Job\ScanStockAlertsJob}. Kick it off with
 * `SendAbandonedCartRecoveryTask --schedule` (or it stays dormant until then).
 */
class ScanAbandonedCartsJob extends AbstractQueuedJob
{
    use Configurable;

    /**
     * Minutes between scan runs (the job re-queues itself this far in the future).
     */
    private static int $scan_interval_mins = 30;

    public function getTitle(): string
    {
        return _t(self::class . '.TITLE', 'Scan for abandoned carts to recover');
    }

    public function getJobType(): string
    {
        return QueuedJob::QUEUED;
    }

    public function process(): void
    {
        $this->currentStep++;

        $result = CartRecoveryService::create()->scanAndEnqueue();

        $this->addMessage(sprintf(
            'Captured %d new cart(s), enqueued %d send(s), stopped %d, completed %d, pruned %d.',
            (int) $result['created'],
            (int) $result['enqueued'],
            (int) $result['stopped'],
            (int) $result['completed'],
            (int) $result['pruned']
        ));
        $this->isComplete = true;

        // Re-arm the recurring scan.
        self::scheduleNext();
    }

    /**
     * Schedule the next scan `scan_interval_mins` from now, unless one is already queued/running.
     */
    public static function scheduleNext(): void
    {
        if (self::isScheduled()) {
            return;
        }

        $mins = max(1, (int) static::config()->get('scan_interval_mins'));
        $startAfter = date('Y-m-d H:i:s', strtotime('+' . $mins . ' minutes', strtotime(DBDatetime::now()->getValue())));

        QueuedJobService::singleton()->queueJob(new ScanAbandonedCartsJob(), $startAfter);
    }

    /**
     * Whether a scan job is already queued or running (so we never pile up duplicates).
     */
    public static function isScheduled(): bool
    {
        return QueuedJobDescriptor::get()
            ->filter([
                'Implementation' => self::class,
                'JobStatus' => [
                    QueuedJob::STATUS_NEW,
                    QueuedJob::STATUS_INIT,
                    QueuedJob::STATUS_RUN,
                    QueuedJob::STATUS_WAIT,
                    QueuedJob::STATUS_PAUSED,
                ],
            ])
            ->exists();
    }
}
