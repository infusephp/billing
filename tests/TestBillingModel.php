<?php

use App\Billing\Models\BillableModel;
use Pulsar\Model;

class TestBillingModel extends BillableModel
{
    public $lastEmail;

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
}
