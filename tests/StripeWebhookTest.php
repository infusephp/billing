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

    public function testProcess()
    {
        $this->markTestIncomplete();
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
