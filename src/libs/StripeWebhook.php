<?php

namespace app\billing\libs;

use Stripe_Customer;
use Stripe_Event;

use App;
use app\billing\models\BillingHistory;

class StripeWebhook
{
    private $event;
    private $app;

    public function __construct(array $event, App $app)
    {
        $this->event = $event;
        $this->app = $app;
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
        if( !isset( $this->event[ 'id' ] ) )

            return 'invalid event';

        // check that the livemode matches our development state
        if( !($this->event[ 'livemode' ] && $this->app[ 'config' ]->get( 'site.production-level' ) || !$this->event[ 'livemode' ] && !$this->app[ 'config' ]->get( 'site.production-level' ) ) )

            return 'livemode mismatch';

        if( isset( $this->event[ 'user_id' ] ) )

            return 'stripe connect event';

        $apiKey = $this->app[ 'config' ]->get( 'stripe.secret' );

        try {
            // retreive the event, unless it is a deauth event
            // since those cannot be retrieved
            $validatedEvent = ($this->event[ 'type' ] == 'account.application.deauthorized') ?
                (object) $this->event :
                Stripe_Event::retrieve( $this->event[ 'id' ], $apiKey );

            return $this->webhook($validatedEvent, $apiKey);
        } catch ( \Exception $e ) {
            return $e->getMessage();
        }
    }

    private function webhook($event, $apiKey)
    {
        if( !in_array( $event->type, [
            'charge.failed',
            'charge.succeeded',
            'customer.subscription.updated',
            'customer.subscription.deleted',
            'customer.subscription.created',
            'invoice.payment_succeeded' ] ) )
        {
            return 'event not supported';
        }

        // get the data attached to the event
        $eventData = $event->data->object;

        // find out which user this event is for by cross-referencing the customer id
        $modelClass = $this->app[ 'config' ]->get( 'billing.model' );

        $member = $modelClass::findOne( [
            'where' => [
                'stripe_customer' => $eventData->customer ] ] );

        if( !$member )

            return 'customer not found';

        if ( in_array( $event->type, [ 'charge.failed', 'charge.succeeded' ] ) ) {
            $description = ($member->hasProperty('plan') && empty($eventData->description)) ?
                $member->plan : $eventData->description;

            // prepare to record the charge
            $historyData = [
                'payment_time' => $eventData->created,
                'amount' => $eventData->amount / 100,
                'stripe_customer' => $eventData->customer,
                'description' => $description,
                'stripe_transaction' => $eventData->id,
                'uid' => $member->id() ];

            $emailTemplate = false;
            $subject = '';

            if ($event->type == 'charge.failed') {
                 // add to billing history
                $historyData[ 'success' ] = 0;
                $historyData[ 'error' ] = $eventData->failure_message;
                $history = new BillingHistory();
                $history->create( $historyData );

                 // e-mail user(s) about the failure
                if ($this->app['config']->get('billing.sendFailedPaymentNotices')) {
                    $emailTemplate = 'payment-problem';
                    $subject = 'Declined payment for ' . $this->app['config']->get( 'site.title' );
                }
            } elseif ($event->type == 'charge.succeeded') {
                $historyData[ 'success' ] = 1;

                // e-mail user(s) with a receipt
                if ($this->app['config']->get('billing.sendPaymentReceipts')) {
                    $emailTemplate = 'payment-received';
                    $subject = 'Received payment for ' . $this->app['config']->get( 'site.title' );
                }
            }

            // add to billing history
            $history = new BillingHistory();
            $history->create( $historyData );

            if ($emailTemplate) {
                $member->sendEmail(
                    $emailTemplate,
                    [
                        'subject' => $subject,
                        'timestamp' => $eventData->created,
                        'payment_time' => date( 'F j, Y g:i a T', $eventData->created ),
                        'amount' => number_format( $eventData->amount / 100, 2 ),
                        'description' => $description,
                        'card_last4' => $eventData->card->last4,
                        'card_expires' => $eventData->card->exp_month . '/' . $eventData->card->exp_year,
                        'card_type' => $eventData->card->brand,
                        'error_message' => $eventData->failure_message ] );
            }
        } elseif( in_array( $event->type, [
            'invoice.payment_succeeded',
            'customer.subscription.updated',
            'customer.subscription.deleted',
            'customer.subscription.created' ] ) )
        {
            // get the customer information
            $customer = Stripe_Customer::retrieve( $eventData->customer, $apiKey );

            $memberUpdateData = [];

            if( $event->type == 'customer.subscription.deleted' )
                $memberUpdateData[ 'canceled' ] = true;

            if ( is_array( $customer->subscriptions->data ) ) {
                // we only use 1 subscription
                if ( count( $customer->subscriptions->data ) > 0 ) {
                    $subscription = $customer->subscriptions->data[ 0 ];

                    if ( is_object( $subscription ) ) {
                        $memberUpdateData = [
                            'past_due' => in_array( $subscription->status, [ 'past_due', 'unpaid', 'canceled' ] ),
                            'renews_next' => $subscription->current_period_end,
                            'trial_ends' => (int) $subscription->trial_end ];

                        if( $subscription->status == 'canceled' )
                            $memberUpdateData[ 'canceled' ] = true;
                    }
                }
                // member has canceled
                else
                    $memberUpdateData[ 'canceled' ] = true;
            }

             // update subscription information
             $member->set( $memberUpdateData );
        }

        return 'ok';
    }
}
