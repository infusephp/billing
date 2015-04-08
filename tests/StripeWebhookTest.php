<?php

use app\billing\libs\StripeWebhook;
use app\billing\models\BillingHistory;

class StripeWebhookTest extends PHPUnit_Framework_TestCase
{
    public static $webhook;

    public static function setUpBeforeClass()
    {
        require_once 'TestBillingModel.php';

        Test::$app['db']->delete('BillingHistories')
            ->where('stripe_transaction', 'charge_failed')->execute();

        self::$webhook = new StripeWebhook();
        self::$webhook->injectApp(Test::$app);
    }

    public function setUp()
    {
        Test::$app['user']->enableSU();
    }

    public function tearDown()
    {
        Test::$app['user']->disableSU();
    }

    public function testHandleInvalidEvent()
    {
        $this->assertEquals(StripeWebhook::ERROR_INVALID_EVENT, self::$webhook->handle([]));
    }

    public function testHandleLivemodeMismatch()
    {
        $event = [
            'id' => 'evt_test',
            'livemode' => true, ];
        $this->assertEquals(StripeWebhook::ERROR_LIVEMODE_MISMATCH, self::$webhook->handle($event));
    }

    public function testHandleConnectEvent()
    {
        $event = [
            'id' => 'evt_test',
            'livemode' => false,
            'user_id' => 'usr_1234', ];
        $this->assertEquals(StripeWebhook::ERROR_STRIPE_CONNECT_EVENT, self::$webhook->handle($event));
    }

    public function testHandleCustomerNotFound()
    {
        $validatedEvent = new stdClass();
        $validatedEvent->type = 'customer.subscription.trial_will_end';
        $validatedEvent->data = new stdClass();
        $validatedEvent->data->object = new stdClass();
        $validatedEvent->data->object->customer = 'cus_test';
        $staticEvent = Mockery::mock('alias:Stripe\\Event');
        $staticEvent->shouldReceive('retrieve')->withArgs(['evt_test2', 'apiKey'])->andReturn($validatedEvent);

        $event = [
            'id' => 'evt_test2',
            'livemode' => false,
            'type' => 'customer.subscription.trial_will_end', ];
        $this->assertEquals(StripeWebhook::ERROR_CUSTOMER_NOT_FOUND, self::$webhook->handle($event));
    }

    public function testHandleNotSupported()
    {
        $staticEvent = Mockery::mock('alias:Stripe\\Event');
        $validatedEvent = new stdClass();
        $validatedEvent->type = 'event.not_found';
        $validatedEvent->data = new stdClass();
        $validatedEvent->data->object = new stdClass();
        $validatedEvent->data->object->customer = 'cus_test';
        $staticEvent->shouldReceive('retrieve')->withArgs(['evt_test3', 'apiKey'])->andReturn($validatedEvent);

        $model = Mockery::mock();

        Test::$app['config']->set('billing.model', 'TestBillingModel2');
        $staticModel = Mockery::mock('alias:TestBillingModel2');
        $staticModel->shouldReceive('findOne')->andReturn($model);

        $event = [
            'id' => 'evt_test3',
            'livemode' => false,
            'type' => 'event.not_found', ];
        $this->assertEquals(StripeWebhook::ERROR_EVENT_NOT_SUPPORTED, self::$webhook->handle($event));
    }

    public function testHandleException()
    {
        $e = new Exception();
        $staticEvent = Mockery::mock('alias:Stripe\\Event');
        $staticEvent->shouldReceive('retrieve')->withArgs(['evt_test', 'apiKey'])->andThrow(new Exception());

        $event = [
            'id' => 'evt_test',
            'livemode' => false,
            'type' => 'customer.subscription.trial_will_end', ];
        $this->assertEquals(StripeWebhook::ERROR_GENERIC, self::$webhook->handle($event));
    }

    public function testHandle()
    {
        $validatedEvent = new stdClass();
        $validatedEvent->type = 'customer.subscription.trial_will_end';
        $validatedEvent->data = new stdClass();
        $validatedEvent->data->object = new stdClass();
        $validatedEvent->data->object->customer = 'cus_test';
        $validatedEvent->data->object->trial_end = time();
        $staticEvent = Mockery::mock('alias:Stripe\\Event');
        $staticEvent->shouldReceive('retrieve')->withArgs(['evt_test', 'apiKey'])->andReturn($validatedEvent);

        $model = Mockery::mock();

        Test::$app['config']->set('billing.model', 'TestBillingModel2');
        $staticModel = Mockery::mock('alias:TestBillingModel2');
        $staticModel->shouldReceive('findOne')->andReturn($model);

        $event = [
            'id' => 'evt_test',
            'livemode' => false,
            'type' => 'customer.subscription.trial_will_end', ];

        $this->assertEquals(StripeWebhook::SUCCESS, self::$webhook->handle($event));
    }

    public function testChargeFailed()
    {
        $event = new stdClass();
        $event->id = 'charge_failed';
        $event->customer = 'cus_test';
        $event->description = 'Descr';
        $event->created = 12;
        $event->amount = 1000;
        $event->source = new stdClass();
        $event->source->object = 'card';
        $event->source->last4 = '1234';
        $event->source->exp_month = '05';
        $event->source->exp_year = '2014';
        $event->source->brand = 'Visa';
        $event->failure_message = 'Fail!';

        $member = Mockery::mock();
        $member->shouldReceive('id')->andReturn(100);
        $member->shouldReceive('hasProperty')->andReturn(false);
        $email = [
            'subject' => 'Declined charge for Test Site',
            'timestamp' => 12,
            'payment_time' => date('F j, Y g:i a T', 12),
            'amount' => '10.00',
            'description' => 'Descr',
            'card_last4' => '1234',
            'card_expires' => '05/2014',
            'card_type' => 'Visa',
            'error_message' => 'Fail!',
            'tags' => ['billing', 'charge-failed'], ];
        $member->shouldReceive('sendEmail')->withArgs(['payment-problem', $email])->once();

        $this->assertTrue(self::$webhook->handleChargeFailed($event, $member));

        $history = BillingHistory::findOne(['where' => ['stripe_transaction' => 'charge_failed']]);
        $this->assertInstanceOf('\\app\\billing\\models\\BillingHistory', $history);

        $expected = [
            'uid' => 100,
            'payment_time' => 12,
            'amount' => 10,
            'stripe_customer' => 'cus_test',
            'stripe_transaction' => 'charge_failed',
            'description' => 'Descr',
            'success' => false,
            'error' => 'Fail!', ];
        $this->assertEquals($expected, $history->toArray(['id']));
    }

    public function testChargeSucceeded()
    {
        $event = new stdClass();
        $event->id = 'charge_succeeded';
        $event->customer = 'cus_test';
        $event->description = 'Descr';
        $event->created = 12;
        $event->amount = 1000;
        $event->source = new stdClass();
        $event->source->object = 'card';
        $event->source->last4 = '1234';
        $event->source->exp_month = '05';
        $event->source->exp_year = '2014';
        $event->source->brand = 'Visa';

        $member = Mockery::mock();
        $member->shouldReceive('id')->andReturn(100);
        $member->shouldReceive('hasProperty')->andReturn(false);
        $email = [
            'subject' => 'Payment receipt on Test Site',
            'timestamp' => 12,
            'payment_time' => date('F j, Y g:i a T', 12),
            'amount' => '10.00',
            'description' => 'Descr',
            'card_last4' => '1234',
            'card_expires' => '05/2014',
            'card_type' => 'Visa',
            'tags' => ['billing','payment-received'], ];
        $member->shouldReceive('sendEmail')->withArgs(['payment-received', $email])->once();

        $this->assertTrue(self::$webhook->handleChargeSucceeded($event, $member));

        $history = BillingHistory::findOne(['where' => ['stripe_transaction' => 'charge_succeeded']]);
        $this->assertInstanceOf('\\app\\billing\\models\\BillingHistory', $history);

        $expected = [
            'uid' => 100,
            'payment_time' => 12,
            'amount' => 10,
            'stripe_customer' => 'cus_test',
            'stripe_transaction' => 'charge_succeeded',
            'description' => 'Descr',
            'success' => true,
            'error' => null, ];
        $this->assertEquals($expected, $history->toArray(['id']));
    }

    public function testSubscriptionCreated()
    {
        $sub = new stdClass();
        $sub->status = 'trialing';
        $sub->trial_end = 100;
        $sub->current_period_end = 101;

        $customer = new stdClass();
        $customer->subscriptions = new stdClass();
        $customer->subscriptions->data = [$sub];

        $staticCustomer = Mockery::mock('alias:Stripe\\Customer');
        $staticCustomer->shouldReceive('retrieve')->withArgs(['cus_test', 'apiKey'])->andReturn($customer);

        $event = new stdClass();
        $event->customer = 'cus_test';

        $member = Mockery::mock();
        $member->shouldReceive('set')->withArgs([[
            'past_due' => false,
            'trial_ends' => 100,
            'renews_next' => 101, ]]);

        $this->assertTrue(self::$webhook->handleCustomerSubscriptionCreated($event, $member));
    }

    public function testSubscriptionUnpaid()
    {
        $sub = new stdClass();
        $sub->status = 'unpaid';
        $sub->trial_end = 100;

        $customer = new stdClass();
        $customer->subscriptions = new stdClass();
        $customer->subscriptions->data = [$sub];

        $staticCustomer = Mockery::mock('alias:Stripe\\Customer');
        $staticCustomer->shouldReceive('retrieve')->withArgs(['cus_test', 'apiKey'])->andReturn($customer);

        $event = new stdClass();
        $event->customer = 'cus_test';

        $member = Mockery::mock();
        $member->shouldReceive('set')->withArgs([[
            'past_due' => false,
            'trial_ends' => 100, ]]);
        $email = [
            'subject' => 'Your Test Site trial has ended',
            'tags' => ['billing', 'trial-ended'], ];
        $member->shouldReceive('sendEmail')->withArgs(['trial-ended', $email])->once();

        $this->assertTrue(self::$webhook->handleCustomerSubscriptionUpdated($event, $member));
    }

    public function testSubscriptionPastDue()
    {
        $sub = new stdClass();
        $sub->status = 'past_due';
        $sub->trial_end = 100;
        $sub->current_period_end = 101;

        $customer = new stdClass();
        $customer->subscriptions = new stdClass();
        $customer->subscriptions->data = [$sub];

        $staticCustomer = Mockery::mock('alias:Stripe\\Customer');
        $staticCustomer->shouldReceive('retrieve')->withArgs(['cus_test', 'apiKey'])->andReturn($customer);

        $event = new stdClass();
        $event->customer = 'cus_test';

        $member = Mockery::mock();
        $member->shouldReceive('set')->withArgs([[
            'past_due' => true,
            'trial_ends' => 100,
            'renews_next' => 101, ]]);

        $this->assertTrue(self::$webhook->handleCustomerSubscriptionUpdated($event, $member));
    }

    public function testSubscriptionCanceled()
    {
        $event = new stdClass();

        $member = Mockery::mock();
        $member->shouldReceive('set')->withArgs(['canceled', true]);
        $email = [
            'subject' => 'Your subscription to Test Site has been canceled',
            'tags' => ['billing', 'subscription-canceled'], ];
        $member->shouldReceive('sendEmail')->withArgs(['subscription-canceled', $email])->once();

        $this->assertTrue(self::$webhook->handleCustomerSubscriptionDeleted($event, $member));
    }

    public function testTrialWillEnd()
    {
        $event = new stdClass();
        $event->trial_end = strtotime('+3 days');

        $member = Mockery::mock();
        $email = [
            'subject' => 'Your trial ends soon on Test Site',
            'tags' => ['billing', 'trial-will-end'], ];
        $member->shouldReceive('sendEmail')->withArgs(['trial-will-end', $email])->once();

        $this->assertTrue(self::$webhook->handleCustomerSubscriptionTrialWillEnd($event, $member));
    }
}
