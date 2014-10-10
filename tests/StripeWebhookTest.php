<?php

use infuse\Database;

use app\billing\libs\StripeWebhook;
use app\billing\models\BillingHistory;

class StripeWebhookTest extends \PHPUnit_Framework_TestCase
{
    public static function setUpBeforeClass()
    {
        require_once 'TestBillingModel.php';

        Database::delete('BillingHistories', ['stripe_transaction' => 'charge_failed']);
    }

    public function setUp()
    {
        TestBootstrap::app('user')->enableSU();
    }

    public function tearDown()
    {
        TestBootstrap::app('user')->disableSU();
    }

    public function testProcessFail()
    {
        $app = TestBootstrap::app();

        $webhook = new StripeWebhook([], $app);
        $this->assertEquals(ERROR_INVALID_EVENT, $webhook->process());

        $event = [
            'id' => 'evt_test',
            'livemode' => true ];
        $webhook = new StripeWebhook($event, $app);
        $this->assertEquals(ERROR_LIVEMODE_MISMATCH, $webhook->process());

        $event = [
            'id' => 'evt_test',
            'livemode' => false,
            'user_id' => 'usr_1234' ];
        $webhook = new StripeWebhook($event, $app);
        $this->assertEquals(ERROR_STRIPE_CONNECT_EVENT, $webhook->process());

        $event = [
            'id' => 'evt_test',
            'livemode' => false,
            'type' => 'account.application.deauthorized' ];
        $webhook = new StripeWebhook($event, $app);
        $this->assertEquals(ERROR_EVENT_NOT_SUPPORTED, $webhook->process());

        $staticEvent = Mockery::mock('alias:Stripe_Event');
        $e = new Exception();
        $staticEvent->shouldReceive('retrieve')->withArgs(['evt_test', 'apiKey'])->andThrow(new Exception());

        $event = [
            'id' => 'evt_test',
            'livemode' => false,
            'type' => 'customer.subscription.trial_will_end' ];
        $webhook = new StripeWebhook($event, $app);
        $this->assertEquals('error', $webhook->process());

        $validatedEvent = new stdClass();
        $validatedEvent->type = 'customer.subscription.trial_will_end';
        $validatedEvent->data = new stdClass();
        $validatedEvent->data->object = new stdClass();
        $validatedEvent->data->object->customer = 'cus_test';
        $staticEvent->shouldReceive('retrieve')->withArgs(['evt_test2', 'apiKey'])->andReturn($validatedEvent);

        $event = [
            'id' => 'evt_test2',
            'livemode' => false,
            'type' => 'customer.subscription.trial_will_end' ];
        $webhook = new StripeWebhook($event, $app);
        $this->assertEquals(ERROR_CUSTOMER_NOT_FOUND, $webhook->process());
    }

    public function testProcess()
    {
        $app = TestBootstrap::app();

        $validatedEvent = new stdClass();
        $validatedEvent->type = 'customer.subscription.trial_will_end';
        $validatedEvent->data = new stdClass();
        $validatedEvent->data->object = new stdClass();
        $validatedEvent->data->object->customer = 'cus_test';
        $staticEvent = Mockery::mock('alias:Stripe_Event');
        $staticEvent->shouldReceive('retrieve')->withArgs(['evt_test', 'apiKey'])->andReturn($validatedEvent);

        $model = Mockery::mock();
        $model->shouldReceive('sendEmail')->once();

        $app['config']->set('billing.model', 'TestBillingModel2');
        $staticModel = Mockery::mock('alias:TestBillingModel2');
        $staticModel->shouldReceive('findOne')->andReturn($model);

        $event = [
            'id' => 'evt_test',
            'livemode' => false,
            'type' => 'customer.subscription.trial_will_end' ];
        $webhook = new StripeWebhook($event, $app);

        $this->assertEquals(STRIPE_WEBHOOK_SUCCESS, $webhook->process());
    }

    public function testChargeFailed()
    {
        $event = new stdClass();
        $event->id = 'charge_failed';
        $event->customer = 'cus_test';
        $event->description = 'Descr';
        $event->created = 12;
        $event->amount = 1000;
        $event->card = new stdClass();
        $event->card->last4 = '1234';
        $event->card->exp_month = '05';
        $event->card->exp_year = '2014';
        $event->card->brand = 'Visa';
        $event->failure_message = 'Fail!';

        $webhook = new StripeWebhook([], TestBootstrap::app());

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
            'error_message' => 'Fail!' ];
        $member->shouldReceive('sendEmail')->withArgs(['payment-problem', $email])->once();

        $this->assertTrue($webhook->chargeFailedHandler($event, $member));

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
            'error' => 'Fail!' ];
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
        $event->card = new stdClass();
        $event->card->last4 = '1234';
        $event->card->exp_month = '05';
        $event->card->exp_year = '2014';
        $event->card->brand = 'Visa';

        $webhook = new StripeWebhook([], TestBootstrap::app());

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
            'card_type' => 'Visa' ];
        $member->shouldReceive('sendEmail')->withArgs(['payment-received', $email])->once();

        $this->assertTrue($webhook->chargeSucceededHandler($event, $member));

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
            'error' => null ];
        $this->assertEquals($expected, $history->toArray(['id']));
    }

    public function testPaymentSucceeded()
    {
        $this->markTestIncomplete();
    }

    public function testSubscriptionCreated()
    {
        $this->markTestIncomplete();
    }

    public function testSubscriptionUpdated()
    {
        $this->markTestIncomplete();
    }

    public function testSubscriptionDeleted()
    {
        $this->markTestIncomplete();
    }

    public function testTrialWillEnd()
    {
        $event = new stdClass();

        $webhook = new StripeWebhook([], TestBootstrap::app());

        $member = Mockery::mock();
        $email = [
            'subject' => 'Your trial ends soon on Test Site' ];
        $member->shouldReceive('sendEmail')->withArgs(['trial-will-end', $email])->once();

        $this->assertTrue($webhook->trialWillEnd($event, $member));
    }
}
