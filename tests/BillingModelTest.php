<?php

use Infuse\Test;
use Stripe\Error\Api as StripeError;

class BillingModelTest extends PHPUnit_Framework_TestCase
{
    public static $modelDriver;

    public static function setUpBeforeClass()
    {
        static::$modelDriver = TestBillingModel::getDriver();
    }

    protected function tearDown()
    {
        TestBillingModel::setDriver(static::$modelDriver);
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

        $model = new TestBillingModel(); // ensure initialize() called
        foreach ($props as $k) {
            $this->assertTrue(TestBillingModel::hasProperty($k), "Should have property $k");
        }
    }

    public function testCreate()
    {
        $this->markTestIncomplete();
    }

    public function testSet()
    {
        $this->markTestIncomplete();
    }

    public function testStripeCustomerRetrieve()
    {
        $testModel = new TestBillingModel();
        $testModel->stripe_customer = 'cust_test';

        $customer = new stdClass();

        $staticCustomer = Mockery::mock('alias:Stripe\Customer');
        $staticCustomer->shouldReceive('retrieve')
                       ->withArgs(['cust_test', 'apiKey'])
                       ->andReturn($customer)
                       ->once();

        $this->assertEquals($customer, $testModel->stripeCustomer());
    }

    public function testStripeCustomerRetrieveFail()
    {
        $testModel = new TestBillingModel();
        $testModel->stripe_customer = 'cust_test';

        $e = new StripeError('error');
        $staticCustomer = Mockery::mock('alias:Stripe\Customer');
        $staticCustomer->shouldReceive('retrieve')
                       ->withArgs(['cust_test', 'apiKey'])
                       ->andThrow($e)
                       ->once();

        $this->assertFalse($testModel->stripeCustomer());
    }

    public function testStripeCustomerCreate()
    {
        $testModel = Mockery::mock('TestBillingModel[save]', [1]);
        $testModel->shouldReceive('save')
                  ->andReturn(true);
        $testModel->stripe_customer = null;

        $customer = new stdClass();
        $customer->id = 'cust_test';

        $staticStripe = Mockery::mock('alias:Stripe\Stripe');
        $staticStripe->shouldReceive('setApiKey')
                     ->withArgs(['apiKey'])
                     ->once();

        $staticCustomer = Mockery::mock('alias:Stripe\Customer');
        $staticCustomer->shouldReceive('create')
                       ->withArgs([['description' => 'TestBillingModel(1)'], 'apiKey'])
                       ->andReturn($customer)
                       ->once();

        $this->assertEquals($customer, $testModel->stripeCustomer());
    }

    public function testStripeCustomerCreateFail()
    {
        $testModel = new TestBillingModel(1);
        $testModel->stripe_customer = false;

        $staticStripe = Mockery::mock('alias:Stripe\Stripe');
        $staticStripe->shouldReceive('setApiKey')
                     ->withArgs(['apiKey'])
                     ->once();

        $e = new StripeError('error');
        $staticCustomer = Mockery::mock('alias:Stripe\Customer');
        $staticCustomer->shouldReceive('create')
                       ->withArgs([['description' => 'TestBillingModel(1)'], 'apiKey'])
                       ->andThrow($e)
                       ->once();

        $this->assertFalse($testModel->stripeCustomer());
    }

    public function testSetDefaultCard()
    {
        $testModel = new TestBillingModel(1);
        $testModel->stripe_customer = 'test';

        $customer = Mockery::mock('StripeCustomer');
        $customer->source = false;
        $customer->shouldReceive('save')->once();

        $staticCustomer = Mockery::mock('alias:Stripe\Customer');
        $staticCustomer->shouldReceive('retrieve')
                       ->andReturn($customer)
                       ->once();

        $staticStripe = Mockery::mock('alias:Stripe\Stripe');
        $staticStripe->shouldReceive('setApiKey')
                     ->withArgs(['apiKey'])
                     ->once();

        $this->assertTrue($testModel->setDefaultCard('tok_test'));

        $this->assertEquals('tok_test', $customer->source);
    }

    public function testSetDefaultCardFail()
    {
        $testModel = new TestBillingModel(1);
        $testModel->stripe_customer = 'test';

        $e = new StripeError('error');
        $customer = Mockery::mock('StripeCustomer');
        $customer->source = false;
        $customer->shouldReceive('save')
                 ->andThrow($e)
                 ->once();

        $staticCustomer = Mockery::mock('alias:Stripe\Customer');
        $staticCustomer->shouldReceive('retrieve')
                       ->andReturn($customer)
                       ->once();

        $staticStripe = Mockery::mock('alias:Stripe\Stripe');
        $staticStripe->shouldReceive('setApiKey')
                     ->withArgs(['apiKey'])
                     ->once();

        $this->assertFalse($testModel->setDefaultCard('tok_test'));

        $this->assertEquals('tok_test', $customer->source);
    }

    public function testSubscription()
    {
        $testModel = new TestBillingModel();
        $testModel->plan = 'test';

        $subscription = $testModel->subscription();
        $this->assertInstanceOf('App\Billing\Libs\BillingSubscription', $subscription);
        $this->assertEquals('test', $subscription->plan());

        $subscription = $testModel->subscription('blah');
        $this->assertInstanceOf('App\Billing\Libs\BillingSubscription', $subscription);
        $this->assertEquals('blah', $subscription->plan());
    }

    public function testGetTrialsEndingSoon()
    {
        $start = strtotime('+2 days');
        $end = strtotime('+3 days');

        $members = TestBillingModel::getTrialsEndingSoon();

        $this->assertInstanceOf('Pulsar\Iterator', $members);

        $expected = [
            'canceled' => false,
            ['trial_ends', $start, '>='],
            ['trial_ends', $end, '<='],
            'last_trial_reminder IS NULL',
        ];
        $this->assertEquals($expected, $members->getQuery()->getWhere());
    }

    public function testGetEndedTrials()
    {
        $t = time();
        $members = TestBillingModel::getEndedTrials();

        $this->assertInstanceOf('Pulsar\Iterator', $members);

        $expected = [
            'canceled' => false,
            'renews_next' => 0,
            ['trial_ends', 0, '>'],
            ['trial_ends', $t, '<'],
            '(last_trial_reminder < trial_ends OR last_trial_reminder IS NULL)',
        ];
        $this->assertEquals($expected, $members->getQuery()->getWhere());
    }

    public function testSendTrialReminders()
    {
        $member = Mockery::mock('TestBillingModel[save]');
        $member->shouldReceive('save')->once();

        $member2 = Mockery::mock('TestBillingModel[save]');
        $member2->shouldReceive('save')->once();

        TestBillingModel::$endingSoon = [$member];
        TestBillingModel::$ended = [$member2];

        $this->assertEquals([1, 1], TestBillingModel::sendTrialReminders());

        $this->assertGreaterThan(0, $member->last_trial_reminder);

        $expected = [
            'trial-will-end',
            [
                'subject' => 'Your trial ends soon on Test Site',
                'tags' => ['billing', 'trial-will-end'],
            ],
        ];
        $this->assertEquals($expected, $member->lastEmail);

        $this->assertGreaterThan(0, $member2->last_trial_reminder);

        $expected = [
            'trial-ended',
            [
                'subject' => 'Your Test Site trial has ended',
                'tags' => ['billing', 'trial-ended'],
            ],
        ];
        $this->assertEquals($expected, $member2->lastEmail);
    }
}
