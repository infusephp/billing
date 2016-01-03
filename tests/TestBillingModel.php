<?php

use App\Billing\Models\BillableModel;
use Pulsar\Model;

class TestBillingModel extends BillableModel
{
    public static $whereMock;
    protected static $hidden = [];

    protected function hasPermission($permission, Model $requester)
    {
        return true;
    }

    public function stripeCustomerData()
    {
        return [
            'description' => 'TestBillingModel('.$this->id.')',
        ];
    }

    public static function setWhereMock($mock)
    {
        self::$whereMock = $mock;
    }

    public static function where($params)
    {
        return self::$whereMock ? self::$whereMock->where($params) : parent::where($params);
    }
}
