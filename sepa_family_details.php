<?php
/*
Gibbon, Flexible & Open School System
Copyright (C) 2010, Ross Parker

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

use Gibbon\Tables\DataTable;
use Gibbon\Module\Sepa\Domain\SepaGateway;
use Gibbon\Module\Sepa\Domain\SepaPaymentAdjustmentGateway;
use Gibbon\Data\Validator;

// Module includes
require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_family_totals.php')) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    $_GET = $container->get(Validator::class)->sanitize($_GET);
    $_POST = $container->get(Validator::class)->sanitize($_POST);
    $gibbonFamilyID = isset($_GET['gibbonFamilyID']) ? $_GET['gibbonFamilyID'] : '';
    $schoolYearID = isset($_GET['schoolYearID']) ? $_GET['schoolYearID'] : $_SESSION[$guid]["gibbonSchoolYearID"];

    if (empty($gibbonFamilyID)) {
        $page->addError(__('Family ID is required.'));
        return;
    } else {
        $page->breadcrumbs->add(__('Family Totals'), '/sepa_family_totals.php');
        $page->breadcrumbs->add(__('Family Details'));

        $SepaGateway = $container->get(SepaGateway::class);
        $FamilySEPA = $SepaGateway->getFamilySEPA($gibbonFamilyID);
        $familyInfo = $SepaGateway->getFamilyInfo($gibbonFamilyID);


        echo '<h2>';
        echo __('Family Details');
        echo '</h2>';

        // Get family info
        if (!empty($familyInfo)) {
            echo '<h3>' . __('Family: ') . htmlspecialchars($familyInfo[0]['name'] ?? '', ENT_QUOTES, 'UTF-8') . '</h3>';
        }

        // Fees Summary
        $feesSummary = $SepaGateway->getFamilyFeesSummary($gibbonFamilyID, $schoolYearID);
        $SepaPaymentAdjustmentGateway = $container->get(SepaPaymentAdjustmentGateway::class);
        $totalAdjustments = $SepaPaymentAdjustmentGateway->getFamilyTotalAdjustments($gibbonFamilyID, $schoolYearID);
        $totalPayments = $SepaGateway->getFamilyTotalPayments($gibbonFamilyID, $schoolYearID);
        $totalFees = $feesSummary[0]['totalFees'] ?? 0;
        $balance = $totalPayments + $totalAdjustments - $totalFees;

        if (!empty($feesSummary)) {
            echo '<h4>' . __('Fees Summary') . '</h4>';
            echo '<p><strong>' . __('Total Fees Owed: ') . '</strong>' . htmlspecialchars($totalFees, ENT_QUOTES, 'UTF-8') . ' €</p>';
            echo '<p><strong>' . __('Total Adjustments: ') . '</strong>' . htmlspecialchars($totalAdjustments, ENT_QUOTES, 'UTF-8') . ' €</p>';
            echo '<p><strong>' . __('Total Payments: ') . '</strong>' . htmlspecialchars($totalPayments, ENT_QUOTES, 'UTF-8') . ' €</p>';
            echo '<p><strong> <span style="color: ' . ($balance < 0 ? 'red' : 'green') . ';"> ' . __('Balance: ') . htmlspecialchars($balance, ENT_QUOTES, 'UTF-8') . ' €</strong></span></p>';
        }

        if (empty($FamilySEPA)) {
            echo '<hr><span style="color: red;">' . __('SEPA informaiton is not available for this family.') . '</span><hr></p>';

        } elseif (count($FamilySEPA) > 1) {
            echo '<hr><span style="color: red;">' . __('Databased Error: more than one SEPA informaiton is entered for this family.') . '</span><hr></p>';
        }

        // Detailed Fees
        echo '<h4>' . __('Fees Details') . '</h4>';
        $detailedFees = $SepaGateway->getFamilyEnrollmentFees($gibbonFamilyID, $schoolYearID);
        $table = DataTable::create('feesDetails');

        $table->addColumn('totalCost', __('Total Cost'));
        $table->addColumn('monthsEnrolled', __('Months Enrolled'));
        $table->addColumn('courseFee', __('Fee'));
        $table->addColumn('childName', __('Child Name'));
        $table->addColumn('courseName', __('Course'));

        echo $table->render($detailedFees);

        // Adjustment Details
        if (!empty($FamilySEPA)) {
            $adjustmentEntries = $SepaPaymentAdjustmentGateway->getFamilyAdjustments($FamilySEPA[0]["gibbonSEPAID"], $schoolYearID );
            echo '<h4>' . __('Adjustment Details') . '</h4>';
            $table3 = DataTable::create('adjustmentDetails');

            $table3->addHeaderAction('add', __('Add Adjustment'))
                ->setURL('/modules/Sepa/sepa_payment_adjustment_add.php')
                ->addParam('gibbonSEPAID', $FamilySEPA[0]["gibbonSEPAID"])
                ->addParam('lockFamily', '1')
                ->addParam('family_details', $gibbonFamilyID)
                ->displayLabel();

            $table3->addColumn('amount', __('Adjustment Amount'));
            $table3->addColumn('description', __('Description'));
            $table3->addColumn('note', __('Note'));
            $table3->addColumn('timestamp', __('Timestamp'));
            $table3->addColumn('gibbonPersonID', __('user'));
            

            $table3->addActionColumn()
                ->addParam('gibbonSEPAPaymentAdjustmentID')
                ->addParam('lockFamily', '1')
                ->addParam('family_details', $gibbonFamilyID)
                ->format(function ($values, $actions) {
                    $actions->addAction('edit', __('Edit'))
                        ->setURL('/modules/Sepa/sepa_payment_adjustment_edit.php');

                    $actions->addAction('delete', __('Delete'))
                        ->setURL('/modules/Sepa/sepa_payment_adjustment_delete.php');
                });

            echo $table3->render($adjustmentEntries);



            // Payment Details
            echo '<h4>' . __('Payment Details') . '</h4>';
            $paymentEntries = $SepaGateway->getPaymentEntriesByFamily($FamilySEPA[0]["gibbonSEPAID"], $schoolYearID);

            $table2 = DataTable::create('paymentDetails');

            $table2->addHeaderAction('add', __('Add Payment'))
                ->setURL('/modules/Sepa/sepa_payment_add.php')
                ->addParam('gibbonSEPAID', $FamilySEPA[0]["gibbonSEPAID"])
                ->addParam('lockFamily', '1')
                ->addParam('family_details', $gibbonFamilyID)
                ->displayLabel();

            $table2->addColumn('amount', __('Amount'));
            $table2->addColumn('booking_date', __('Booking Date'));
            $table2->addColumn('payer', __('Payer'));
            $table2->addColumn('transaction_message', __('Transaction Message'));
            $table2->addColumn('IBAN', __('IBAN'));
            $table2->addColumn('transaction_reference', __('Transaction Reference'));
            $table2->addColumn('note', __('Note'));

            $table2->addActionColumn()
                ->addParam('gibbonSEPAPaymentRecordID')
                ->addParam('lockFamily', '1')
                ->addParam('family_details', $gibbonFamilyID)
                ->format(function ($values, $actions) {
                    $actions->addAction('edit', __('Edit'))
                        ->setURL('/modules/Sepa/sepa_payment_edit.php');
                    $actions->addAction('delete', __('Unlink'))
                        ->setURL('/modules/Sepa/sepa_payment_delete.php');
                });

            echo $table2->render($paymentEntries);

        }
    }
}
