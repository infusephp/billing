infuse/billing
==============

[![Build Status](https://travis-ci.org/infusephp/billing.svg?branch=master&style=flat)](https://travis-ci.org/infusephp/billing)
[![Coverage Status](https://coveralls.io/repos/infusephp/billing/badge.svg?style=flat)](https://coveralls.io/r/infusephp/billing)
[![Latest Stable Version](https://poser.pugx.org/infuse/billing/v/stable.svg?style=flat)](https://packagist.org/packages/infuse/billing)
[![Total Downloads](https://poser.pugx.org/infuse/billing/downloads.svg?style=flat)](https://packagist.org/packages/infuse/billing)
[![HHVM Status](http://hhvm.h4cc.de/badge/infuse/billing.svg?style=flat)](http://hhvm.h4cc.de/package/infuse/billing)

Subscription membership module for Infuse Framework powered by Stripe

## Installation

1. Install the package with [composer](http://getcomposer.org):

		composer require infuse/billing

2. Add a billing section in your app's configuration:

	```php
	'billing' => [
		'model' => 'App\Users\Models\User',
		'emails' => [
			'trial_will_end' => true,
			'trial_ended' => true,
			'failed_payment' => true,
			'payment_receipt' => true,
			'subscription_canceled' => true
		],
		'defaultPlan' => 'default_plan'
	]
	```

3. Add the console command to run jobs to `console.commands` in your app's configuration:

	```php
	'console' => [
		// ...
		'commands' => [
			// ...
			'App\Billing\Console\ExtendTrialCommand',
			'App\Billing\Console\SyncStripeSubscriptionsCommand',
			'App\Billing\Console\SyncStripeProfilesCommand'
		]
	]
	```

4. (optional) Add the following scheduled job to your app's configuration:

	```php
	'cron' => [
		// ...
		[
		    'id' => 'billing:sendTrialReminders',
		    'class' => 'App\Billing\Jobs\SendTrialReminders',
		    'minute' => 0,
		    'expires' => 1800 // 30 minutes
		]
	]
	```

5. (optional) Add an endpoint to your routing table to receive Stripe webhooks:

	```php
	'routes' => [
		// ...
		'POST /billing/webhook' => [
			'App\Billing\Controller',
			'webhook'
	    ]
	]
	```