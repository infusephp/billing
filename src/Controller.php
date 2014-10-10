<?php

namespace app\billing;

use app\billing\libs\StripeWebhook;

class Controller
{
    use \InjectApp;

    public static $properties = [
        'models' => [
            'BillingHistory'
        ],
        'routes' => [
            'post /billing/webhook' => 'webhook'
        ]
    ];

    public static $scaffoldAdmin;

    public function webhook($req, $res)
    {
        $this->app['user']->enableSU();

        $webhook = new StripeWebhook($req->request(), $this->app);
        $res->setBody($webhook->process());
    }
}
