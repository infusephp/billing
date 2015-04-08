<?php

use infuse\Model;
use app\billing\models\BillableModel;

class TestBillingModel extends BillableModel
{
    public static $findAllMock;

    protected function hasPermission($permission, Model $requester)
    {
        return true;
    }

    public function stripeCustomerData()
    {
        return [
            'description' => 'TestBillingModel('.$this->id.')', ];
    }

    public static function setFindAllMock($mock)
    {
        self::$findAllMock = $mock;
    }

    public static function findAll(array $params = [])
    {
        return self::$findAllMock->findAll($params);
    }
}
