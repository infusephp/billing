<?php

/**
 * @author Jared King <j@jaredtking.com>
 *
 * @link http://jaredtking.com
 *
 * @copyright 2015 Jared King
 * @license MIT
 */

namespace Infuse\Billing\Console;

use Infuse\HasApp;
use Stripe\Stripe;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SyncStripeSubscriptionsCommand extends Command
{
    use HasApp;

    protected function configure()
    {
        $this
            ->setName('billing:sync-stripe-subscriptions')
            ->setDescription('Outputs difference between our database and Stripe subscriptions with the option to update the database (warning: takes a really long time)')
            ->addOption(
                'confirm',
                null,
                InputOption::VALUE_NONE,
                'When set the database will be updated with subscriptions'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->syncSubscriptions($output, $input->getOption('confirm'));

        return 0;
    }

    private function syncSubscriptions($output, $doIt)
    {
        $modelClass = $this->app['config']->get('billing.model');
        $models = $modelClass::where([
                'canceled' => false,
                'not_charged' => false,
                'stripe_customer <> ""', ])
            ->sort('id ASC')
            ->all();

        $affected = 0;

        foreach ($models as $member) {
            $customer = $member->stripeCustomer();

            if (!$customer) {
                continue;
            }

            $memberUpdateData = [];

            if (is_array($customer->subscriptions->data)) {
                // we only use 1 subscription
                if (count($customer->subscriptions->data) > 0) {
                    $subscription = $customer->subscriptions->data[0];

                    if (is_object($subscription)) {
                        $memberUpdateData = [
                            'past_due' => in_array($subscription->status, ['past_due', 'unpaid', 'canceled']),
                            'renews_next' => $subscription->current_period_end,
                        ];

                        if ($subscription->status == 'canceled') {
                            $memberUpdateData['canceled'] = true;
                        }
                    }
                // member has canceled
                } else {
                    $memberUpdateData['canceled'] = true;
                }
            }

            // check if subscription needs to be updated
            $currentMemberData = $member->get([
                'past_due',
                'renews_next',
                'canceled', ]);

            $currentMemberData['past_due'] = (bool) $currentMemberData['past_due'];

            // calculate delta
            $diff = [];
            foreach ($memberUpdateData as $k => $v) {
                if ($v != $currentMemberData[$k]) {
                    $diff[$k] = $v;
                }
            }

            // $diff = array_diff_assoc($memberUpdateData, $currentMemberData);

            if (count($diff) > 0) {
                $output->writeln('Need to update billing data for company # '.$member->id().':');

                $output->writeln('-- Difference:');
                foreach ($diff as $k => $v) {
                    $output->writeln("    $k: $v");
                }
                $output->writeln($diffStr);

                ++$affected;

                // update subscription information
                if ($doIt) {
                    if ($member->set($memberUpdateData)) {
                        $output->writeln('Updated company');
                    } else {
                        $output->writeln('Could not update company');
                    }
                }

                $output->writeln('');
            }
        }

        $output->writeln("$affected copanies differed from Stripe");
    }
}
