framework-billing [![Build Status](https://travis-ci.org/idealistsoft/framework-billing.png?branch=master)](https://travis-ci.org/idealistsoft/framework-billing)
=================

[![Coverage Status](https://coveralls.io/repos/idealistsoft/framework-billing/badge.png)](https://coveralls.io/r/idealistsoft/framework-billing)
[![Latest Stable Version](https://poser.pugx.org/idealistsoft/framework-billing/v/stable.png)](https://packagist.org/packages/idealistsoft/framework-billing)
[![Total Downloads](https://poser.pugx.org/idealistsoft/framework-billing/downloads.png)](https://packagist.org/packages/idealistsoft/framework-billing)

Stripe billing module for Idealist Framework

## Installation

1. Add the composer package in the require section of your app's `composer.json` and run `composer update`

2. Add a billing section to your `config.php`:
```php
[
	'model' => '\\app\\users\\models\\User',
	'emails' => [
		'failed_payment' => true,
		'payment_receipt' => true,
		'trial_ended' => true,
		'trial_will_end' => true,
		'subscription_canceled' => true
	],
	'defaultPlan' => 'default_plan',
	'plans' => [
		...
	]
]
```