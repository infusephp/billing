<?php

namespace app\billing\libs;

use Stripe\Customer;
use app\billing\models\BillingHistory;

class StripeWebhook extends WebhookController
{
    /**
     * Handles charge.failed.
     *
     * @param object $eventData
     * @param object $member
     *
     * @return boolean
     */
    public function handleChargeFailed($eventData, $member)
    {
        // currently only handle card charges
        if ($eventData->source->object != 'card') {
            return true;
        }

        // add to billing history
        $description = $eventData->description;

        if (empty($eventData->description) && $member->hasProperty('plan')) {
            $description = $member->plan;
        }

        $history = new BillingHistory();
        $history->create([
            'uid' => $member->id(),
            'payment_time' => $eventData->created,
            'amount' => $eventData->amount / 100,
            'stripe_customer' => $eventData->customer,
            'stripe_transaction' => $eventData->id,
            'description' => $description,
            'success' => '0',
            'error' => $eventData->failure_message, ]);

        // email member about the failure
        if ($this->app['config']->get('billing.emails.failed_payment')) {
            $member->sendEmail(
                'payment-problem', [
                    'subject' => 'Declined charge for '.$this->app['config']->get('site.title'),
                    'timestamp' => $eventData->created,
                    'payment_time' => date('F j, Y g:i a T', $eventData->created),
                    'amount' => number_format($eventData->amount / 100, 2),
                    'description' => $description,
                    'card_last4' => $eventData->source->last4,
                    'card_expires' => $eventData->source->exp_month.'/'.$eventData->source->exp_year,
                    'card_type' => $eventData->source->brand,
                    'error_message' => $eventData->failure_message,
                    'tags' => ['billing', 'charge-failed'], ]);
        }

        return true;
    }

    /**
     * Handles charge.succeeded.
     *
     * @param object $eventData
     * @param object $member
     *
     * @return boolean
     */
    public function handleChargeSucceeded($eventData, $member)
    {
        // currently only handle card charges
        if ($eventData->source->object != 'card') {
            return true;
        }

        // add to billing history
        $description = $eventData->description;

        if (empty($eventData->description) && $member->hasProperty('plan')) {
            $description = $member->plan;
        }

        $history = new BillingHistory();
        $history->create([
            'uid' => $member->id(),
            'payment_time' => $eventData->created,
            'amount' => $eventData->amount / 100,
            'stripe_customer' => $eventData->customer,
            'stripe_transaction' => $eventData->id,
            'description' => $description,
            'success' => true, ]);

        // email member with a receipt
        if ($this->app['config']->get('billing.emails.payment_receipt')) {
            $member->sendEmail(
                'payment-received', [
                    'subject' => 'Payment receipt on '.$this->app['config']->get('site.title'),
                    'timestamp' => $eventData->created,
                    'payment_time' => date('F j, Y g:i a T', $eventData->created),
                    'amount' => number_format($eventData->amount / 100, 2),
                    'description' => $description,
                    'card_last4' => $eventData->source->last4,
                    'card_expires' => $eventData->source->exp_month.'/'.$eventData->source->exp_year,
                    'card_type' => $eventData->source->brand,
                    'tags' => ['billing', 'payment-received'], ]);
        }

        return true;
    }

    /**
     * Handles customer.subscription.created.
     *
     * @param object $eventData
     * @param object $member
     *
     * @return boolean
     */
    public function handleCustomerSubscriptionCreated($eventData, $member)
    {
        return $this->handleCustomerSubscriptionUpdated($eventData, $member);
    }

    /**
     * Handles invoice.payment_succeeded.
     *
     * @param object $eventData
     * @param object $member
     *
     * @return boolean
     */
    public function handleInvoicePaymentSucceeded($eventData, $member)
    {
        return $this->handleCustomerSubscriptionUpdated($eventData, $member);
    }

    /**
     * Handles customer.subscription.updated.
     *
     * @param object $eventData
     * @param object $member
     *
     * @return boolean
     */
    public function handleCustomerSubscriptionUpdated($eventData, $member)
    {
        // get the customer information
        $customer = Customer::retrieve($eventData->customer, $this->apiKey);

        // we only use the 1st subscription
        $subscription = $customer->subscriptions->data[0];

        $update = [
            'past_due' => $subscription->status == 'past_due',
            'trial_ends' => $subscription->trial_end, ];

        if (in_array($subscription->status, ['trialing', 'active', 'past_due'])) {
            $update['renews_next'] = $subscription->current_period_end;
        }

        $member->set($update);

        // TODO need to move this into cron job
        if ($subscription->status == 'unpaid' && $this->app['config']->get('billing.emails.trial_ended')) {
            $member->sendEmail(
                'trial-ended', [
                    'subject' => 'Your '.$this->app['config']->get('site.title').' trial has ended',
                    'tags' => ['billing', 'trial-ended'], ]);
        }

        return true;
    }

    /**
     * Handles customer.subscription.deleted.
     *
     * @param object $eventData
     * @param object $member
     *
     * @return boolean
     */
    public function handleCustomerSubscriptionDeleted($eventData, $member)
    {
        $member->set('canceled', true);

        if ($this->app['config']->get('billing.emails.subscription_canceled')) {
            $member->sendEmail(
                'subscription-canceled', [
                    'subject' => 'Your subscription to '.$this->app['config']->get('site.title').' has been canceled',
                    'tags' => ['billing', 'subscription-canceled'], ]);
        }

        return true;
    }

    /**
     * Handles customer.subscription.trial_will_end.
     *
     * @param object $eventData
     * @param object $member
     *
     * @return boolean
     */
    public function handleCustomerSubscriptionTrialWillEnd($eventData, $member)
    {
        // TODO need to move this into cron job
        if ($this->app['config']->get('billing.emails.trial_will_end')) {
            // do not send the notice unless the trial has more than 1 day left
            if ($eventData->trial_end - time() >= 86400) {
                $member->sendEmail(
                    'trial-will-end', [
                        'subject' => 'Your trial ends soon on '.$this->app['config']->get('site.title'),
                        'tags' => ['billing', 'trial-will-end'], ]);
            }
        }

        return true;
    }
}
