<?php

namespace GS\ToolboxBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class PrunePaymentCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            // the name of the command (the part after "bin/console")
            ->setName('gs:payment:prune')

            // the short description shown while running "php bin/console list"
            ->setDescription('Prune the payments.')

            // the full command description shown when running the command with
            // the "--help" option
            ->setHelp('Remove all the draft payment older than a day.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // outputs multiple lines to the console (adding "\n" at the end of each line)
        $output->writeln([
            'GS prune payments',
            '=================',
            '',
        ]);
        $em = $this->getContainer()->get('doctrine.orm.entity_manager');

        $payments = $em->getRepository('GSStructureBundle:Payment')->findDraftPaymentToPrune();

        foreach ( $payments as $payment ) {
            $em->remove($payment);
            $output->write('Delete payment for: ');
            $output->writeln($payment->getAccount()->getDisplayName());
        }
        $em->flush();

        $output->writeln([
            '=================',
            'Done',
        ]);
    }
}
