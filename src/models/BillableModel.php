<?php

namespace app\billing\models;

use infuse\Model;
use Stripe\Stripe;
use Stripe\Customer;
use app\billing\libs\BillingSubscription;

abstract class BillableModel extends Model
{
    private static $billingProperties = [
        'plan' => [
            'type' => 'string',
            'null' => true,
        ],
        'stripe_customer' => [
            'type' => 'string',
            'null' => true,
            'admin_html' => '<a href="https://manage.stripe.com/customers/{stripe_customer}" target="_blank">{stripe_customer}</a>',
        ],
        'renews_next' => [
            'type' => 'date',
            'null' => true,
            'admin_type' => 'datepicker',
            'admin_hidden_property' => true,
        ],
        'trial_ends' => [
            'type' => 'date',
            'null' => true,
            'admin_type' => 'datepicker',
            'admin_hidden_property' => true,
        ],
        'past_due' => [
            'type' => 'boolean',
            'default' => false,
            'admin_type' => 'checkbox',
            'admin_hidden_property' => true,
        ],
        'canceled' => [
            'type' => 'boolean',
            'default' => false,
            'admin_type' => 'checkbox',
            'default' => false,
        ],
        'canceled_at' => [
            'type' => 'date',
            'null' => true,
            'admin_type' => 'datepicker',
            'admin_hidden_property' => true,
        ],
        'not_charged' => [
            'type' => 'boolean',
            'default' => false,
            'admin_type' => 'checkbox',
            'admin_hidden_property' => true,
        ],
    ];

    /**
     * Returns data for this model to be set when creating Stripe Customers.
     *
     * @return array
     */
    abstract public function stripeCustomerData();

    ////////////////////
    // HOOKS
    ////////////////////

    protected static function propertiesHook()
    {
        return array_replace(parent::propertiesHook(), self::$billingProperties);
    }

    protected function preCreateHook(&$data)
    {
        if (isset($data['not_charged']) && !$this->app['user']->isAdmin()) {
            unset($data['not_charged']);
        }

        return true;
    }

    protected function preSetHook(&$data)
    {
        if (isset($data['not_charged']) && !$this->app['user']->isAdmin()) {
            unset($data['not_charged']);
        }

        if (isset($data['canceled']) && $data['canceled'] && !$this->canceled) {
            $data['canceled_at'] = time();
        }

        return true;
    }

    /**
     * Attempts to create or retrieve the Stripe Customer for this model.
     *
     * @return Customer|false
     */
    public function stripeCustomer()
    {
        $apiKey = $this->app['config']->get('stripe.secret');

        // attempt to retreive the customer on stripe
        try {
            if ($custId = $this->stripe_customer) {
                return Customer::retrieve($custId, $apiKey);
            }
        } catch (\Exception $e) {
            $this->app['logger']->debug($e);
            $this->app['errors']->push([
                'error' => 'stripe_error',
                'message' => $e->getMessage(), ]);

            return false;
        }

        // create the customer on stripe
        try {
            // This is necessary because save() on stripe objects does
            // not accept an API key or save one from the retrieve() request
            Stripe::setApiKey($this->app['config']->get('stripe.secret'));

            $customer = Customer::create($this->stripeCustomerData(), $apiKey);

            // save the new customer id on the model
            $this->grantAllPermissions();
            $this->set('stripe_customer', $customer->id);
            $this->enforcePermissions();

            return $customer;
        } catch (\Exception $e) {
            $this->app['logger']->debug($e);
            $this->app['errors']->push([
                'error' => 'stripe_error',
                'message' => $e->getMessage(), ]);
        }

        return false;
    }

    /**
     * Sets the default source to charge for the customer in Stripe. If
     * there is an existing default source, it will be deleted and replaced
     * with the new one.
     *
     * @param string $token
     *
     * @return boolean
     */
    public function setDefaultCard($token)
    {
        $customer = $this->stripeCustomer();

        if (!$customer || empty($token)) {
            return false;
        }

        // This is necessary because save() on stripe objects does
        // not accept an API key or save one from the retrieve() request
        Stripe::setApiKey($this->app['config']->get('stripe.secret'));

        try {
            $customer->source = $token;
            $customer->save();

            return true;
        } catch (\Exception $e) {
            $this->app['logger']->debug($e);
            $this->app['errors']->push([
                'error' => 'stripe_error',
                'message' => $e->getMessage(), ]);

            return false;
        }
    }

    /**
     * Retreives the subscription for this model.
     *
     * @param string $plan optional billing plan to use
     *
     * @return BillingSubscription
     */
    public function subscription($plan = false)
    {
        if (!$plan) {
            $plan = $this->plan;
        }

        return new BillingSubscription($this, $plan, $this->app);
    }
}
