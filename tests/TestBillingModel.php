<?php

use infuse\Model;
use app\billing\models\BillableModel;

class TestBillingModel extends BillableModel
{
    protected function hasPermission($permission, Model $requester)
    {
        return true;
    }

    public function stripeCustomerData()
    {
        return [
            'description' => 'TestBillingModel('.$this->id.')' ];
    }
}
