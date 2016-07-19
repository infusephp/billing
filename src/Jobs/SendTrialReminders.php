<?php

namespace App\Billing\Jobs;

use Infuse\HasApp;

class SendTrialReminders
{
    use HasApp;

    public function __invoke($run)
    {
        list($m, $n) = $this->sendTrialReminders();

        $run->writeOutput("Sent $m trial ending soon notifications");
        $run->writeOutput("Sent $n trial ended notifications");
    }

    /**
     * Gets members with trials that are ending soon but not notified yet.
     *
     * @return \Pulsar\Iterator
     */
    public function getTrialsEndingSoon()
    {
        $modelClass = $this->app['config']->get('billing.model');

        $days = $this->app['config']->get('billing.trialWillEndReminderDays');
        // reminder window is valid for up to 1 day
        $end = time() + $days * 86400;
        $start = $end - 86400;

        return $modelClass::where('canceled', false)
            ->where('trial_ends', $start, '>=')
            ->where('trial_ends', $end, '<=')
            ->where('last_trial_reminder IS NULL')
            ->all();
    }

    /**
     * Gets members with trials that have ended but not notified yet.
     *
     * @return \Pulsar\Iterator
     */
    public function getEndedTrials()
    {
        $modelClass = $this->app['config']->get('billing.model');

        return $modelClass::where('canceled', false)
            ->where('trial_ends', 0, '>')
            ->where('trial_ends', time(), '<')
            ->where('renews_next', 0)
            ->where('(last_trial_reminder < trial_ends OR last_trial_reminder IS NULL)')
            ->all();
    }

    /**
     * Sends out trial reminders - trial_will_end and trial_ended.
     *
     * @return array [sent ending soon notices, sent ended notices]
     */
    public function sendTrialReminders()
    {
        return [
            self::sendTrialWillEndReminders(),
            self::sendTrialEndedReminders(),
        ];
    }

    private function sendTrialWillEndReminders()
    {
        $config = $this->app['config'];
        if (!$config->get('billing.emails.trial_will_end')) {
            return 0;
        }

        $members = $this->getTrialsEndingSoon();
        $n = 0;
        $subject = 'Your trial ends soon on '.$config->get('app.title');
        foreach ($members as $member) {
            $member->sendEmail(
                'trial-will-end', [
                    'subject' => $subject,
                    'tags' => ['billing', 'trial-will-end'], ]);

            $member->last_trial_reminder = time();
            $member->grantAllPermissions()->save();

            ++$n;
        }

        return $n;
    }

    private function sendTrialEndedReminders()
    {
        $config = $this->app['config'];
        if (!$config->get('billing.emails.trial_ended')) {
            return 0;
        }

        $members = $this->getEndedTrials();
        $n = 0;
        $subject = 'Your '.$config->get('app.title').' trial has ended';
        foreach ($members as $member) {
            $member->sendEmail(
                'trial-ended', [
                    'subject' => $subject,
                    'tags' => ['billing', 'trial-ended'], ]);

            $member->last_trial_reminder = time();
            $member->grantAllPermissions()->save();

            ++$n;
        }

        return $n;
    }
}
