<?php

namespace Infuse\Billing\Models;

use Infuse\Billing\Exception\BillingException;
use Infuse\Billing\Libs\BillingSubscription;
use Infuse\HasApp;
use InvalidArgumentException;
use Pulsar\ACLModel;
use Pulsar\Model;
use Stripe\Customer;
use Stripe\Error\Base as StripeError;
use Stripe\Error\Card as StripeCardError;
use Stripe\Stripe;

abstract class BillableModel extends ACLModel
{
    use HasApp;

    private static $billingProperties = [
        'plan' => [],
        'stripe_customer' => [],
        'renews_next' => [
            'type' => Model::TYPE_DATE,
            'null' => true,
        ],
        'trial_ends' => [
            'type' => Model::TYPE_DATE,
            'null' => true,
        ],
        'past_due' => [
            'type' => Model::TYPE_BOOLEAN,
            'default' => false,
        ],
        'canceled' => [
            'type' => Model::TYPE_BOOLEAN,
            'default' => false,
        ],
        'canceled_at' => [
            'type' => Model::TYPE_DATE,
            'null' => true,
        ],
        'not_charged' => [
            'type' => Model::TYPE_BOOLEAN,
            'default' => false,
        ],
        'last_trial_reminder' => [
            'type' => Model::TYPE_DATE,
            'null' => true,
        ],
    ];

    /**
     * @var bool
     */
    private $_setNotCharged;

    protected function initialize()
    {
        static::$properties = array_replace(static::$properties, self::$billingProperties);

        static::$hidden = array_merge(static::$hidden, ['stripe_customer', 'not_charged', 'last_trial_reminder']);

        parent::initialize();

        self::creating([get_called_class(), 'notChargedGuard']);
    }

    /**
     * Returns data for this model to be set when creating Stripe Customers.
     *
     * @return array
     */
    abstract public function stripeCustomerData();

    ////////////////////
    // HOOKS
    ////////////////////

    public static function notChargedGuard($event)
    {
        $model = $event->getModel();
        if (isset($model->not_charged) && $model->not_charged && !$model->_setNotCharged) {
            throw new InvalidArgumentException('Tried to disable charging on '.static::modelName().' without using isNotCharged()');
        }

        $model->_setNotCharged = false;
    }

    protected function preSetHook(&$data)
    {
        if (isset($data['not_charged']) && $data['not_charged'] && !$this->_setNotCharged) {
            throw new InvalidArgumentException('Tried to disable charging on '.static::modelName().' without using isNotCharged()');
        }
        $this->_setNotCharged = false;

        if (isset($data['canceled']) && $data['canceled'] && !$this->ignoreUnsaved()->canceled) {
            $data['canceled_at'] = time();
        }

        return true;
    }

    ////////////////////
    // GETTERS
    ////////////////////

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

        return new BillingSubscription($this, $plan, $this->getApp());
    }

    /**
     * Attempts to create or retrieve the Stripe Customer for this model.
     *
     * @throws BillingException when the operation fails.
     *
     * @return Customer|false
     */
    public function stripeCustomer()
    {
        $app = $this->getApp();

        $apiKey = $app['config']->get('stripe.secret');

        // attempt to retreive the customer on stripe
        try {
            if ($custId = $this->stripe_customer) {
                return Customer::retrieve($custId, $apiKey);
            }
        } catch (StripeError $e) {
            $app['logger']->debug($e);
            throw new BillingException($e->getMessage(), $e->getCode(), $e);
        }

        // create the customer on stripe
        try {
            // This is necessary because save() on stripe objects does
            // not accept an API key or save one from the retrieve() request
            Stripe::setApiKey($app['config']->get('stripe.secret'));

            $customer = Customer::create($this->stripeCustomerData(), $apiKey);

            // save the new customer id on the model
            $this->stripe_customer = $customer->id;
            $this->grantAllPermissions()->save();
            $this->enforcePermissions();

            return $customer;
        } catch (StripeError $e) {
            // log any errors not related to invalid cards
            if (!($e instanceof StripeCardError)) {
                $app['logger']->error($e);
            }

            throw new BillingException($e->getMessage(), $e->getCode(), $e);
        }
    }

    ////////////////////
    // SETTERS
    ////////////////////

    /**
     * Enables charging for the model.
     *
     * @return self
     */
    public function isCharged()
    {
        $this->not_charged = false;
        $this->_setNotCharged = true;

        return $this;
    }

    /**
     * Disables charging for the model.
     *
     * @return self
     */
    public function isNotCharged()
    {
        $this->not_charged = true;
        $this->_setNotCharged = true;

        return $this;
    }

    /**
     * Sets the default source to charge for the customer in Stripe. If
     * there is an existing default source, it will be deleted and replaced
     * with the new one.
     *
     * @param string $token
     *
     * @throws BillingException when the operation fails.
     *
     * @return bool
     */
    public function setDefaultCard($token)
    {
        $app = $this->getApp();

        $customer = $this->stripeCustomer();

        if (!$customer || empty($token)) {
            return false;
        }

        // This is necessary because save() on stripe objects does
        // not accept an API key or save one from the retrieve() request
        Stripe::setApiKey($app['config']->get('stripe.secret'));

        try {
            $customer->source = $token;
            $customer->save();

            return true;
        } catch (StripeError $e) {
            // log any errors not related to invalid cards
            if (!($e instanceof StripeCardError)) {
                $app['logger']->error($e);
            }

            throw new BillingException($e->getMessage(), $e->getCode(), $e);
        }
    }
}
