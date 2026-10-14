<?php

declare(strict_types=1);

namespace SilverShop\AbandonedCart\Task;

use SilverShop\AbandonedCart\Job\ScanAbandonedCartsJob;
use SilverShop\AbandonedCart\Model\CartRecovery;
use SilverShop\AbandonedCart\Service\CartRecoveryService;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * CLI runner / backstop for abandoned-cart recovery — the same scan the recurring {@link ScanAbandonedCartsJob}
 * performs, runnable from cron without a job worker. Dry-run by default (lists eligible carts + what is due); pass
 * --run to actually capture + send due steps inline. --schedule (re-)arms the recurring scan job. Mirrors
 * {@link \SilverShop\StockAlerts\Task\SendStockAlertsTask}.
 */
class SendAbandonedCartRecoveryTask extends BuildTask
{
    protected string $title = 'Send abandoned-cart recovery';

    protected static string $description =
        'Captures abandoned carts and sends their due recovery emails. Dry-run unless --run.';

    public function getOptions(): array
    {
        return [
            new InputOption('run', null, InputOption::VALUE_NONE, 'Actually capture carts and send due recovery emails (otherwise just list).'),
            new InputOption('schedule', null, InputOption::VALUE_NONE, 'Queue the recurring scan job if it is not already scheduled.'),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        CartRecoveryService::ensureRequest();
        $service = CartRecoveryService::create();

        if ($input->getOption('schedule')) {
            ScanAbandonedCartsJob::scheduleNext();
            $output->writeln('Recurring scan job scheduled (if it was not already).');
        }

        if (!$input->getOption('run')) {
            $eligible = $service->eligibleCarts()->count();
            $output->writeln(sprintf('Dry run — %d cart(s) match the abandonment window (pre item/email guards).', $eligible));

            $active = CartRecovery::get()->filter('Status', CartRecovery::STATUS_ACTIVE);
            $output->writeln($active->count() . ' active recovery row(s):');
            foreach ($active as $recovery) {
                $due = $service->dueStepIndex($recovery);
                $output->writeln(sprintf(
                    '  %s — %d item(s), last step sent %d, %s',
                    $recovery->getDisplayEmail(),
                    $recovery->getItemCount(),
                    (int) $recovery->LastStepSent,
                    $due === null ? 'nothing due' : ('step ' . $due . ' due')
                ));
            }
            $output->writeln('Pass --run to capture and send.');

            return Command::SUCCESS;
        }

        $result = $service->processDueNow();
        $output->writeln(sprintf(
            'Captured %d new cart(s); sent %d recovery email(s); stopped %d; completed %d; pruned %d.',
            (int) $result['created'],
            (int) $result['sent'],
            (int) $result['stopped'],
            (int) $result['completed'],
            (int) $result['pruned']
        ));

        return Command::SUCCESS;
    }
}
