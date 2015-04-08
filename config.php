<?php

/* This configuration is used to run the tests */

return  [
  'site' => [
    'title' => 'Test Site',
    'salt' => 'replacewithrandomstring',
  ],
  'modules' => [
    'middleware' => [
      'auth',
    ],
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
    'emails' => [
      'failed_payment' => true,
      'payment_receipt' => true,
      'subscription_canceled' => true,
      'trial_ended' => true,
      'trial_will_end' => true,
    ],
    'model' => 'TestBillingModel',
  ],
];
