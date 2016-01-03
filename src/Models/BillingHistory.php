<?php

/**
 * @author Jared King <j@jaredtking.com>
 *
 * @link http://jaredtking.com
 *
 * @version 0.1.16
 *
 * @copyright 2013 Jared King
 * @license MIT
 */
namespace App\Billing\Models;

use Pulsar\Model;

class BillingHistory extends Model
{
    public static $scaffoldApi;

    protected static $properties = [
        'uid' => [
            'type' => Model::TYPE_NUMBER,
        ],
        'payment_time' => [
            'type' => Model::TYPE_DATE,
            'admin_type' => 'datepicker',
        ],
        'amount' => [
            'type' => Model::TYPE_NUMBER,
            'searchable' => true,
            'admin_html' => '${amount}',
        ],
        'stripe_customer' => [
            'searchable' => true,
            'admin_html' => '<a href="https://manage.stripe.com/customers/{stripe_customer}" target="_blank">{stripe_customer}</a>',
        ],
        'stripe_transaction' => [
            'searchable' => true,
            'admin_html' => '<a href="https://manage.stripe.com/payments/{stripe_transaction}" target="_blank">{stripe_transaction}</a>',
        ],
        'description' => [
            'searchable' => true,
            'admin_hidden_property' => true,
        ],
        'success' => [
            'type' => Model::TYPE_BOOLEAN,
            'admin_type' => 'checkbox',
        ],
        'error' => [
            'null' => true,
            'searchable' => true,
            'admin_hidden_property' => true,
        ],
    ];
}
