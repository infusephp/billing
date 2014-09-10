<?php

namespace app\billing\libs;

use app\billing\models\BillableModel;
use App;

class BillingSubscription
{
    private $model;
    private $plan;
    private $app;

    public function __construct(BillableModel $model, $plan, App $app)
    {
        $this->model = $model;
        $this->plan = $plan;
        $this->app = $app;
    }

    /**
     * Returns the plan associated with this object
     *
     * @return string
     */
    public function plan()
    {
        return $this->plan;
    }

    /**
     * Creates a new Stripe subscription. If a token is provided it will
     * become the new default card for the customer
     *
     * @param string $token optional Stripe token to use for the plan
     *
     * @return boolean
     */
    public function create($token = false)
    {
        // cannot create a subscription if there is already an
        // active existing plan; must use change() instead
        if (empty($this->plan) || $this->active())
            return false;

        $customer = $this->model->stripeCustomer();

        if (!$customer)
            return false;

        $apiKey = $this->app[ 'config' ]->get('stripe.secret');

        $params = [
            'plan' => $this->plan ];

        if ($token)
            $params['card'] = $token;

        try {
            $subscription = $customer->updateSubscription($params);

            // update the user's billing state
            if (in_array($subscription->status, ['active', 'trialing'])) {
                $this->model->grantAllPermissions();
                $this->model->set([
                    'plan' => $this->plan,
                    'past_due' => false,
                    'renews_next' => $subscription->current_period_end,
                    'trial_ends' => $subscription->trial_end,
                    'canceled' => false
                ]);
                $this->model->enforcePermissions();

                return true;
            }
        } catch (\Exception $e) {
            $this->app[ 'errors' ]->push( [
                'error' => 'stripe_error',
                'message' => $e->getMessage() ] );
            $this->app[ 'logger' ]->debug($e);
        }

        return false;
    }

    /**
     * Changes the subscription plan
     *
     * @param string $plan
     *
     * @return boolean result
     */
    public function change($plan)
    {
        if (empty($plan) || !$this->active())
            return false;

        $customer = $this->model->stripeCustomer();

        if (!$customer)
            return false;

        $apiKey = $this->app[ 'config' ]->get('stripe.secret');

        try {
            $subscription = $customer->updateSubscription([
                'plan' => $plan,
                'prorate' => true
            ]);

            // update the user's billing state
            if (in_array($subscription->status, ['active', 'trialing'])) {
                $this->model->grantAllPermissions();
                $this->model->set([
                    'plan' => $this->plan,
                    'past_due' => false,
                    'renews_next' => $subscription->current_period_end,
                    'trial_ends' => $subscription->trial_end,
                    'canceled' => false
                ]);
                $this->model->enforcePermissions();

                $this->plan = $plan;

                return true;
            }
        } catch (\Exception $e) {
            $this->app[ 'errors' ]->push( [ 'error' => 'stripe_error', 'message' => $e->getMessage() ] );
            $this->app[ 'logger' ]->debug($e);
        }

        return false;
    }

    /**
     * Cancels the subscription
     *
     * @return boolean
     */
    public function cancel()
    {
        if (!$this->active())
            return false;

        $apiKey = $this->app[ 'config' ]->get('stripe.secret');

        $customer = $this->model->stripeCustomer();

        if (!$customer)
            return false;

        try {
            $subscription = $customer->cancelSubscription();

            if ($subscription->status == 'canceled') {
                $this->model->grantAllPermissions();
                $this->model->set('canceled', true);
                $this->model->enforcePermissions();

                return true;
            }
        } catch (\Exception $e) {
            $this->app[ 'logger' ]->debug($e);
            $this->app[ 'errors' ]->push( [ 'error' => 'stripe_error', 'message' => $e->getMessage() ] );
        }

        return false;
    }

    /**
	 * Gets the status of the subscription
	 *
	 * @return string one of not_subscribed, trialing, active, past_due, canceled, or unpaid
	 */
    public function status()
    {
        if ($this->model->canceled)
            return 'canceled';

        // subscription plan must match model's bililng plan for it to
        // have a non-canceled status
        if (!$this->plan || $this->model->plan != $this->plan)
            return 'not_subscribed';

        // flag to skip charging this model, unless canceled the subscription
        // is always active
        if ($this->model->not_charged)
            return 'active';

        // check if the subscription is active or trialing
        if ($this->model->renews_next > time()) {
            if ($this->model->trial_ends > time())
                return 'trialing';
            else
                return 'active';
        }

        // the subscription is past due when its status has been changed
        // to past_due on stripe
        if ($this->model->past_due)
            return 'past_due';

        return 'unpaid';
    }

    /**
     * Checks if the model's subscription is active
     *
     * @return boolean
     */
    public function active()
    {
        return in_array( $this->status(),
            [ 'active', 'trialing', 'past_due' ] );
    }

    /**
     * Checks if the model's subscription is canceled
     *
     * @return boolean
     */
    public function canceled()
    {
        return $this->status() == 'canceled';
    }

    /**
     * Checks if the model's subscription is in a trial period
     *
     * @return boolean
     */
    public function trialing()
    {
        return $this->status() == 'trialing';
    }
}
