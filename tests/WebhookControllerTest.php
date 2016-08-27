<?php

use Infuse\Billing\Libs\WebhookController;
use Infuse\Test;
use Stripe\Error\Api as StripeError;

class WebhookControllerTest extends PHPUnit_Framework_TestCase
{
    public static $webhook;
    public static $modelDriver;

    public static function setUpBeforeClass()
    {
        self::$webhook = new TestController();
        self::$webhook->setApp(Test::$app);

        static::$modelDriver = TestBillingModel::getDriver();
    }

    protected function tearDown()
    {
        TestBillingModel::setDriver(static::$modelDriver);
    }

    public function testHandleInvalidEvent()
    {
        $this->assertEquals(WebhookController::ERROR_INVALID_EVENT, self::$webhook->handle([]));
    }

    public function testHandleLivemodeMismatch()
    {
        $event = [
            'id' => 'evt_test',
            'livemode' => true,
        ];

        $this->assertEquals(WebhookController::ERROR_LIVEMODE_MISMATCH, self::$webhook->handle($event));
    }

    public function testHandleConnectEvent()
    {
        $event = [
            'id' => 'evt_test',
            'livemode' => false,
            'user_id' => 'usr_1234',
        ];

        $this->assertEquals(WebhookController::ERROR_STRIPE_CONNECT_EVENT, self::$webhook->handle($event));
    }

    public function testHandleException()
    {
        $e = new StripeError('error');
        $staticEvent = Mockery::mock('alias:Stripe\Event');
        $staticEvent->shouldReceive('retrieve')
                    ->withArgs(['evt_test', 'apiKey'])
                    ->andThrow($e);

        $event = [
            'id' => 'evt_test',
            'livemode' => false,
            'type' => 'customer.subscription.updated',
        ];

        $this->assertEquals(WebhookController::ERROR_GENERIC, self::$webhook->handle($event));
    }

    public function testHandleCustomerNotFound()
    {
        $driver = Mockery::mock('Pulsar\Driver\DriverInterface');
        $driver->shouldReceive('queryModels')
               ->andReturn([]);
        TestBillingModel::setDriver($driver);

        $validatedEvent = new stdClass();
        $validatedEvent->type = 'customer.subscription.updated';
        $validatedEvent->data = new stdClass();
        $validatedEvent->data->object = new stdClass();
        $validatedEvent->data->object->customer = 'cus_test';
        $staticEvent = Mockery::mock('alias:Stripe\Event');
        $staticEvent->shouldReceive('retrieve')
                    ->withArgs(['evt_test2', 'apiKey'])
                    ->andReturn($validatedEvent);

        $event = [
            'id' => 'evt_test2',
            'livemode' => false,
            'type' => 'customer.subscription.updated',
        ];

        $this->assertEquals(WebhookController::ERROR_CUSTOMER_NOT_FOUND, self::$webhook->handle($event));
    }

    public function testHandleNotSupported()
    {
        $driver = Mockery::mock('Pulsar\Driver\DriverInterface');
        $driver->shouldReceive('queryModels')
               ->andReturn([['id' => 100]]);
        TestBillingModel::setDriver($driver);

        $staticEvent = Mockery::mock('alias:Stripe\Event');
        $validatedEvent = new stdClass();
        $validatedEvent->type = 'event.not_found';
        $validatedEvent->data = new stdClass();
        $validatedEvent->data->object = new stdClass();
        $validatedEvent->data->object->customer = 'cus_test';
        $staticEvent->shouldReceive('retrieve')
                    ->withArgs(['evt_test3', 'apiKey'])
                    ->andReturn($validatedEvent);

        $event = [
            'id' => 'evt_test3',
            'livemode' => false,
            'type' => 'event.not_found',
        ];

        $this->assertEquals(WebhookController::ERROR_EVENT_NOT_SUPPORTED, self::$webhook->handle($event));
    }

    public function testHandle()
    {
        $driver = Mockery::mock('Pulsar\Driver\DriverInterface');
        $driver->shouldReceive('queryModels')
               ->andReturn([['id' => 100]]);
        TestBillingModel::setDriver($driver);

        $validatedEvent = new stdClass();
        $validatedEvent->type = 'test';
        $validatedEvent->data = new stdClass();
        $validatedEvent->data->object = new stdClass();
        $validatedEvent->data->object->customer = 'cus_test';
        $staticEvent = Mockery::mock('alias:Stripe\Event');
        $staticEvent->shouldReceive('retrieve')
                    ->withArgs(['evt_test', 'apiKey'])
                    ->andReturn($validatedEvent);

        $event = [
            'id' => 'evt_test',
            'livemode' => false,
            'type' => 'test',
        ];

        $this->assertEquals(WebhookController::SUCCESS, self::$webhook->handle($event));
    }
}

class TestController extends WebhookController
{
    public function handleTest()
    {
        return true;
    }
}
