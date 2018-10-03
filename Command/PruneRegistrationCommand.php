<?php

namespace GS\ToolboxBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PruneRegistrationCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            // the name of the command (the part after "bin/console")
            ->setName('gs:registrations:prune')

            // the short description shown while running "php bin/console list"
            ->setDescription('Prune the registrations.')

            // the full command description shown when running the command with
            // the "--help" option
            ->setHelp('Remove all the registrations that are submitted, waiting or validated.')

            ->addOption(
                'topic',
                null,
                InputOption::VALUE_REQUIRED,
                'Id of the topic to be cleaned?',
                false
            )

            ->addOption(
                'activity',
                null,
                InputOption::VALUE_REQUIRED,
                'Id of the activity to be cleaned?',
                false
            )

            ->addOption(
                'year',
                null,
                InputOption::VALUE_REQUIRED,
                'Id of the year to be cleaned?',
                false
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // outputs multiple lines to the console (adding "\n" at the end of each line)
        $output->writeln([
            'GS prune registrations',
            '======================',
        ]);
        $em = $this->getContainer()->get('doctrine.orm.entity_manager');

        $yearId = $input->getOption('year');
        if ($yearId !== null) {
            $year = $em->getRepository('GSStructureBundle:Year')->find($yearId);
            $this->getContainer()->get('gstoolbox.registration.service')->cleanYearRegistrations($year);
        }

        $activityId = $input->getOption('activity');
        if ($activityId !== null) {
            $activity = $em->getRepository('GSStructureBundle:Activity')->find($activityId);
            $this->getContainer()->get('gstoolbox.registration.service')->cleanActivityRegistrations($activity);
        }

        $topicId = $input->getOption('topic');
        if ($topicId !== null) {
            $topic = $em->getRepository('GSStructureBundle:Topic')->find($topicId);
            $this->getContainer()->get('gstoolbox.registration.service')->cleanTopicRegistrations($topic);
        }

        $em->flush();

        $output->writeln([
            'Done',
        ]);
    }
}
