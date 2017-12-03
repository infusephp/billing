<?php

use Infuse\Billing\Libs\BillingSubscription;
use Infuse\Test;
use Stripe\Error\Api as StripeError;
use Infuse\Billing\Exception\BillingException;
use Mockery\Adapter\Phpunit\MockeryTestCase;

class BillingSubscriptionTest extends MockeryTestCase
{
    public function testPlan()
    {
        $member = new TestBillingModel();
        $sub = new BillingSubscription($member, 'test', Test::$app);

        $this->assertEquals('test', $sub->plan());
    }

    public function testStatusNotSubscribed()
    {
        $member = new TestBillingModel();
        $sub = new BillingSubscription($member, false, Test::$app);

        $this->assertEquals('not_subscribed', $sub->status());

        $sub = new BillingSubscription($member, 'test', Test::$app);
        $this->assertEquals('not_subscribed', $sub->status());
    }

    public function testCanceled()
    {
        $member = new TestBillingModel();
        $member->canceled = true;
        $sub = new BillingSubscription($member, 'test', Test::$app);

        $this->assertEquals('canceled', $sub->status());
        $this->assertTrue($sub->canceled());
    }

    public function testStatusActive()
    {
        $member = new TestBillingModel();
        $member->plan = 'test';
        $member->renews_next = time() + 1000;
        $sub = new BillingSubscription($member, 'test', Test::$app);

        $this->assertEquals('active', $sub->status());
        $this->assertTrue($sub->active());
    }

    public function testStatusActiveNotCharged()
    {
        $member = new TestBillingModel();
        $member->plan = 'test';
        $member->not_charged = true;
        $sub = new BillingSubscription($member, 'test', Test::$app);

        $this->assertEquals('active', $sub->status());
        $this->assertTrue($sub->active());
    }

    public function testStatusActiveNotRenewedYet()
    {
        $member = new TestBillingModel();
        $member->plan = 'test';
        $member->renews_next = time() - 1000;
        $member->past_due = false;
        $sub = new BillingSubscription($member, 'test', Test::$app);

        $this->assertEquals('active', $sub->status());
        $this->assertTrue($sub->active());
    }

    public function testStatusTrialing()
    {
        $member = new TestBillingModel();
        $member->plan = 'test';
        $member->renews_next = 0;
        $member->trial_ends = time() + 900;
        $sub = new BillingSubscription($member, 'test', Test::$app);

        $this->assertEquals('trialing', $sub->status());
        $this->assertTrue($sub->trialing());
    }

    public function testPastDue()
    {
        $member = new TestBillingModel();
        $member->plan = 'test';
        $member->past_due = true;
        $sub = new BillingSubscription($member, 'test', Test::$app);

        $this->assertEquals('past_due', $sub->status());
        $this->assertTrue($sub->active());
    }

    public function testUnpaid()
    {
        $member = new TestBillingModel();
        $member->plan = 'test';
        $sub = new BillingSubscription($member, 'test', Test::$app);

        $this->assertEquals('unpaid', $sub->status());
    }

    public function testCannotCreate()
    {
        $member = new TestBillingModel();
        $member->plan = 'test';

        $sub = new BillingSubscription($member, 'test', Test::$app);
        $this->assertFalse($sub->create());

        $sub = new BillingSubscription($member, '', Test::$app);
        $this->assertFalse($sub->create());
    }

    public function testCreate()
    {
        $resultSub = new stdClass();
        $resultSub->status = 'active';
        $resultSub->current_period_end = 100;
        $resultSub->trial_end = 100;

        $customer = Mockery::mock('StripeCustomer');
        $customer->shouldReceive('updateSubscription')
                 ->withArgs([[
                    'plan' => 'test',
                    'coupon' => 'favorite-customer', ]])
                 ->andReturn($resultSub)
                 ->once();

        $member = Mockery::mock('TestBillingModel[stripeCustomer,save]');
        $member->shouldReceive('stripeCustomer')
                ->andReturn($customer)
                ->once();
        $member->shouldReceive('save')->once();

        $subscription = new BillingSubscription($member, 'test', Test::$app);

        $this->assertTrue($subscription->create(false, false, ['coupon' => 'favorite-customer']));

        $this->assertEquals('test', $member->plan);
        $this->assertFalse($member->past_due);
        $this->assertEquals(100, $member->renews_next);
        $this->assertFalse($member->canceled);
        $this->assertNull($member->canceled_at);
        $this->assertEquals(0, $member->trial_ends);
    }

    public function testCreateWithToken()
    {
        $resultSub = new stdClass();
        $resultSub->status = 'trialing';
        $resultSub->current_period_end = 100;
        $resultSub->trial_end = 100;

        $customer = Mockery::mock('StripeCustomer');
        $customer->shouldReceive('updateSubscription')
                 ->withArgs([[
                    'plan' => 'test',
                    'source' => 'tok_test', ]])
                 ->andReturn($resultSub)
                 ->once();

        $member = Mockery::mock('TestBillingModel[stripeCustomer,save]');
        $member->shouldReceive('stripeCustomer')
                ->andReturn($customer)
                ->once();
        $member->shouldReceive('save')->once();

        $subscription = new BillingSubscription($member, 'test', Test::$app);

        $this->assertTrue($subscription->create('tok_test'));

        $this->assertEquals('test', $member->plan);
        $this->assertFalse($member->past_due);
        $this->assertEquals(100, $member->renews_next);
        $this->assertFalse($member->canceled);
        $this->assertNull($member->canceled_at);
    }

    public function testCreateNoTrial()
    {
        $resultSub = new stdClass();
        $resultSub->status = 'active';
        $resultSub->current_period_end = 100;
        $resultSub->trial_end = 0;

        $customer = Mockery::mock('StripeCustomer');
        $customer->shouldReceive('updateSubscription')
                 ->withArgs([[
                    'plan' => 'test',
                    'trial_end' => 'now', ]])
                 ->andReturn($resultSub)
                 ->once();

        $member = Mockery::mock('TestBillingModel[stripeCustomer,save]');
        $member->shouldReceive('stripeCustomer')
                ->andReturn($customer)
                ->once();
        $member->shouldReceive('save')->once();

        $subscription = new BillingSubscription($member, 'test', Test::$app);

        $this->assertTrue($subscription->create(false, true));

        $this->assertEquals('test', $member->plan);
        $this->assertFalse($member->past_due);
        $this->assertEquals(100, $member->renews_next);
        $this->assertFalse($member->canceled);
        $this->assertNull($member->canceled_at);
        $this->assertEquals(0, $member->trial_ends);
    }

    public function testCreateFail()
    {
        $this->expectException(BillingException::class);

        $e = new StripeError('error');
        $customer = Mockery::mock('StripeCustomer');
        $customer->shouldReceive('updateSubscription')
            ->withArgs([['plan' => 'test', 'source' => 'tok_test']])
            ->andThrow($e);

        $member = Mockery::mock('TestBillingModel[stripeCustomer]');
        $member->shouldReceive('stripeCustomer')
                ->andReturn($customer)
                ->once();

        $subscription = new BillingSubscription($member, 'test', Test::$app);

        $subscription->create('tok_test');
    }

    public function testCannotChange()
    {
        $member = new TestBillingModel();
        $this->assertFalse($member->subscription()->change('test'));
        $this->assertFalse($member->subscription()->change(''));

        $member->not_charged = true;
        $member->plan = 'test2';
        $this->assertFalse($member->subscription()->change('test'));
    }

    public function testChange()
    {
        $trialEnds = time() + 1000;

        $resultSub = new stdClass();
        $resultSub->status = 'active';
        $resultSub->current_period_end = 100;
        $resultSub->trial_end = 100;
        $resultSub->plan = new stdClass();
        $resultSub->plan->id = 'blah';

        $customer = Mockery::mock('StripeCustomer');
        $customer->shouldReceive('updateSubscription')
            ->withArgs([[
                'plan' => 'blah',
                'prorate' => false,
                'trial_end' => $trialEnds, ]])
            ->andReturn($resultSub)
            ->once();

        $member = Mockery::mock('TestBillingModel[stripeCustomer,save]');
        $member->shouldReceive('stripeCustomer')
                ->andReturn($customer)
                ->once();
        $member->shouldReceive('save')->once();

        $member->canceled = false;
        $member->not_charged = false;
        $member->trial_ends = $trialEnds;
        $member->renews_next = 0;
        $member->plan = 'test';

        $subscription = new BillingSubscription($member, 'test', Test::$app);

        $this->assertTrue($subscription->change('blah', false, ['prorate' => false]));
        $this->assertEquals('blah', $subscription->plan());

        $this->assertEquals('blah', $member->plan);
        $this->assertFalse($member->past_due);
        $this->assertEquals(100, $member->renews_next);
        $this->assertFalse($member->canceled);
        $this->assertNull($member->canceled_at);
        $this->assertEquals(0, $member->trial_ends);
    }

    public function testChangeFail()
    {
        $this->expectException(BillingException::class);

        $e = new StripeError('error');
        $customer = Mockery::mock('StripeCustomer');
        $customer->shouldReceive('updateSubscription')
                  ->withArgs([[
                    'plan' => 'blah',
                    'prorate' => true,
                    'trial_end' => 'now', ]])
                  ->andThrow($e);

        $member = Mockery::mock('TestBillingModel[stripeCustomer]');
        $member->shouldReceive('stripeCustomer')
                ->andReturn($customer)
                ->once();

        $member->canceled = false;
        $member->not_charged = false;
        $member->renews_next = time() + 1000;
        $member->trial_ends = null;
        $member->plan = 'test';

        $subscription = new BillingSubscription($member, 'test', Test::$app);

        $subscription->change('blah', true);
    }

    public function testCancelNoStripeCustomer()
    {
        $resultSub = new stdClass();
        $resultSub->status = 'canceled';

        $member = Mockery::mock('TestBillingModel[save]');
        $member->shouldReceive('save')->once();

        $member->canceled = false;
        $member->trial_ends = 0;
        $member->renews_next = strtotime('+1 month');
        $member->not_charged = false;
        $member->plan = 'test';
        $member->stripe_customer = null;

        $subscription = new BillingSubscription($member, 'test', Test::$app);

        $this->assertTrue($subscription->cancel());

        $this->assertTrue($member->canceled);

        $expected = [
            'subscription-canceled',
            [
                'subject' => 'Your subscription to Test Site has been canceled',
                'tags' => ['billing', 'subscription-canceled'],
            ],
        ];
        $this->assertEquals($expected, $member->lastEmail);
    }

    public function testCancelNotCharged()
    {
        $resultSub = new stdClass();
        $resultSub->status = 'canceled';

        $member = Mockery::mock('TestBillingModel[save]');
        $member->shouldReceive('save')->once();

        $member->canceled = false;
        $member->trial_ends = 0;
        $member->not_charged = true;
        $member->plan = 'test';
        $member->stripe_customer = 'cust_test';

        $subscription = new BillingSubscription($member, 'test', Test::$app);

        $this->assertTrue($subscription->cancel());

        $this->assertTrue($member->canceled);

        $expected = [
            'subscription-canceled',
            [
                'subject' => 'Your subscription to Test Site has been canceled',
                'tags' => ['billing', 'subscription-canceled'],
            ],
        ];
        $this->assertEquals($expected, $member->lastEmail);
    }

    public function testCancel()
    {
        $resultSub = new stdClass();
        $resultSub->status = 'canceled';

        $customer = Mockery::mock('StripeCustomer');
        $customer->shouldReceive('cancelSubscription')
                 ->andReturn($resultSub)
                 ->once();

        $member = Mockery::mock('TestBillingModel[stripeCustomer,save]');
        $member->shouldReceive('stripeCustomer')
                ->andReturn($customer)
                ->once();
        $member->shouldReceive('save')->once();

        $member->canceled = false;
        $member->trial_ends = 0;
        $member->renews_next = strtotime('+1 month');
        $member->not_charged = false;
        $member->plan = 'test';
        $member->stripe_customer = 'cust_test';

        $subscription = new BillingSubscription($member, 'test', Test::$app);

        $this->assertTrue($subscription->cancel());

        $this->assertTrue($member->canceled);
    }

    public function testCancelAtPeriodEnd()
    {
        $resultSub = new stdClass();
        $resultSub->status = 'active';
        $resultSub->cancel_at_period_end = true;
        $resultSub->canceled_at = time();

        $customer = Mockery::mock('StripeCustomer');
        $customer->shouldReceive('cancelSubscription')
                 ->withArgs([['at_period_end' => true]])
                 ->andReturn($resultSub)
                 ->once();

        $member = Mockery::mock('TestBillingModel[stripeCustomer,save]');
        $member->shouldReceive('stripeCustomer')
                ->andReturn($customer)
                ->once();
        $member->shouldReceive('save')->once();

        $member->canceled = false;
        $member->trial_ends = 0;
        $member->renews_next = strtotime('+1 month');
        $member->not_charged = false;
        $member->plan = 'test';
        $member->stripe_customer = 'cust_test';

        $subscription = new BillingSubscription($member, 'test', Test::$app);

        $this->assertTrue($subscription->cancel(true));

        $this->assertFalse($member->canceled);
        $this->assertEquals($resultSub->canceled_at, $member->canceled_at);
    }

    public function testCancelFail()
    {
        $this->expectException(BillingException::class);

        $e = new StripeError('error');
        $customer = Mockery::mock('StripeCustomer');
        $customer->shouldReceive('cancelSubscription')
                 ->andThrow($e);

        $member = Mockery::mock('TestBillingModel[stripeCustomer]');
        $member->shouldReceive('stripeCustomer')
                ->andReturn($customer)
                ->once();

        $member->canceled = false;
        $member->trial_ends = 0;
        $member->renews_next = strtotime('+1 month');
        $member->not_charged = false;
        $member->plan = 'test';
        $member->stripe_customer = 'cust_test';

        $subscription = new BillingSubscription($member, 'test', Test::$app);

        $subscription->cancel();
    }
}
