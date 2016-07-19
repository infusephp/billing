<?php

use App\Billing\Jobs\SendTrialReminders;
use Infuse\Test;
use Pulsar\ACLModel;

class SendTrialRemindersTest extends PHPUnit_Framework_TestCase
{
    public static $originalDriver;
    public static $driver;

    public static function setUpBeforeClass()
    {
        ACLModel::setRequester(Mockery::mock('Pulsar\Model'));

        self::$originalDriver = TestBillingModel::getDriver();
        $driver = Mockery::mock('Pulsar\Driver\DriverInterface');
        $driver->shouldReceive('createModel')->andReturn(true);
        $driver->shouldReceive('getCreatedID')->andReturn(1);
        $driver->shouldReceive('updateModel')->andReturn(true);
        $driver->shouldReceive('loadModel')->andReturn([]);
        TestBillingModel::setDriver($driver);
        self::$driver = $driver;
    }

    public static function tearDownAfterClass()
    {
        TestBillingModel::setDriver(self::$originalDriver);
    }

    public function testGetTrialsEndingSoon()
    {
        $job = $this->getJob();

        $start = strtotime('+2 days');
        $end = strtotime('+3 days');

        $members = $job->getTrialsEndingSoon();

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
        $job = $this->getJob();

        $t = time();
        $members = $job->getEndedTrials();

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
        $job = Mockery::mock('App\Billing\Jobs\SendTrialReminders[getTrialsEndingSoon,getEndedTrials]');
        $job->setApp(Test::$app);

        $member = Mockery::mock('TestBillingModel[save]');
        $member->shouldReceive('save')->once();

        $member2 = Mockery::mock('TestBillingModel[save]');
        $member2->shouldReceive('save')->once();

        $job->shouldReceive('getTrialsEndingSoon')
            ->andReturn([$member]);

        $job->shouldReceive('getEndedTrials')
            ->andReturn([$member2]);

        $this->assertEquals([1, 1], $job->sendTrialReminders());

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

    public function testSendTrialRemindersDisabled()
    {
        Test::$app['config']->set('billing.emails.trial_will_end', false);
        Test::$app['config']->set('billing.emails.trial_ended', false);
        $this->assertEquals([0, 0], $this->getJob()->sendTrialReminders());
    }

    public function testRun()
    {
        $run = Mockery::mock();
        $run->shouldReceive('writeOutput');

        $job = Mockery::mock('App\Billing\Jobs\SendTrialReminders[sendTrialReminders]');
        $job->shouldReceive('sendTrialReminders')
            ->andReturn([0, 0])
            ->once();
        $job->setApp(Test::$app);

        $job($run);
    }

    private function getJob()
    {
        $job = new SendTrialReminders();
        $job->setApp(Test::$app);

        return $job;
    }
}
