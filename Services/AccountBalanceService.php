<?php

namespace GS\ToolboxBundle\Services;

use Doctrine\ORM\EntityManager;

use GS\StructureBundle\Entity\Account;
use GS\StructureBundle\Entity\Activity;
use GS\StructureBundle\Entity\Category;
use GS\StructureBundle\Entity\Certificate;
use GS\StructureBundle\Entity\Discount;
use GS\StructureBundle\Entity\Payment;
use GS\StructureBundle\Entity\PaymentItem;
use GS\StructureBundle\Entity\Registration;
use GS\StructureBundle\Entity\Year;
use GS\ToolboxBundle\Services\MembershipService;

class AccountBalanceService
{
    /**
     * @var EntityManager
     */
    private $entityManager;

    /**
     * @var MembershipService
     */
    private $membershipService;

    public function __construct(EntityManager $entityManager, MembershipService $membershipService)
    {
        $this->entityManager = $entityManager;
        $this->membershipService = $membershipService;
    }

    public function getBalance(Account $account, Activity $activity = null)
    {
        $registrations = $this->getRegistrations($account, $activity);

        // Add the adhesion if it is missing (adhesion is mandatory but
        // it has been removed by the user)
        $newRegistration = null;
        foreach ($registrations as $registration) {
            $newRegistration = $this->membershipService->fulfillMembershipRegistration($registration);
            if ($newRegistration !== null) {
                $registrations[] = $newRegistration;
                break;
            }
        }

        if (count($registrations)) {
            $payment = new Payment();
            $payment->setType('CARD');
        } else {
            $payment = null;
        }

        $details = array();
        $totalBalance = 0.0;
        $i = 0;
        $currentActivity = null;
        $currentCategory = null;

        // Registrations are sorted by Category and Price.
        // All Discounts are linked to Category and they apply from the most
        // expensive Category to the less expensive one.
        foreach ($registrations as $registration) {
            $activity = $registration->getTopic()->getActivity();
            $category = $registration->getTopic()->getCategory();

            // When we change Category, we reset the index of the Registration
            // since some discount are based on the number of Topics having the
            // same Category.
            if ($currentCategory !== $category) {
                $currentCategory = $category;
                $i = 0;
            }

            // For a better display, we group registrations by activity.
            if ($currentActivity !== $activity) {
                $currentActivity = $activity;
                $i = 0;
            }

            // We append the name of the year for better display
            if ($activity->isMembership()) {
                $displayName = $activity->getTitle() . ' - ' .
                            $activity->getYear()->getTitle();
            } else {
                $displayName = $category->getName() . ' - ' .
                        $registration->getTopic()->getTitle();
            }

            $discounts = $category->getDiscounts();
            $discount = $this->chooseDiscount($i, $account, $category,
                $activity->getYear(), $discounts);

            $line = $this->getPriceToPay($registration, $category, $discount);
            $line['title'] = $displayName;
            $details[] = $line;
            $totalBalance += $line['balance'];

            if (null !== $payment) {
                $paymentItem = new PaymentItem();
                $paymentItem->setRegistration($registration);
                $paymentItem->setDiscount($discount);
                $payment->addItem($paymentItem);
            }

            $i++;
        }

        if (null !== $payment) {
            $this->entityManager->persist($payment);
            $this->entityManager->flush();
        }

        $balance = array(
            'details' => $details,
            'totalBalance' => $totalBalance,
            'payment' => $payment,
        );
        return $balance;
    }

    private function getRegistrations(Account $account, Activity $activity = null)
    {
        if (null === $activity ) {
            $registrations = $this->entityManager
                ->getRepository('GSStructureBundle:Registration')
                ->getValidatedRegistrationsForAccount($account);
        } else {
            $registrations = $this->entityManager
                ->getRepository('GSStructureBundle:Registration')
                ->getRegistrationsPaidOrValidatedForAccountAndActivity($account, $activity);
        }
        return $registrations;
    }

    private function getPriceToPay(Registration $registration, Category $category, Discount $discount = null)
    {
        $isSemester = $registration->getSemester();
        $coeff = $isSemester ? 0.5 : 1.0;
        $price = $coeff * $category->getPrice();
        $alreadyPaid = $registration->getAmountPaid();

        $line = array(
            'price' => $price,
            'alreadyPaid' => $alreadyPaid,
        );
        $due = $price;

        if (null !== $discount) {
            if($discount->getType() == 'percent') {
                $line['discount'] = '-' . $discount->getValue() . '%';
                $due *= 1 - $discount->getValue() / 100;
            } else {
                $line['discount'] = '-' . $coeff * $discount->getValue() . '&euro;';
                $due -= $coeff * $discount->getValue();
            }
        } else {
            $line['discount'] = '';
        }

        $line['balance'] = $due - $alreadyPaid;
        return $line;
    }

    private function getDiscountAmount (Category $category, Discount $discount)
    {
        $price = $category->getPrice();
        if($discount->getType() == 'percent') {
            return $price * $discount->getValue() / 100;
        } else {
            return $discount->getValue();
        }
    }

    private function chooseDiscount($i, Account $account, Category $category, Year $year, $discounts)
    {
        $maxAmount = 0;
        $result = null;

        foreach($discounts as $discount) {
            $amount = 0;
            if (($i >= 4 && $discount->getCondition() == '5th') ||
                    ($i >= 3 && $discount->getCondition() == '4th') ||
                    ($i >= 2 && $discount->getCondition() == '3rd') ||
                    ($i >= 1 && $discount->getCondition() == '2nd') ||
                    ($this->isStudent($account) && $discount->getCondition() == 'student') ||
                    ($this->isUnemployed($account) && $discount->getCondition() == 'unemployed') ||
                    ($this->membershipService->isAlmostMember($account, $year) && $discount->getCondition() == 'member')) {
                $amount = $this->getDiscountAmount($category, $discount);
            }

            if ($amount > $maxAmount) {
                $result = $discount;
                $maxAmount = $amount;
            }
        }
        return $result;
    }

    public function isStudent(Account $account)
    {
        if (null === $account ) {
            return false;
        }
        $certificate = $this->entityManager
            ->getRepository('GSStructureBundle:Certificate')
            ->getValidCertificate($account, Certificate::STUDENT);
        if (null === $certificate) {
            return false;
        }
        return true;
    }

    public function isUnemployed(Account $account)
    {
        if (null === $account ) {
            return false;
        }
        $certificate = $this->entityManager
            ->getRepository('GSStructureBundle:Certificate')
            ->getValidCertificate($account, Certificate::UNEMPLOYED);
        if (null === $certificate) {
            return false;
        }
        return true;
    }

}