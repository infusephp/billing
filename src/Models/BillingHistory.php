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

namespace Infuse\Billing\Models;

use Pulsar\Model;

class BillingHistory extends Model
{
    protected static $properties = [
        'user_id' => [
            'type' => Model::TYPE_INTEGER,
        ],
        'payment_time' => [
            'type' => Model::TYPE_DATE,
        ],
        'amount' => [
            'type' => Model::TYPE_FLOAT,
            'searchable' => true,
        ],
        'stripe_customer' => [
            'searchable' => true,
        ],
        'stripe_transaction' => [
            'searchable' => true,
        ],
        'description' => [
            'searchable' => true,
        ],
        'success' => [
            'type' => Model::TYPE_BOOLEAN,
        ],
        'error' => [
            'null' => true,
            'searchable' => true,
        ],
    ];

    protected static $casts = [];
    protected static $validations = [];
    protected static $protected = [];
    protected static $defaults = [];
}
