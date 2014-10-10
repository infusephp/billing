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

class StripeWebhook
{
    private $event;
    private $app;
    private $apiKey;

    private static $eventHandlers = [
        // charges
        'charge.failed' => 'chargeFailedHandler',
        'charge.succeeded' => 'chargeSucceededHandler',
        // subscription updated
        'customer.subscription.created' => 'updatedSubscriptionHandler',
        'invoice.payment_succeeded' => 'updatedSubscriptionHandler',
        'customer.subscription.updated' => 'updatedSubscriptionHandler',
        'customer.subscription.deleted' => 'updatedSubscriptionHandler',
        // trials
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
            $eventData = $event->data->object;

            // find out which user this event is for by cross-referencing the customer id
            $modelClass = $this->app['config']->get('billing.model');

            $member = $modelClass::findOne([
                'where' => [
                    'stripe_customer' => $eventData->customer ]]);

            if (!$member)
                return ERROR_CUSTOMER_NOT_FOUND;

            $handler = self::$eventHandlers[$type];
            if ($this->$handler($eventData, $member))
                return 'ok';
        } catch ( \Exception $e ) {
            return $e->getMessage();
        }
    }

    /**
     * Handles failed charge events
     *
     * @param object $event
     * @param object $member
     *
     * @return boolean
     */
    public function chargeFailedHandler(\stdClass $event, $member)
    {
        // add to billing history
        $description = $event->description;

        if (empty($event->description) && $member->hasProperty('plan'))
            $description = $member->plan;

        $history = new BillingHistory();
        $history->create([
            'uid' => $member->id(),
            'payment_time' => $event->created,
            'amount' => $event->amount / 100,
            'stripe_customer' => $event->customer,
            'stripe_transaction' => $event->id,
            'description' => $description,
            'success' => '0',
            'error' => $event->failure_message ]);

        // email member about the failure
        if ($this->app['config']->get('billing.emails.failed_payment')) {
            $member->sendEmail(
                'payment-problem', [
                    'subject' => 'Declined charge for ' . $this->app['config']->get('site.title'),
                    'timestamp' => $event->created,
                    'payment_time' => date('F j, Y g:i a T', $event->created),
                    'amount' => number_format($event->amount / 100, 2),
                    'description' => $description,
                    'card_last4' => $event->card->last4,
                    'card_expires' => $event->card->exp_month . '/' . $event->card->exp_year,
                    'card_type' => $event->card->brand,
                    'error_message' => $event->failure_message ]);
        }

        return true;
    }

    /**
     * Handles succeeded charge events
     *
     * @param object $event
     * @param object $member
     *
     * @return boolean
     */
    public function chargeSucceededHandler(\stdClass $event, $member)
    {
        // add to billing history
        $description = $event->description;

        if (empty($event->description) && $member->hasProperty('plan'))
            $description = $member->plan;

        $history = new BillingHistory();
        $history->create([
            'uid' => $member->id(),
            'payment_time' => $event->created,
            'amount' => $event->amount / 100,
            'stripe_customer' => $event->customer,
            'stripe_transaction' => $event->id,
            'description' => $description,
            'success' => true ]);

        // email member with a receipt
        if ($this->app['config']->get('billing.emails.payment_receipt')) {
            $member->sendEmail(
                'payment-received', [
                    'subject' => 'Payment receipt on ' . $this->app['config']->get('site.title'),
                    'timestamp' => $event->created,
                    'payment_time' => date('F j, Y g:i a T', $event->created),
                    'amount' => number_format($event->amount / 100, 2),
                    'description' => $description,
                    'card_last4' => $event->card->last4,
                    'card_expires' => $event->card->exp_month . '/' . $event->card->exp_year,
                    'card_type' => $event->card->brand ]);
        }

        return true;
    }

    /**
     * Handles subscription-related events
     *
     * @param object $event
     * @param object $member
     *
     * @return boolean
     */
    public function updatedSubscriptionHandler(\stdClass $event, $member)
    {
        // get the customer information
        $customer = Stripe_Customer::retrieve($event->customer, $this->apiKey);

        $update = [];

        if( $event->type == 'customer.subscription.deleted' )
            $update[ 'canceled' ] = true;

        if (is_array($customer->subscriptions->data)) {
            // we only use the 1st subscription
            if ( count( $customer->subscriptions->data ) > 0 ) {
                $subscription = $customer->subscriptions->data[ 0 ];

                if ( is_object( $subscription ) ) {
                    $update = [
                        'past_due' => $subscription->status == 'past_due',
                        'trial_ends' => $subscription->trial_end ];

                    if (in_array($subscription->status, ['trialing','active','past_due']))
                        $update['renews_next'] = $subscription->current_period_end;

                    if ($subscription->status == 'canceled') {
                        $update['canceled'] = true;
                        $update['canceled_at'] = $subscription->canceled_at;
                    }
                }
            }
            // member has canceled
            else
                $update['canceled'] = true;
        }

        // update subscription information on member
        $member->set($update);

        return true;
    }

    public function trialWillEnd(\stdClass $event, $member)
    {
        if ($this->app['config']->get('billing.emails.trial_will_end')) {
            $member->sendEmail(
                'trial-will-end', [
                    'subject' => 'Your trial ends soon on ' . $this->app['config']->get('site.title') ]);
        }

        return true;
    }
}
