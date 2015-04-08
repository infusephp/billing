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

        $staticCustomer = Mockery::mock('alias:Stripe\\Customer');
        $staticCustomer->shouldReceive('retrieve')->withArgs(['cust_test', 'apiKey'])->andReturn($customer)->once();

        $this->assertEquals($customer, $testModel->stripeCustomer());
    }

    public function testStripeCustomerRetrieveFail()
    {
        $testModel = new TestBillingModel();
        $testModel->stripe_customer = 'cust_test';

        $staticCustomer = Mockery::mock('alias:Stripe\\Customer');
        $staticCustomer->shouldReceive('retrieve')->withArgs(['cust_test', 'apiKey'])->andThrow(new Exception())->once();

        $this->assertFalse($testModel->stripeCustomer());
    }

    public function testStripeCustomerCreate()
    {
        $testModel = new TestBillingModel(1);

        $customer = new stdClass();
        $customer->id = 'cust_test';

        $staticStripe = Mockery::mock('alias:Stripe\\Stripe');
        $staticStripe->shouldReceive('setApiKey')->withArgs(['apiKey'])->once();

        $staticCustomer = Mockery::mock('alias:Stripe\\Customer');
        $staticCustomer->shouldReceive('create')->withArgs([['description' => 'TestBillingModel(1)'], 'apiKey'])
            ->andReturn($customer)->once();

        $this->assertEquals($customer, $testModel->stripeCustomer());
    }

    public function testStripeCustomerCreateFail()
    {
        $testModel = new TestBillingModel(1);
        $testModel->stripe_customer = false;

        $staticStripe = Mockery::mock('alias:Stripe\\Stripe');
        $staticStripe->shouldReceive('setApiKey')->withArgs(['apiKey'])->once();

        $staticCustomer = Mockery::mock('alias:Stripe\\Customer');
        $staticCustomer->shouldReceive('create')->withArgs([['description' => 'TestBillingModel(1)'], 'apiKey'])
            ->andThrow(new Exception())->once();

        $this->assertFalse($testModel->stripeCustomer());
    }

    public function testSetDefaultCard()
    {
        $testModel = new TestBillingModel(1);
        $testModel->stripe_customer = 'test';

        $customer = Mockery::mock('StripeCustomer');
        $customer->source = false;
        $customer->shouldReceive('save')->once();

        $staticCustomer = Mockery::mock('alias:Stripe\\Customer');
        $staticCustomer->shouldReceive('retrieve')->andReturn($customer)->once();

        $staticStripe = Mockery::mock('alias:Stripe\\Stripe');
        $staticStripe->shouldReceive('setApiKey')->withArgs(['apiKey'])->once();

        $this->assertTrue($testModel->setDefaultCard('tok_test'));

        $this->assertEquals('tok_test', $customer->source);
    }

    public function testSetDefaultCardFail()
    {
        $testModel = new TestBillingModel(1);
        $testModel->stripe_customer = 'test';

        $customer = Mockery::mock('StripeCustomer');
        $customer->source = false;
        $customer->shouldReceive('save')->andThrow(new Exception())->once();

        $staticCustomer = Mockery::mock('alias:Stripe\\Customer');
        $staticCustomer->shouldReceive('retrieve')->andReturn($customer)->once();

        $staticStripe = Mockery::mock('alias:Stripe\\Stripe');
        $staticStripe->shouldReceive('setApiKey')->withArgs(['apiKey'])->once();

        $this->assertFalse($testModel->setDefaultCard('tok_test'));

        $this->assertEquals('tok_test', $customer->source);
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

    public function testSendTrialReminders()
    {
        $model = Mockery::mock('TestBillingModel')->makePartial();
        Test::$app['config']->set('billing.emails.trial_will_end', true);
        Test::$app['config']->set('billing.emails.trial_ended', true);
        $model::inject(Test::$app);

        $findAllMock = Mockery::mock();

        $member = Mockery::mock();
        $email = [
            'subject' => 'Your trial ends soon on Test Site',
            'tags' => ['billing', 'trial-will-end'], ];
        $member->shouldReceive('sendEmail')->withArgs(['trial-will-end', $email])->once();
        $member->shouldReceive('grantAllPermissions');
        $member->shouldReceive('set')->withArgs(['last_trial_reminder', time()]);

        $findAllMock->shouldReceive('findAll')
            ->withArgs([['where' => [
                'trial_ends >= '.strtotime('+2 days'),
                'trial_ends <= '.strtotime('+3 days'),
                'last_trial_reminder IS NULL', ]]])
            ->andReturn([$member])->once();

        $member2 = Mockery::mock();
        $email2 = [
            'subject' => 'Your Test Site trial has ended',
            'tags' => ['billing', 'trial-ended'], ];
        $member2->shouldReceive('sendEmail')->withArgs(['trial-ended', $email2])->once();
        $member2->shouldReceive('grantAllPermissions');
        $member2->shouldReceive('set')->withArgs(['last_trial_reminder', time()]);

        $findAllMock->shouldReceive('findAll')->withArgs([['where' => [
            'trial_ends < '.time(),
            'renews_next' => 0,
            '(last_trial_reminder < trial_ends OR last_trial_reminder IS NULL)', ]]])->andReturn([$member2])->once();

        TestBillingModel::setFindAllMock($findAllMock);

        $this->assertTrue(TestBillingModel::sendTrialReminders());
    }
}
