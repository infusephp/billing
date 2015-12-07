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

    public function middleware($req, $res)
    {
        // add routes
        $this->app->post('/billing/webhook', ['App\Billing\Controller', 'webhook']);
    }

    public function sendTrialReminders()
    {
        $modelClass = $this->app['config']->get('billing.model');

        return $modelClass::sendTrialReminders();
    }
}
