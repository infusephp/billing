<?php

use app\billing\libs\BillingSubscription;

class BillingSubscriptionTest extends \PHPUnit_Framework_TestCase
{
    public static function setUpBeforeClass()
    {
        require_once 'TestBillingModel.php';
    }

    public function testProperties()
    {
        $props = [
            'plan',
            'stripe_customer',
            'renews_next',
            'trial_ends',
            'past_due',
            'canceled',
            'canceled_at',
            'not_charged', ];

        foreach ($props as $p) {
            $this->assertTrue(TestBillingModel::hasProperty($p));
        }
    }

    public function testPlan()
    {
        $testModel = new TestBillingModel();
        $sub = new BillingSubscription($testModel, 'test', Test::$app);

        $this->assertEquals('test', $sub->plan());
    }

    public function testStatusNotSubscribed()
    {
        $testModel = new TestBillingModel();
        $sub = new BillingSubscription($testModel, false, Test::$app);

        $this->assertEquals('not_subscribed', $sub->status());

        $sub = new BillingSubscription($testModel, 'test', Test::$app);
        $this->assertEquals('not_subscribed', $sub->status());
    }

    public function testCanceled()
    {
        $testModel = new TestBillingModel();
        $testModel->canceled = true;
        $sub = new BillingSubscription($testModel, 'test', Test::$app);

        $this->assertEquals('canceled', $sub->status());
        $this->assertTrue($sub->canceled());
    }

    public function testStatusActive()
    {
        $testModel = new TestBillingModel();
        $testModel->plan = 'test';
        $testModel->renews_next = time() + 1000;
        $sub = new BillingSubscription($testModel, 'test', Test::$app);

        $this->assertEquals('active', $sub->status());
        $this->assertTrue($sub->active());
    }

    public function testStatusActiveNotCharged()
    {
        $testModel = new TestBillingModel();
        $testModel->plan = 'test';
        $testModel->not_charged = true;
        $sub = new BillingSubscription($testModel, 'test', Test::$app);

        $this->assertEquals('active', $sub->status());
        $this->assertTrue($sub->active());
    }

    public function testStatusTrialing()
    {
        $testModel = new TestBillingModel();
        $testModel->plan = 'test';
        $testModel->renews_next = 0;
        $testModel->trial_ends = time() + 900;
        $sub = new BillingSubscription($testModel, 'test', Test::$app);

        $this->assertEquals('trialing', $sub->status());
        $this->assertTrue($sub->trialing());
    }

    public function testPastDue()
    {
        $testModel = new TestBillingModel();
        $testModel->plan = 'test';
        $testModel->past_due = true;
        $sub = new BillingSubscription($testModel, 'test', Test::$app);

        $this->assertEquals('past_due', $sub->status());
        $this->assertTrue($sub->active());
    }

    public function testUnpaid()
    {
        $testModel = new TestBillingModel();
        $testModel->plan = 'test';
        $sub = new BillingSubscription($testModel, 'test', Test::$app);

        $this->assertEquals('unpaid', $sub->status());
    }

    public function testCannotCreate()
    {
        $testModel = new TestBillingModel();
        $testModel->plan = 'test';

        $sub = new BillingSubscription($testModel, 'test', Test::$app);
        $this->assertFalse($sub->create());

        $sub = new BillingSubscription($testModel, '', Test::$app);
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

        $testModel = Mockery::mock('BillingModel', '\\app\\billing\\models\\BillableModel');
        $testModel->shouldReceive('get');
        $testModel->shouldReceive('stripeCustomer')
                  ->andReturn($customer)
                  ->once();
        $testModel->shouldReceive('grantAllPermissions')
                  ->andReturn($testModel);
        $testModel->shouldReceive('set')
                  ->withArgs([[
                        'plan' => 'test',
                        'past_due' => false,
                        'renews_next' => 100,
                        'canceled' => false,
                        'canceled_at' => null,
                        'trial_ends' => 0,
                    ]])
                  ->once();
        $testModel->shouldReceive('enforcePermissions');

        $subscription = new BillingSubscription($testModel, 'test', Test::$app);

        $this->assertTrue($subscription->create(false, false, ['coupon' => 'favorite-customer']));
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

        $testModel = Mockery::mock('BillingModel', '\\app\\billing\\models\\BillableModel');
        $testModel->shouldReceive('get');
        $testModel->shouldReceive('stripeCustomer')
                  ->andReturn($customer)
                  ->once();
        $testModel->shouldReceive('grantAllPermissions')
                  ->andReturn($testModel);
        $testModel->shouldReceive('set')
                  ->withArgs([[
                        'plan' => 'test',
                        'past_due' => false,
                        'renews_next' => 100,
                        'canceled' => false,
                        'canceled_at' => null,
                    ]])
                  ->once();
        $testModel->shouldReceive('enforcePermissions');

        $subscription = new BillingSubscription($testModel, 'test', Test::$app);

        $this->assertTrue($subscription->create('tok_test'));
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

        $testModel = Mockery::mock('BillingModel', '\\app\\billing\\models\\BillableModel');
        $testModel->shouldReceive('get');
        $testModel->shouldReceive('stripeCustomer')
                  ->andReturn($customer)
                  ->once();
        $testModel->shouldReceive('grantAllPermissions')
                  ->andReturn($testModel);
        $testModel->shouldReceive('set')
                  ->withArgs([[
                        'plan' => 'test',
                        'past_due' => false,
                        'renews_next' => 100,
                        'canceled' => false,
                        'canceled_at' => null,
                        'trial_ends' => 0,
                    ]])
                  ->once();
        $testModel->shouldReceive('enforcePermissions');

        $subscription = new BillingSubscription($testModel, 'test', Test::$app);

        $this->assertTrue($subscription->create(false, true));
    }

    public function testCreateFail()
    {
        $customer = Mockery::mock('StripeCustomer');
        $customer->shouldReceive('updateSubscription')->withArgs([['plan' => 'test', 'source' => 'tok_test']])
            ->andThrow(new Exception())->once();

        $testModel = Mockery::mock('BillingModel', '\\app\\billing\\models\\BillableModel');
        $testModel->shouldReceive('get');
        $testModel->shouldReceive('stripeCustomer')->andReturn($customer)->once();

        $subscription = new BillingSubscription($testModel, 'test', Test::$app);

        $this->assertFalse($subscription->create('tok_test'));
    }

    public function testCannotChange()
    {
        $testModel = new TestBillingModel();
        $this->assertFalse($testModel->subscription()->change('test'));
        $this->assertFalse($testModel->subscription()->change(''));

        $testModel->not_charged = true;
        $testModel->plan = 'test2';
        $this->assertFalse($testModel->subscription()->change('test'));
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

        $testModel = Mockery::mock('BillingModel', '\\app\\billing\\models\\BillableModel');
        $testModel->shouldReceive('get')
                  ->withArgs([['canceled']])
                  ->andReturn(false);
        $testModel->shouldReceive('get')
                  ->withArgs([['not_charged']])
                  ->andReturn(false);
        $testModel->shouldReceive('get')
                  ->withArgs([['trial_ends']])
                  ->andReturn($trialEnds);
        $testModel->shouldReceive('get')
                  ->withArgs([['renews_next']])
                  ->andReturn(0);
        $testModel->shouldReceive('get')
                  ->withArgs([['plan']])
                  ->andReturn('test');
        $testModel->shouldReceive('stripeCustomer')
                  ->andReturn($customer)
                  ->once();
        $testModel->shouldReceive('grantAllPermissions')
                  ->andReturn($testModel);
        $testModel->shouldReceive('set')
                  ->withArgs([[
                    'plan' => 'blah',
                    'past_due' => false,
                    'renews_next' => 100,
                    'canceled' => false,
                    'canceled_at' => null,
                    'trial_ends' => 0,
                  ]])
                  ->once();
        $testModel->shouldReceive('enforcePermissions');

        $subscription = new BillingSubscription($testModel, 'test', Test::$app);

        $this->assertTrue($subscription->change('blah', false, ['prorate' => false]));
        $this->assertEquals('blah', $subscription->plan());
    }

    public function testChangeFail()
    {
        $customer = Mockery::mock('StripeCustomer');
        $customer->shouldReceive('updateSubscription')->withArgs([[
            'plan' => 'blah',
            'prorate' => true,
            'trial_end' => 'now', ]])
            ->andThrow(new Exception())->once();

        $testModel = Mockery::mock('BillingModel', '\\app\\billing\\models\\BillableModel');
        $testModel->shouldReceive('get')
            ->withArgs([['canceled']])
            ->andReturn(false);
        $testModel->shouldReceive('get')
            ->withArgs([['not_charged']])
            ->andReturn(false);
        $testModel->shouldReceive('get')
            ->withArgs([['renews_next']])
            ->andReturn(time() + 1000);
        $testModel->shouldReceive('get')
            ->withArgs([['trial_ends']])
            ->andReturn(null);
        $testModel->shouldReceive('get')
            ->withArgs([['plan']])
            ->andReturn('test');
        $testModel->shouldReceive('stripeCustomer')->andReturn($customer)->once();

        $subscription = new BillingSubscription($testModel, 'test', Test::$app);

        $this->assertFalse($subscription->change('blah', true));
    }

    public function testCancelNoStripeCustomer()
    {
        $resultSub = new stdClass();
        $resultSub->status = 'canceled';

        $testModel = Mockery::mock('BillingModel', '\\app\\billing\\models\\BillableModel');
        $testModel->shouldReceive('get')
            ->withArgs([['canceled']])
            ->andReturn(false);
        $testModel->shouldReceive('get')
            ->withArgs([['trial_ends']])
            ->andReturn(0);
        $testModel->shouldReceive('get')
            ->withArgs([['not_charged']])
            ->andReturn(true);
        $testModel->shouldReceive('get')
            ->withArgs([['plan']])
            ->andReturn('test');
        $testModel->shouldReceive('get')
            ->withArgs([['stripe_customer']])
            ->andReturn(null);
        $testModel->shouldReceive('grantAllPermissions');
        $testModel->shouldReceive('enforcePermissions');
        $testModel->shouldReceive('set')->withArgs(['canceled', true])->once();
        $testModel->shouldReceive('sendEmail')->withArgs(['subscription-canceled', ['subject' => 'Your subscription to Test Site has been canceled', 'tags' => ['billing', 'subscription-canceled']]])->once();

        $subscription = new BillingSubscription($testModel, 'test', Test::$app);

        $this->assertTrue($subscription->cancel());
    }

    public function testCancel()
    {
        $resultSub = new stdClass();
        $resultSub->status = 'canceled';

        $customer = Mockery::mock('StripeCustomer');
        $customer->shouldReceive('cancelSubscription')->andReturn($resultSub)->once();

        $testModel = Mockery::mock('BillingModel', '\\app\\billing\\models\\BillableModel');
        $testModel->shouldReceive('get')
            ->withArgs([['canceled']])
            ->andReturn(false);
        $testModel->shouldReceive('get')
            ->withArgs([['trial_ends']])
            ->andReturn(0);
        $testModel->shouldReceive('get')
            ->withArgs([['not_charged']])
            ->andReturn(true);
        $testModel->shouldReceive('get')
            ->withArgs([['plan']])
            ->andReturn('test');
        $testModel->shouldReceive('get')
            ->withArgs([['stripe_customer']])
            ->andReturn('cus_test');
        $testModel->shouldReceive('stripeCustomer')->andReturn($customer)->once();
        $testModel->shouldReceive('grantAllPermissions');
        $testModel->shouldReceive('enforcePermissions');
        $testModel->shouldReceive('set')->withArgs(['canceled', true])->once();

        $subscription = new BillingSubscription($testModel, 'test', Test::$app);

        $this->assertTrue($subscription->cancel());
    }

    public function testCancelFail()
    {
        $customer = Mockery::mock('StripeCustomer');
        $customer->shouldReceive('cancelSubscription')->andThrow(new Exception())->once();

        $testModel = Mockery::mock('BillingModel', '\\app\\billing\\models\\BillableModel');
        $testModel->shouldReceive('get')
            ->withArgs([['canceled']])
            ->andReturn(false);
        $testModel->shouldReceive('get')
            ->withArgs([['trial_ends']])->andReturn(0);
        $testModel->shouldReceive('get')
            ->withArgs([['not_charged']])
            ->andReturn(true);
        $testModel->shouldReceive('get')
            ->withArgs([['plan']])
            ->andReturn('test');
        $testModel->shouldReceive('get')
            ->withArgs([['stripe_customer']])
            ->andReturn('cus_test');
        $testModel->shouldReceive('stripeCustomer')->andReturn($customer)->once();

        $subscription = new BillingSubscription($testModel, 'test', Test::$app);

        $this->assertFalse($subscription->cancel());
    }
}
