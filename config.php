<?php

use JAQB\Services\ConnectionManager;
use Pulsar\Driver\DatabaseDriver;
use Pulsar\Services\ModelDriver;
use Infuse\Billing\Tests\TestBillingModel;

/* This configuration is used to run the tests */

return  [
    'app' => [
        'title' => 'Test Site',
        'salt' => 'replacewithrandomstring',
    ],
    'services' => [
        'database' => ConnectionManager::class,
        'model_driver' => ModelDriver::class,
    ],
    'models' => [
        'driver' => DatabaseDriver::class,
    ],
    'database' => [
        'test' => [
            'type' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'name' => 'mydb',
            'user' => 'root',
            'password' => '',
            'charset' => 'utf8',
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]
        ],
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
