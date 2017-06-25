<?php

use JAQB\Services\ConnectionManager;
use Pulsar\Driver\DatabaseDriver;
use Pulsar\Services\ErrorStack;
use Pulsar\Services\ModelDriver;

/* This configuration is used to run the tests */

return  [
    'app' => [
        'title' => 'Test Site',
        'salt' => 'replacewithrandomstring',
    ],
    'services' => [
        'database' => ConnectionManager::class,
        'errors' => ErrorStack::class,
        'model_driver' => ModelDriver::class,
    ],
    'models' => [
        'driver' => DatabaseDriver::class,
    ],
    'database' => [
        'test' => [
            'type' => 'mysql',
            'user' => 'root',
            'password' => '',
            'host' => '127.0.0.1',
            'name' => 'mydb',
        ]
    ],
    'stripe' => [
        'secret' => 'apiKey',
    ],
    'billing' => [
        'model' => TestBillingModel::class,
        'emails' => [
            'failed_payment' => true,
            'payment_receipt' => true,
            'subscription_canceled' => true,
            'trial_ended' => true,
            'trial_will_end' => true,
        ],
        'trialWillEndReminderDays' => 3,
    ],
];
