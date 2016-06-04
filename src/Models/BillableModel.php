<?php

namespace App\Billing\Models;

use App\Billing\Libs\BillingSubscription;
use Infuse\Application;
use Pulsar\ACLModel;
use Pulsar\Model;
use Stripe\Customer;
use Stripe\Error\Base as StripeError;
use Stripe\Stripe;

abstract class BillableModel extends ACLModel
{
    private static $billingProperties = [
        'plan' => [
            'null' => true,
        ],
        'stripe_customer' => [
            'null' => true,
            'admin_html' => '<a href="https://manage.stripe.com/customers/{stripe_customer}" target="_blank">{stripe_customer}</a>',
        ],
        'renews_next' => [
            'type' => Model::TYPE_DATE,
            'null' => true,
            'admin_type' => 'datepicker',
            'admin_hidden_property' => true,
        ],
        'trial_ends' => [
            'type' => Model::TYPE_DATE,
            'null' => true,
            'admin_type' => 'datepicker',
            'admin_hidden_property' => true,
        ],
        'past_due' => [
            'type' => Model::TYPE_BOOLEAN,
            'default' => false,
            'admin_type' => 'checkbox',
            'admin_hidden_property' => true,
        ],
        'canceled' => [
            'type' => Model::TYPE_BOOLEAN,
            'default' => false,
            'admin_type' => 'checkbox',
            'default' => false,
        ],
        'canceled_at' => [
            'type' => Model::TYPE_DATE,
            'null' => true,
            'admin_type' => 'datepicker',
            'admin_hidden_property' => true,
        ],
        'not_charged' => [
            'type' => Model::TYPE_BOOLEAN,
            'default' => false,
            'admin_type' => 'checkbox',
            'admin_hidden_property' => true,
        ],
        'last_trial_reminder' => [
            'type' => Model::TYPE_DATE,
            'null' => true,
            'admin_type' => 'datepicker',
            'admin_hidden_property' => true,
        ],
    ];

    /**
     * @var the number of days left when the trial will end
     *          reminder should be sent
     */
    public static $trialWillEndReminderDays = 3;

    protected function initialize()
    {
        static::$properties = array_replace(static::$properties, self::$billingProperties);

        static::$hidden = array_merge(static::$hidden, ['stripe_customer', 'not_charged', 'last_trial_reminder']);

        parent::initialize();
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

    protected function preCreateHook()
    {
        if (isset($this->not_charged) && !$this->getApp()['user']->isAdmin()) {
            unset($this->not_charged);
        }

        return true;
    }

    protected function preSetHook(&$data)
    {
        if (isset($data['not_charged']) && !$this->getApp()['user']->isAdmin()) {
            unset($data['not_charged']);
        }

        if (isset($data['canceled']) && $data['canceled'] && !$this->ignoreUnsaved()->canceled) {
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
        $app = $this->getApp();

        $apiKey = $app['config']->get('stripe.secret');

        // attempt to retreive the customer on stripe
        try {
            if ($custId = $this->stripe_customer) {
                return Customer::retrieve($custId, $apiKey);
            }
        } catch (StripeError $e) {
            $app['logger']->debug($e);
            $app['errors']->push([
                'error' => 'stripe_error',
                'message' => $e->getMessage(), ]);

            return false;
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
            $app['logger']->debug($e);
            $app['errors']->push([
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
            $app['logger']->debug($e);
            $app['errors']->push([
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

        return new BillingSubscription($this, $plan, $this->getApp());
    }

    /**
     * Gets members with trials that are ending soon but not notified yet.
     *
     * @return Pulsar\Iterator
     */
    public static function getTrialsEndingSoon()
    {
        // reminder window is valid for up to 1 day
        $end = time() + self::$trialWillEndReminderDays * 86400;
        $start = $end - 86400;

        return static::where('canceled', false)
            ->where('trial_ends', $start, '>=')
            ->where('trial_ends', $end, '<=')
            ->where('last_trial_reminder IS NULL')
            ->all();
    }

    /**
     * Gets members with trials that have ended but not notified yet.
     *
     * @return Pulsar\Iterator
     */
    public static function getEndedTrials()
    {
        return static::where('canceled', false)
            ->where('trial_ends', 0, '>')
            ->where('trial_ends', time(), '<')
            ->where('renews_next', 0)
            ->where('(last_trial_reminder < trial_ends OR last_trial_reminder IS NULL)')
            ->all();
    }

    /**
     * Sends out trial reminders - trial_will_end and trial_ended.
     *
     * @return array [sent ending soon notices, sent ended notices]
     */
    public static function sendTrialReminders()
    {
        return [
            self::sendTrialWillEndReminders(),
            self::sendTrialEndedReminders(),
        ];
    }

    private static function sendTrialWillEndReminders()
    {
        $app = Application::getDefault();
        $config = $app['config'];
        if (!$config->get('billing.emails.trial_will_end')) {
            return 0;
        }

        $members = static::getTrialsEndingSoon();
        $n = 0;
        foreach ($members as $member) {
            $member->sendEmail(
                'trial-will-end', [
                    'subject' => 'Your trial ends soon on '.$config->get('app.title'),
                    'tags' => ['billing', 'trial-will-end'], ]);

            $member->last_trial_reminder = time();
            $member->grantAllPermissions()->save();

            ++$n;
        }

        return $n;
    }

    private static function sendTrialEndedReminders()
    {
        $app = Application::getDefault();
        $config = $app['config'];
        if (!$config->get('billing.emails.trial_ended')) {
            return 0;
        }

        $members = static::getEndedTrials();
        $n = 0;
        foreach ($members as $member) {
            $member->sendEmail(
                'trial-ended', [
                    'subject' => 'Your '.$config->get('app.title').' trial has ended',
                    'tags' => ['billing', 'trial-ended'], ]);

            $member->last_trial_reminder = time();
            $member->grantAllPermissions()->save();

            ++$n;
        }

        return $n;
    }
}
