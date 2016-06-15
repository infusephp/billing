<?php

namespace App\Billing;

use App\Billing\Libs\StripeWebhook;

class Controller extends StripeWebhook
{
    public static $properties = [
        'models' => [
            'BillingHistory',
        ],
    ];

    public static $scaffoldAdmin;

    public function sendTrialReminders()
    {
        $modelClass = $this->app['config']->get('billing.model');

        list($m, $n) = $modelClass::sendTrialReminders();

        echo "Sent $m trial ending soon notifications\n";
        echo "Sent $n trial ended notifications\n";

        return true;
    }
}
