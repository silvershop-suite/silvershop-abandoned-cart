<?php

declare(strict_types=1);

namespace SilverShop\AbandonedCart\Job;

use SilverShop\AbandonedCart\Service\CartRecoveryService;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * Per-cart, per-step recovery send, enqueued by {@link ScanAbandonedCartsJob} when a row's next step falls due.
 * Delegates to {@link CartRecoveryService::sendStep()}, which claims the step atomically (writes LastStepSent BEFORE
 * sending), so a retried job never double-sends. Retryable + visible in QueuedJobAdmin — the reason
 * symbiote/silverstripe-queuedjobs is a hard requirement.
 *
 * Mirrors {@link \SilverShop\StockAlerts\Job\NotifyBackInStockJob}.
 *
 * @property int $RecoveryID
 * @property int $StepIndex
 */
class SendCartRecoveryJob extends AbstractQueuedJob
{
    // retry_max_attempts / retry_initial_delay / retry_falloff_multiplier (inherited queuedjobs config) are set
    // in _config/abandoned-cart.yml so a transient mail/DB failure is retried a few times with a growing delay.

    public function __construct(int $recoveryID = 0, int $stepIndex = 0)
    {
        if ($recoveryID > 0) {
            $this->RecoveryID = $recoveryID;
            $this->StepIndex = $stepIndex;
        }
    }

    public function getTitle(): string
    {
        return _t(
            self::class . '.TITLE',
            'Send recovery step {step} for cart recovery #{id}',
            ['step' => (int) $this->StepIndex, 'id' => (int) $this->RecoveryID]
        );
    }

    public function getJobType(): string
    {
        return QueuedJob::QUEUED;
    }

    public function process(): void
    {
        $this->currentStep++;

        $sent = CartRecoveryService::create()->sendStep((int) $this->RecoveryID, (int) $this->StepIndex);

        $this->addMessage(sprintf(
            '%s recovery step %d for row #%d.',
            $sent ? 'Sent' : 'Skipped',
            (int) $this->StepIndex,
            (int) $this->RecoveryID
        ));
        $this->isComplete = true;
    }

    /**
     * Queue a per-cart, per-step recovery send.
     */
    public static function enqueue(int $recoveryID, int $stepIndex): void
    {
        if ($recoveryID < 1) {
            return;
        }

        QueuedJobService::singleton()->queueJob(new SendCartRecoveryJob($recoveryID, $stepIndex));
    }
}
