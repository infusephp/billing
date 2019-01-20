<?php

namespace Infuse\Billing\Tests;

use Infuse\Billing\Libs\StripeWebhook;
use Infuse\Billing\Models\BillingHistory;
use Infuse\Test;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery;
use Pulsar\ACLModelRequester;

class StripeWebhookTest extends MockeryTestCase
{
    public static $webhook;

    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();

        Test::$app['database']->getDefault()
            ->delete('BillingHistories')
            ->where('stripe_transaction', 'charge_failed')
            ->execute();

        self::$webhook = new StripeWebhook();
        self::$webhook->setApp(Test::$app);

        $model = Mockery::mock('Pulsar\Model');
        ACLModelRequester::set($model);
    }

    public function testHandleChargeFailed()
    {
        $event = new \stdClass();
        $event->id = 'charge_failed';
        $event->customer = 'cus_test';
        $event->description = 'Descr';
        $event->created = 12;
        $event->amount = 1000;
        $event->source = new \stdClass();
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

        $history = BillingHistory::where('stripe_transaction', 'charge_failed')
            ->first();
        $this->assertInstanceOf('Infuse\Billing\Models\BillingHistory', $history);

        $expected = [
            'id' => $history->id(),
            'user_id' => 100,
            'payment_time' => 12,
            'amount' => 10,
            'stripe_customer' => 'cus_test',
            'stripe_transaction' => 'charge_failed',
            'description' => 'Descr',
            'success' => false,
            'error' => 'Fail!',
        ];
        $this->assertEquals($expected, $history->toArray());
    }

    public function testHandleChargeSucceeded()
    {
        $event = new \stdClass();
        $event->id = 'charge_succeeded';
        $event->customer = 'cus_test';
        $event->description = 'Descr';
        $event->created = 12;
        $event->amount = 1000;
        $event->source = new \stdClass();
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
            'tags' => ['billing', 'payment-received'], ];
        $member->shouldReceive('sendEmail')->withArgs(['payment-received', $email])->once();

        $this->assertTrue(self::$webhook->handleChargeSucceeded($event, $member));

        $history = BillingHistory::where('stripe_transaction', 'charge_succeeded')
            ->first();
        $this->assertInstanceOf('Infuse\Billing\Models\BillingHistory', $history);

        $expected = [
            'id' => $history->id(),
            'user_id' => 100,
            'payment_time' => 12,
            'amount' => 10,
            'stripe_customer' => 'cus_test',
            'stripe_transaction' => 'charge_succeeded',
            'description' => 'Descr',
            'success' => true,
            'error' => null,
        ];
        $this->assertEquals($expected, $history->toArray());
    }

    public function testHandleSubscriptionCreated()
    {
        $event = new \stdClass();
        $event->status = 'trialing';
        $event->trial_end = 100;
        $event->current_period_end = 101;
        $event->plan = new \stdClass();
        $event->plan->id = 'growth';

        $member = Mockery::mock(TestBillingModel::class.'[save]');
        $member->shouldReceive('save')->once();

        $this->assertTrue(self::$webhook->handleCustomerSubscriptionCreated($event, $member));

        $this->assertFalse($member->past_due);
        $this->assertEquals(101, $member->renews_next);
        $this->assertFalse($member->canceled);
        $this->assertNull($member->canceled_at);
        $this->assertEquals('growth', $member->plan);
    }

    public function testHandleSubscriptionUnpaid()
    {
        $event = new \stdClass();
        $event->status = 'unpaid';
        $event->trial_end = 100;
        $event->plan = new \stdClass();
        $event->plan->id = 'startup';

        $member = Mockery::mock(TestBillingModel::class.'[save]');
        $member->shouldReceive('save')->once();

        $this->assertTrue(self::$webhook->handleCustomerSubscriptionUpdated($event, $member));

        $this->assertFalse($member->past_due);
        $this->assertEquals('startup', $member->plan);
    }

    public function testHandleSubscriptionPastDue()
    {
        $event = new \stdClass();
        $event->status = 'past_due';
        $event->trial_end = 100;
        $event->current_period_end = 101;
        $event->plan = new \stdClass();
        $event->plan->id = 'startup';

        $member = Mockery::mock(TestBillingModel::class.'[save]');
        $member->shouldReceive('save')->once();

        $this->assertTrue(self::$webhook->handleCustomerSubscriptionUpdated($event, $member));

        $this->assertTrue($member->past_due);
        $this->assertEquals(101, $member->renews_next);
        $this->assertFalse($member->canceled);
        $this->assertNull($member->canceled_at);
        $this->assertEquals(0, $member->trial_ends);
        $this->assertEquals('startup', $member->plan);
    }

    public function testHandleSubscriptionActive()
    {
        $event = new \stdClass();
        $event->status = 'active';
        $event->trial_end = 100;
        $event->current_period_end = 1000;
        $event->plan = new \stdClass();
        $event->plan->id = 'startup';

        $member = Mockery::mock(TestBillingModel::class.'[save]');
        $member->shouldReceive('save')->once();

        $this->assertTrue(self::$webhook->handleCustomerSubscriptionUpdated($event, $member));

        $this->assertFalse($member->past_due);
        $this->assertEquals(1000, $member->renews_next);
        $this->assertFalse($member->canceled);
        $this->assertNull($member->canceled_at);
        $this->assertEquals(0, $member->trial_ends);
        $this->assertEquals('startup', $member->plan);
    }

    public function testHandleSubscriptionCanceled()
    {
        $event = new \stdClass();

        $member = Mockery::mock(TestBillingModel::class.'[save]');
        $member->shouldReceive('save')->once();

        $this->assertTrue(self::$webhook->handleCustomerSubscriptionDeleted($event, $member));

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
}
