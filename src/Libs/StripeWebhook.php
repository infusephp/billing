<?php

namespace Infuse\Billing\Libs;

use Infuse\Billing\Models\BillingHistory;
use Stripe\Customer;

class StripeWebhook extends WebhookController
{
    /**
     * Handles charge.failed.
     *
     * @param object $eventData
     * @param object $member
     *
     * @return bool
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
            'user_id' => $member->id(),
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
                    'subject' => 'Declined charge for '.$this->app['config']->get('app.title'),
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
     * @return bool
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
            'user_id' => $member->id(),
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
                    'subject' => 'Payment receipt on '.$this->app['config']->get('app.title'),
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
     * @return bool
     */
    public function handleCustomerSubscriptionCreated($eventData, $member)
    {
        return $this->handleCustomerSubscriptionUpdated($eventData, $member);
    }

    /**
     * Handles customer.subscription.updated.
     *
     * @param object $eventData
     * @param object $member
     *
     * @return bool
     */
    public function handleCustomerSubscriptionUpdated($eventData, $member)
    {
        $member->past_due = $eventData->status == 'past_due';
        $member->plan = $eventData->plan->id;

        if (in_array($eventData->status, ['trialing', 'active', 'past_due'])) {
            $member->renews_next = $eventData->current_period_end;
            $member->canceled = false;
            $member->canceled_at = null;
        }

        if (!in_array($eventData->status, ['trialing', 'unpaid'])) {
            $member->trial_ends = 0;
        }

        $member->save();

        return true;
    }

    /**
     * Handles customer.subscription.deleted.
     *
     * @param object $eventData
     * @param object $member
     *
     * @return bool
     */
    public function handleCustomerSubscriptionDeleted($eventData, $member)
    {
        $member->canceled = true;
        $member->save();

        if ($this->app['config']->get('billing.emails.subscription_canceled')) {
            $member->sendEmail(
                'subscription-canceled', [
                    'subject' => 'Your subscription to '.$this->app['config']->get('app.title').' has been canceled',
                    'tags' => ['billing', 'subscription-canceled'], ]);
        }

        return true;
    }
}
