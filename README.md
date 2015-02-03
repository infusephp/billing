billing
=================

[![Build Status](https://travis-ci.org/infusephp/billing.png?branch=master)](https://travis-ci.org/infusephp/billing)
[![Coverage Status](https://coveralls.io/repos/infusephp/billing/badge.png)](https://coveralls.io/r/infusephp/billing)
[![Latest Stable Version](https://poser.pugx.org/infuse/billing/v/stable.png)](https://packagist.org/packages/infuse/billing)
[![Total Downloads](https://poser.pugx.org/infuse/billing/downloads.png)](https://packagist.org/packages/infuse/billing)
[![HHVM Status](http://hhvm.h4cc.de/badge/infuse/billing.svg)](http://hhvm.h4cc.de/package/infuse/billing)

Stripe billing module for Infuse Framework

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