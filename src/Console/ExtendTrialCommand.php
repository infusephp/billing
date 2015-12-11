<?php

/**
 * @author Jared King <j@jaredtking.com>
 *
 * @link http://jaredtking.com
 *
 * @copyright 2015 Jared King
 * @license MIT
 */
namespace App\Billing\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ExtendTrialCommand extends Command
{
    use \InjectApp;

    protected function configure()
    {
        $this
            ->setName('extend-trial')
            ->setDescription('Extends the free trial')
            ->addArgument(
                'member',
                InputArgument::REQUIRED,
                'Billable member ID to extend'
            )
            ->addArgument(
                'days',
                InputArgument::REQUIRED,
                '# of days to extend the trial for'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $id = $input->getArgument('member');
        $days = $input->getArgument('days');

        $modelClass = $this->app['config']->get('billing.model');
        $member = $modelClass::where('id', $id)->first();

        if (!$member) {
            $output->writeln("Member # $id not found");

            return 1;
        }

        if ($member->trial_ends <= 0) {
            $output->writeln("Member # $id is not currently trialing");

            return 1;
        }

        $member->trial_ends += $days * 86400;
        $member->save();

        $ends = date('M d, Y', $member->trial_ends);
        $output->writeln("Extended member # $id trial period by $days days to $ends");

        return 0;
    }
}
