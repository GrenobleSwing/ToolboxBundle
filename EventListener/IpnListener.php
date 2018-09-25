<?php

namespace GS\ToolboxBundle\EventListener;

use Doctrine\ORM\EntityManager;
use GS\ETransactionBundle\Entity\Environment;
use GS\ETransactionBundle\Entity\Payment as ETPayment;
use GS\ETransactionBundle\Event\IpnEvent;
use GS\StructureBundle\Entity\Invoice;
use GS\StructureBundle\Entity\Payment;
use GS\ToolboxBundle\Services\PaymentService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig_Environment;

class IpnListener
{
    /**
     * @var string
     */
    private $rootDir;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var EntityManager
     */
    private $entityManager;

    /**
     * @var PaymentService
     */
    private $ps;

    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @var UrlGeneratorInterface
     */
    private $router;

    /**
     * @var Twig_Environment
     */
    private $twig;

    /**
     * Constructor.
     *
     * @param string                $rootDir
     * @param Filesystem            $filesystem
     * @param EntityManager         $entityManager
     * @param PaymentService        $ps
     * @param ContainerInterface    $container
     * @param UrlGeneratorInterface $router
     * @param Twig_Environment      $twig
     */
    public function __construct($rootDir, Filesystem $filesystem, EntityManager $entityManager, PaymentService $ps,
            ContainerInterface $container, UrlGeneratorInterface $router, Twig_Environment $twig)
    {
        $this->rootDir = $rootDir;
        $this->filesystem = $filesystem;
        $this->entityManager = $entityManager;
        $this->ps = $ps;
        $this->container = $container;
        $this->router = $router;
        $this->twig = $twig;
    }

    private function verifyOrigin(IpnEvent $event, Environment $env)
    {
        // TODO: fix this function!
        return true;
        $serverIp = $event->getRemAddr();
        $validIps = $env->getValidIps();

        if(!in_array($serverIp, $validIps)) {
            return false;
        }
        return true;
    }

    /**
     * @param IpnEvent $event
     */
    public function onIpnReceived(IpnEvent $event)
    {
        $path = sprintf('%s/../data/%s', $this->rootDir, date('Y\/m\/d\/'));
        $this->filesystem->mkdir($path);
        $content = sprintf('Signature verification : %s%s', $event->isVerified() ? 'OK' : 'KO', PHP_EOL);
        foreach ($event->getData() as $key => $value) {
            $content .= sprintf("%s:%s%s", $key, $value, PHP_EOL);
        }
        $fileName = sprintf('%s%s.txt', $path, time());
        file_put_contents(
            $fileName,
            $content
        );

        if ( !$event->isVerified() ) {
            return;
        }

        $em = $this->entityManager;
        $data = $event->getData();

        $payment = $em
                ->getRepository('GSStructureBundle:Payment')
                ->findOneByRef($data['Ref']);

        if (null === $payment->getParent()) {
            $hasParent = false;
            $etranEnv = $payment->getItems()[0]
                        ->getRegistration()
                        ->getTopic()
                        ->getActivity()
                        ->getYear()
                        ->getSociety()
                        ->getPaymentEnvironment();
            $account = $payment->getAccount();
        } else {
            $hasParent = true;
            $etranEnv = $payment->getParent()->getItems()[0]
                        ->getRegistration()
                        ->getTopic()
                        ->getActivity()
                        ->getYear()
                        ->getSociety()
                        ->getPaymentEnvironment();
            $account = $payment->getParent()->getAccount();
        }

        if ( !$this->verifyOrigin($event, $etranEnv) ) {
            file_put_contents(
                    $fileName,
                    sprintf("Message not coming from the bank server!%s", PHP_EOL),
                    FILE_APPEND
            );

            return;
        }

        // ETAT_PBX:PBX_RECONDUCTION_ABT
        if ('00000' != $data['Erreur']) {
            // Handle failure of a payment in 2 or 3 times
            if (in_array('ETAT_PBX', $data) && $data['ETAT_PBX'] == 'PBX_RECONDUCTION_ABT') {
                $newPayment = new Payment();
                $newPayment->setAmount($payment->getRemainingAmount());
                $newPayment->setParent($payment);

                $transaction = new ETPayment();
                $transaction->setCmd($newPayment->getRef());
                $transaction->setEnvironment($etranEnv);
                $transaction->setPorteur($account->getEmail());
                $transaction->setTotal((int)($payment->getAmount() * 100));
                $transaction->setUrlAnnule($this->container->getParameter('return_url_cancelled'));
                $transaction->setUrlEffectue($this->container->getParameter('return_url_success'));
                $transaction->setUrlRefuse($this->container->getParameter('return_url_rejected'));
                $transaction->setIpnUrl($this->router->generateUrl('gs_etran_ipn', array(), UrlGeneratorInterface::ABSOLUTE_URL));

                $buttonHtml = $this->twig->render('GSToolboxBundle:Default:button.html.twig', array(
                    'transaction' => $transaction,
                ));

                $this->ps->sendEmailFailurePartialPayment($newPayment, $buttonHtml);

                $em->persist($newPayment);
                $em->flush();
            }

            return false;
        } else {
            $montant = (float)$data['Mt'] / 100;

            if (in_array('ETAT_PBX', $data) && $data['ETAT_PBX'] == 'PBX_RECONDUCTION_ABT') {
                $alreadyPaid = $payment->getAlreadyPaid();
            } else {
                $alreadyPaid = 0.0;
            }

            if (!$account->getPayments()->contains($payment)) {
                $account->addPayment($payment);
            }

            $alreadyPaid += $montant;
            $payment->setAlreadyPaid($alreadyPaid);

            if ($alreadyPaid >= $payment->getAmount()) {
                $payment->setState('PAID');

                if ($hasParent) {
                    $payment->getParent()->setState('PAID');
                    $invoicePayment = $payment->getParent();
                } else {
                    $invoicePayment = $payment;
                }

                $repo = $em->getRepository('GSStructureBundle:Invoice');
                if (null === $repo->findOneByPayment($invoicePayment)) {
                    $date = new \DateTime();
                    $prefix = $date->format('Y');
                    $invoiceNumber = $repo->countByNumber($prefix) + 1;
                    $invoice = new Invoice($invoicePayment);
                    $invoice->setNumber($prefix . sprintf('%05d', $invoiceNumber));
                    $invoice->setDate($date);

                    $this->ps->sendEmailSuccess($invoicePayment);

                    $em->persist($invoice);
                }

            } else {
                // This should never be reached in case of a child payment
                $payment->setState('IN_PRO');
            }
        }
        $em->flush();
    }
}
