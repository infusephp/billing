<?php

class BillingModelTest extends \PHPUnit_Framework_TestCase
{
    public static function setUpBeforeClass()
    {
        require_once 'TestBillingModel.php';
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

        $staticCustomer = Mockery::mock('alias:Stripe_Customer');
        $staticCustomer->shouldReceive('retrieve')->withArgs(['cust_test', 'apiKey'])->andReturn($customer)->once();

        $this->assertEquals($customer, $testModel->stripeCustomer());
    }

    public function testStripeCustomerRetrieveFail()
    {
        $testModel = new TestBillingModel();
        $testModel->stripe_customer = 'cust_test';

        $staticCustomer = Mockery::mock('alias:Stripe_Customer');
        $staticCustomer->shouldReceive('retrieve')->withArgs(['cust_test', 'apiKey'])->andThrow(new Exception())->once();

        $this->assertFalse($testModel->stripeCustomer());
    }

    public function testStripeCustomerCreate()
    {
        $testModel = new TestBillingModel(1);

        $customer = new stdClass();
        $customer->id = 'cust_test';

        $staticCustomer = Mockery::mock('alias:Stripe_Customer');
        $staticCustomer->shouldReceive('create')->withArgs([['description' => 'TestBillingModel(1)'], 'apiKey'])
            ->andReturn($customer)->once();

        $this->assertEquals($customer, $testModel->stripeCustomer());
    }

    public function testStripeCustomerCreateFail()
    {
        $testModel = new TestBillingModel(1);
        $testModel->stripe_customer = false;

        $staticCustomer = Mockery::mock('alias:Stripe_Customer');
        $staticCustomer->shouldReceive('create')->withArgs([['description' => 'TestBillingModel(1)'], 'apiKey'])
            ->andThrow(new Exception())->once();

        $this->assertFalse($testModel->stripeCustomer());
    }

    public function testSetDefaultCard()
    {
        $testModel = new TestBillingModel(1);
        $testModel->stripe_customer = 'test';

        $customer = Mockery::mock('StripeCustomer');
        $customer->card = false;
        $customer->shouldReceive('save')->once();

        $staticCustomer = Mockery::mock('alias:Stripe_Customer');
        $staticCustomer->shouldReceive('retrieve')->andReturn($customer)->once();

        $staticStripe = Mockery::mock('alias:Stripe');
        $staticStripe->shouldReceive('setApiKey')->withArgs(['apiKey'])->once();

        $this->assertTrue($testModel->setDefaultCard('tok_test'));

        $this->assertEquals('tok_test', $customer->card);
    }

    public function testSetDefaultCardFail()
    {
        $testModel = new TestBillingModel(1);
        $testModel->stripe_customer = 'test';

        $customer = Mockery::mock('StripeCustomer');
        $customer->card = false;
        $customer->shouldReceive('save')->andThrow(new Exception())->once();

        $staticCustomer = Mockery::mock('alias:Stripe_Customer');
        $staticCustomer->shouldReceive('retrieve')->andReturn($customer)->once();

        $staticStripe = Mockery::mock('alias:Stripe');
        $staticStripe->shouldReceive('setApiKey')->withArgs(['apiKey'])->once();

        $this->assertFalse($testModel->setDefaultCard('tok_test'));

        $this->assertEquals('tok_test', $customer->card);
    }

    public function testSubscription()
    {
        $testModel = new TestBillingModel();
        $testModel->plan = 'test';

        $subscription = $testModel->subscription();
        $this->assertInstanceOf('\\app\\billing\\libs\\BillingSubscription', $subscription);
        $this->assertEquals('test', $subscription->plan());

        $subscription = $testModel->subscription('blah');
        $this->assertInstanceOf('\\app\\billing\\libs\\BillingSubscription', $subscription);
        $this->assertEquals('blah', $subscription->plan());
    }
}
