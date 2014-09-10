<?php

namespace app\billing;

use App;
use app\billing\libs\StripeWebhook;

class Controller
{
    public static $properties = [
        'models' => [
            'BillingHistory'
        ],
        'routes' => [
            'post /billing/webhook' => 'webhook'
        ]
    ];

    public static $scaffoldAdmin;

    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function webhook($req, $res)
    {
        $this->app[ 'user' ]->enableSU();

        $webhook = new StripeWebhook($req->request(), $this->app);
        $res->setBody($webhook->process());
    }
}
