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
use Symfony\Component\Console\Output\OutputInterface;

class SyncStripeProfilesCommand extends Command
{
    use HasApp;

    protected function configure()
    {
        $this
            ->setName('sync-stripe-profiles')
            ->setDescription('Syncs Stripe profiles with the database (warning: takes a really long time)');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->syncProfiles($output);

        return 0;
    }

    private function syncProfiles($output)
    {
        // WARNING this will take a long time
        // and is VERY DATABASE INTENSIVE

        $modelClass = $this->app['config']->get('billing.model');
        $models = $modelClass::where(['stripe_customer <> ""'])
            ->sort('id ASC')
            ->all();

        $affected = 0;

        // This is necessary because save() on stripe objects does
        // not accept an API key or save one from the retrieve() request
        Stripe::setApiKey($this->app['config']->get('stripe.secret'));

        foreach ($models as $member) {
            $customer = $member->stripeCustomer();

            if (!$customer) {
                continue;
            }

            $diff = false;
            foreach ($member->stripeCustomerData() as $property => $value) {
                if (is_array($value)) {
                    if (array_keys($value) != $customer->$property->keys()) {
                        $customer->$property = $value;
                        $diff = true;
                    } else {
                        foreach ($value as $property2 => $value2) {
                            if (!isset($customer->$property->$property2) || $customer->$property->$property2 != $value2) {
                                $customer->$property = $value;
                                $diff = true;
                                break;
                            }
                        }
                    }
                } elseif ($customer->$property != $value && !empty($value)) {
                    $customer->$property = $value;
                    $diff = true;
                }
            }

            if ($diff) {
                $output->writeln('Need to update billing data for company # '.$member->id().':');

                try {
                    if ($customer->save()) {
                        ++$affected;
                        $output->writeln("\tok");
                    } else {
                        $output->writeln("\tfail");
                    }
                } catch (\Exception $e) {
                    $output->writeln("\t".$e->getMessage());
                }
            }
        }

        $output->writeln("$affected company Stripe profiles updated");
    }
}
