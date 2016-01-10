<?php

use App\Billing\Models\BillableModel;
use Pulsar\Model;

class TestBillingModel extends BillableModel
{
    public $lastEmail;
    public static $endingSoon;
    public static $ended;

    protected static $hidden = [];

    protected function hasPermission($permission, Model $requester)
    {
        return true;
    }

    public function stripeCustomerData()
    {
        return [
            'description' => 'TestBillingModel('.$this->id().')',
        ];
    }

    public function sendEmail()
    {
        $this->lastEmail = func_get_args();
    }

    public static function getTrialsEndingSoon()
    {
        return self::$endingSoon ? self::$endingSoon : parent::getTrialsEndingSoon();
    }

    public static function getEndedTrials()
    {
        return self::$ended ? self::$ended : parent::getEndedTrials();
    }
}
