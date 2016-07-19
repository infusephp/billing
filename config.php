<?php

/* This configuration is used to run the tests */

return  [
  'app' => [
    'title' => 'Test Site',
    'salt' => 'replacewithrandomstring',
  ],
  'services' => [
    'db' => 'JAQB\Services\Database',
    'errors' => 'App\Billing\Services\ErrorStack',
    'model_driver' => 'App\Billing\Services\ModelDriver',
    'pdo' => 'Infuse\Services\Pdo',
  ],
  'models' => [
    'driver' => 'Pulsar\Driver\DatabaseDriver',
  ],
  'database' => [
    'type' => 'mysql',
    'user' => 'root',
    'password' => '',
    'host' => '127.0.0.1',
    'name' => 'mydb',
  ],
  'stripe' => [
    'secret' => 'apiKey',
  ],
  'billing' => [
    'model' => 'TestBillingModel',
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
