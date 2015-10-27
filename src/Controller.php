<?php

namespace app\billing;

use app\billing\libs\StripeWebhook;

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
        $this->app->post('/billing/webhook', ['billing\Controller', 'webhook']);
    }

    public function sendTrialReminders()
    {
        $modelClass = $this->app['config']->get('billing.model');

        return $modelClass::sendTrialReminders();
    }
}
