<?php

namespace app\billing\libs;

use Stripe_Customer;
use Stripe_Event;

use App;
use app\billing\models\BillingHistory;

define('ERROR_INVALID_EVENT', 'invalid_event');
define('ERROR_LIVEMODE_MISMATCH', 'livemode_mismatch');
define('ERROR_STRIPE_CONNECT_EVENT', 'stripe_connect_event');
define('ERROR_EVENT_NOT_SUPPORTED', 'event_not_supported');
define('ERROR_CUSTOMER_NOT_FOUND', 'customer_not_found');
define('STRIPE_WEBHOOK_SUCCESS', 'OK');

class StripeWebhook
{
    private $event;
    private $app;
    private $apiKey;

    private static $eventHandlers = [
        'charge.failed' => 'chargeFailed',
        'charge.succeeded' => 'chargeSucceeded',
        'customer.subscription.created' => 'updatedSubscription',
        'invoice.payment_succeeded' => 'updatedSubscription',
        'customer.subscription.created' => 'updatedSubscription',
        'customer.subscription.updated' => 'updatedSubscription',
        'customer.subscription.deleted' => 'canceledSubscription',
        'customer.subscription.trial_will_end' => 'trialWillEnd'
    ];

    public function __construct(array $event, App $app)
    {
        $this->event = $event;
        $this->app = $app;
        $this->apiKey = $this->app[ 'config' ]->get('stripe.secret');
    }

    /**
	 * This function receives a Stripe webhook and processes it.
	 *
	 * Currently, we only care about the charge.succeeded and charge.failed events. This method returns a string
	 * because typically the only person that sees the output is a Stripe server
	 *
	 * @return string output
	 */
    public function process()
    {
        if ( !isset($this->event['id'])) {
            return ERROR_INVALID_EVENT;
        }

        // check that the livemode matches our development state
        if (!($this->event['livemode'] && $this->app['config']->get('site.production-level') ||
            !$this->event['livemode'] && !$this->app['config']->get('site.production-level'))) {
            return ERROR_LIVEMODE_MISMATCH;
        }

        if (isset($this->event['user_id'])) {
            return ERROR_STRIPE_CONNECT_EVENT;
        }

        try {
            // retreive the event, unless it is a deauth event
            // since those cannot be retrieved
            $validatedEvent = ($this->event['type'] == 'account.application.deauthorized') ?
                (object) $this->event :
                Stripe_Event::retrieve($this->event['id'], $this->apiKey);

            $type = $validatedEvent->type;
            if (!isset(self::$eventHandlers[$type]))
                return ERROR_EVENT_NOT_SUPPORTED;

            // get the data attached to the event
            $eventData = $validatedEvent->data->object;

            // find out which user this event is for by cross-referencing the customer id
            $modelClass = $this->app['config']->get('billing.model');

            $member = $modelClass::findOne([
                'where' => [
                    'stripe_customer' => $eventData->customer ]]);

            if (!$member)
                return ERROR_CUSTOMER_NOT_FOUND;

            $handler = self::$eventHandlers[$type];
            if ($this->$handler($eventData, $member))
                return STRIPE_WEBHOOK_SUCCESS;
        } catch ( \Exception $e ) {
            $this->app['logger']->error($e);
        }

        return 'error';
    }

    /**
     * Handles failed charge events
     *
     * @param object $eventData
     * @param object $member
     *
     * @return boolean
     */
    public function chargeFailed($eventData, $member)
    {
        // add to billing history
        $description = $eventData->description;

        if (empty($eventData->description) && $member->hasProperty('plan'))
            $description = $member->plan;

        $history = new BillingHistory();
        $history->create([
            'uid' => $member->id(),
            'payment_time' => $eventData->created,
            'amount' => $eventData->amount / 100,
            'stripe_customer' => $eventData->customer,
            'stripe_transaction' => $eventData->id,
            'description' => $description,
            'success' => '0',
            'error' => $eventData->failure_message ]);

        // email member about the failure
        if ($this->app['config']->get('billing.emails.failed_payment')) {
            $member->sendEmail(
                'payment-problem', [
                    'subject' => 'Declined charge for ' . $this->app['config']->get('site.title'),
                    'timestamp' => $eventData->created,
                    'payment_time' => date('F j, Y g:i a T', $eventData->created),
                    'amount' => number_format($eventData->amount / 100, 2),
                    'description' => $description,
                    'card_last4' => $eventData->card->last4,
                    'card_expires' => $eventData->card->exp_month . '/' . $eventData->card->exp_year,
                    'card_type' => $eventData->card->brand,
                    'error_message' => $eventData->failure_message ]);
        }

        return true;
    }

    /**
     * Handles succeeded charge events
     *
     * @param object $eventData
     * @param object $member
     *
     * @return boolean
     */
    public function chargeSucceeded($eventData, $member)
    {
        // add to billing history
        $description = $eventData->description;

        if (empty($eventData->description) && $member->hasProperty('plan'))
            $description = $member->plan;

        $history = new BillingHistory();
        $history->create([
            'uid' => $member->id(),
            'payment_time' => $eventData->created,
            'amount' => $eventData->amount / 100,
            'stripe_customer' => $eventData->customer,
            'stripe_transaction' => $eventData->id,
            'description' => $description,
            'success' => true ]);

        // email member with a receipt
        if ($this->app['config']->get('billing.emails.payment_receipt')) {
            $member->sendEmail(
                'payment-received', [
                    'subject' => 'Payment receipt on ' . $this->app['config']->get('site.title'),
                    'timestamp' => $eventData->created,
                    'payment_time' => date('F j, Y g:i a T', $eventData->created),
                    'amount' => number_format($eventData->amount / 100, 2),
                    'description' => $description,
                    'card_last4' => $eventData->card->last4,
                    'card_expires' => $eventData->card->exp_month . '/' . $eventData->card->exp_year,
                    'card_type' => $eventData->card->brand ]);
        }

        return true;
    }

    /**
     * Handles created/updated subscription events
     *
     * @param object $eventData
     * @param object $member
     *
     * @return boolean
     */
    public function updatedSubscription($eventData, $member)
    {
        // get the customer information
        $customer = Stripe_Customer::retrieve($eventData->customer, $this->apiKey);

        // we only use the 1st subscription
        $subscription = $customer->subscriptions->data[0];

        $update = [
            'past_due' => $subscription->status == 'past_due',
            'trial_ends' => $subscription->trial_end ];

        if (in_array($subscription->status, ['trialing','active','past_due']))
            $update['renews_next'] = $subscription->current_period_end;

        $member->set($update);

        if ($subscription->status == 'unpaid' && $this->app['config']->get('billing.emails.trial_ended')) {
            $member->sendEmail(
                'trial-ended', [
                    'subject' => 'Your ' . $this->app['config']->get('site.title') . ' trial has ended' ]);
        }

        return true;
    }

    /**
     * Handles canceled subscription events
     *
     * @param object $eventData
     * @param object $member
     *
     * @return boolean
     */
    public function canceledSubscription($eventData, $member)
    {
        $member->set('canceled', true);

        if ($this->app['config']->get('billing.emails.subscription_canceled')) {
            $member->sendEmail(
                'subscription-canceled', [
                    'subject' => 'Your subscription to ' . $this->app['config']->get('site.title') . ' has been canceled' ]);
        }

        return true;
    }

    /**
     * Handles trial ends soon events
     *
     * @param object $eventData
     * @param object $member
     *
     * @return boolean
     */
    public function trialWillEnd($eventData, $member)
    {
        if ($this->app['config']->get('billing.emails.trial_will_end')) {
            $member->sendEmail(
                'trial-will-end', [
                    'subject' => 'Your trial ends soon on ' . $this->app['config']->get('site.title') ]);
        }

        return true;
    }
}
