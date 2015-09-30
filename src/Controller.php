<?php

namespace app\billing;

use app\billing\libs\StripeWebhook;
use Stripe\Stripe;

class Controller extends StripeWebhook
{
    public static $properties = [
        'models' => [
            'BillingHistory',
        ],
    ];

    public static $scaffoldAdmin;

    public function middleware($req, $res)
    {
        $this->app->post('/billing/webhook', ['billing\\Controller', 'webhook'])
                  ->get('/billing/syncSubscriptions', ['billing\\Controller', 'syncSubscriptions'])
                  ->get('/billing/syncProfiles', ['billing\\Controller', 'syncProfiles']);
    }

    ////////////////////////////
    // CRON JOBS
    ////////////////////////////

    public function sendTrialReminders()
    {
        $modelClass = $this->app['config']->get('billing.model');

        return $modelClass::sendTrialReminders();
    }

    ////////////////////////////
    // BACKGROUND TASKS
    ////////////////////////////

    public function syncSubscriptions($req, $res)
    {
        if (!$req->isCli()) {
            return $res->setCode(404);
        }

        $doIt = $req->cliArgs(2) == 'confirm';

        // WARNING this will take a long time
        // and is VERY DATABASE INTENSIVE

        $modelClass = $this->app['config']->get('billing.model');
        $models = $modelClass::where([
                'canceled' => 0,
                'not_charged' => 0,
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

            // $diff = array_diff_assoc( $memberUpdateData, $currentMemberData );

            if (count($diff) > 0) {
                echo 'Need to update billing data for company # '.$member->id().":\n";

                echo "-- Difference:\n";
                print_r($diff);

                ++$affected;

                // update subscription information
                if ($doIt) {
                    if ($member->set($memberUpdateData)) {
                        echo "Updated company\n";
                    } else {
                        echo "Could not update company\n";
                    }
                }

                echo "\n";
            }
        }

        echo "$affected copanies differed from Stripe\n";
    }

    public function syncProfiles($req, $res)
    {
        if (!$req->isCli()) {
            return $res->setCode(404);
        }

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
                echo 'Need to update billing data for company # '.$member->id().":\n";

                try {
                    if ($customer->save()) {
                        ++$affected;
                        echo "\tok\n";
                    } else {
                        echo "\tfail\n";
                    }
                } catch (\Exception $e) {
                    echo "\t".$e->getMessage()."\n";
                }
            }
        }

        echo "$affected company Stripe profiles updated\n";
    }
}
