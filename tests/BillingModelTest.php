<?php

namespace Infuse\Billing\Tests;

use Infuse\Billing\Exception\BillingException;
use Pulsar\ACLModel;
use Stripe\Error\Api as StripeError;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery;

class BillingModelTest extends MockeryTestCase
{
    public static $model;
    public static $originalDriver;

    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();

        $requester = Mockery::mock('Pulsar\Model');
        $requester->shouldReceive('id')->andReturn(1);
        ACLModel::setRequester($requester);

        self::$originalDriver = TestBillingModel::getDriver();
        $driver = Mockery::mock('Pulsar\Driver\DriverInterface');
        $driver->shouldReceive('createModel')->andReturn(true);
        $driver->shouldReceive('getCreatedID')->andReturn(1);
        $driver->shouldReceive('updateModel')->andReturn(true);
        $driver->shouldReceive('loadModel')->andReturn([]);
        TestBillingModel::setDriver($driver);
    }

    public static function tearDownAfterClass()
    {
        parent::tearDownAfterClass();
        TestBillingModel::setDriver(self::$originalDriver);
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
            'not_charged',
        ];

        $model = new TestBillingModel(); // ensure initialize() called
        foreach ($props as $k) {
            $this->assertTrue(TestBillingModel::hasProperty($k), "Should have property $k");
        }
    }

    public function testCreate()
    {
        self::$model = new TestBillingModel();
        $this->assertTrue(self::$model->save());
    }

    public function testSet()
    {
        self::$model->plan = 'test-plan';
        $this->assertTrue(self::$model->save());
    }

    public function testStripeCustomerRetrieve()
    {
        $testModel = new TestBillingModel();
        $testModel->stripe_customer = 'cust_test';

        $customer = new \stdClass();

        $staticCustomer = Mockery::mock('alias:Stripe\Customer');
        $staticCustomer->shouldReceive('retrieve')
                       ->withArgs(['cust_test', 'apiKey'])
                       ->andReturn($customer)
                       ->once();

        $this->assertEquals($customer, $testModel->stripeCustomer());
    }

    public function testStripeCustomerRetrieveFail()
    {
        $this->expectException(BillingException::class);

        $testModel = new TestBillingModel();
        $testModel->stripe_customer = 'cust_test';

        $e = new StripeError('error');
        $staticCustomer = Mockery::mock('alias:Stripe\Customer');
        $staticCustomer->shouldReceive('retrieve')
                       ->withArgs(['cust_test', 'apiKey'])
                       ->andThrow($e)
                       ->once();

        $testModel->stripeCustomer();
    }

    public function testStripeCustomerCreate()
    {
        $testModel = Mockery::mock(TestBillingModel::class.'[save]', [1]);
        $testModel->shouldReceive('save')
                  ->andReturn(true);
        $testModel->stripe_customer = null;

        $customer = new \stdClass();
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
        $this->expectException(BillingException::class);

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

        $testModel->stripeCustomer();
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
        $this->expectException(BillingException::class);

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

        $testModel->setDefaultCard('tok_test');
    }

    public function testSubscription()
    {
        $testModel = new TestBillingModel();
        $testModel->plan = 'test';

        $subscription = $testModel->subscription();
        $this->assertInstanceOf('Infuse\Billing\Libs\BillingSubscription', $subscription);
        $this->assertEquals('test', $subscription->plan());

        $subscription = $testModel->subscription('blah');
        $this->assertInstanceOf('Infuse\Billing\Libs\BillingSubscription', $subscription);
        $this->assertEquals('blah', $subscription->plan());
    }

    public function testIsCharged()
    {
        self::$model->isCharged();
        $this->assertFalse(self::$model->not_charged);
        $this->assertTrue(self::$model->save());
    }

    public function testIsNotCharged()
    {
        self::$model->isNotCharged();
        $this->assertTrue(self::$model->not_charged);
        $this->assertTrue(self::$model->save());
    }
}
