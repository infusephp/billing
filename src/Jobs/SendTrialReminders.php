<?php

namespace App\Billing\Jobs;

use Infuse\HasApp;

class SendTrialReminders
{
    use HasApp;

    public function __invoke($run)
    {
        $modelClass = $this->app['config']->get('billing.model');

        list($m, $n) = $modelClass::sendTrialReminders();

        $run->writeOutput("Sent $m trial ending soon notifications");
        $run->writeOutput("Sent $n trial ended notifications");
    }
}
