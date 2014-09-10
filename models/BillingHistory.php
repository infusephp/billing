<?php

/**
 * @package infuse\framework
 * @author Jared King <j@jaredtking.com>
 * @link http://jaredtking.com
 * @version 0.1.16
 * @copyright 2013 Jared King
 * @license MIT
 */
 
namespace app\billing\models;

use infuse\Model;

class BillingHistory extends Model
{
	static $scaffoldApi;

	static $properties = [
		'uid' => [
			'type' => 'number',
			'title' => 'Company',
			'relation' => '\\app\\companies\\models\\Company'
		],
		'payment_time' => [
			'type' => 'date',
			'admin_type' => 'datepicker'
		],
		'amount' => [
			'type' => 'number',
			'searchable' => true,
			'admin_html' => '${amount}'
		],
		'stripe_customer' => [
			'type' => 'string',
			'searchable' => true,
			'admin_html' => '<a href="https://manage.stripe.com/customers/{stripe_customer}" target="_blank">{stripe_customer}</a>',
		],
		'stripe_transaction' => [
			'type' => 'string',
			'searchable' => true,
			'admin_html' => '<a href="https://manage.stripe.com/payments/{stripe_transaction}" target="_blank">{stripe_transaction}</a>',
		],
		'success' => [
			'type' => 'boolean',
			'admin_type' => 'checkbox'
		],
		'error' => [
			'type' => 'string',
			'searchable' => true,
			'admin_hidden_property' => true
		],
		'description' => [
			'type' => 'string',
			'searchable' => true,
			'admin_hidden_property' => true
		]
	];

	protected function hasPermission( $permission, Model $requester )
	{
		return $requester->isAdmin();
	}
}