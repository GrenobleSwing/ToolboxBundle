<?php

namespace GS\ToolboxBundle\Services;

use Doctrine\ORM\EntityManager;

use GS\StructureBundle\Entity\Account;
use GS\StructureBundle\Entity\Registration;
use GS\StructureBundle\Entity\Year;

class MembershipService
{
    private $entityManager;

    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function isAlmostMember(Account $account, Year $year)
    {
        $registrations = $this->entityManager
            ->getRepository('GSStructureBundle:Registration')
            ->getMembershipRegistrationsForAccountAndYear($account, $year);

        foreach ($registrations as $registration) {
            if ($registration->getState() == 'VALIDATED') {
                return true;
            }
        }
        return false;
    }

    public function isTeacher(Account $account, Year $year)
    {
        $user = $account->getUser();
        foreach ($year->getTeachers() as $teacher) {
            if ($user === $teacher) {
                return true;
            }
        }
        return false;
    }

    public function isMember(Account $account, Year $year)
    {
        if ( $this->isTeacher($account, $year) ) {
            return true;
        }

        $registrations = $this->entityManager
            ->getRepository('GSStructureBundle:Registration')
            ->getMembershipRegistrationsForAccountAndYear($account, $year);

        foreach ($registrations as $registration) {
            if ($registration->getState() == 'PAID' ||$registration->getState() == 'PAYMENT_IN_PROGRESS') {
                return true;
            }
        }
        return false;
    }

    public function getMembers(Year $year, $onlyPaid = true)
    {
        $registrations = $this->entityManager
            ->getRepository('GSStructureBundle:Registration')
            ->getMembershipRegistrationsForYear($year);

        $accounts = [];
        foreach ($registrations as $registration) {
            if ($registration->getState() == 'PAID') {
                $accounts[] = $registration->getAccount();
            } elseif (!$onlyPaid && $registration->getState() == 'VALIDATED') {
                $accounts[] = $registration->getAccount();
            }
        }

        foreach ($year->getTeachers() as $teacher) {
            $account = $this->entityManager
                    ->getRepository('GSStructureBundle:Account')
                    ->findOneByUser($teacher);
            $accounts[] = $account;
        }

        return $accounts;
    }

    // Check if the membership is mandatory for the Registration
    // and do the needed work in case it is.
    // Return the created Registration if the adhesion was missing, null otherwise
    public function fulfillMembershipRegistration (Registration $registration) {
        $topic = $registration->getTopic();
        $account = $registration->getAccount();
        $activity = $topic->getActivity();
        $year = $activity->getYear();

        if ($activity->getMembersOnly() &&
                !($this->isMember($account, $year) ||
                $this->isAlmostMember($account, $year)) &&
                null !== $activity->getMembershipTopic()) {
            $membership = new Registration();
            $membership->setAccount($account);
            $membership->setTopic($activity->getMembershipTopic());
            $membership->setAcceptRules($registration->getAcceptRules());
            $membership->validate();
            $this->entityManager->persist($membership);
            return $membership;
        }
        return null;
    }

}
