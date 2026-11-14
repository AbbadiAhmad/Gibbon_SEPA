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
use Gibbon\Module\Sepa\Security\IbanCipher;
use Gibbon\Module\Sepa\Domain\SepaPaymentAdjustmentGateway;
use Gibbon\Data\Validator;

// Module includes
require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_family_totals.php')) {
    // Access denied
    $page->addError(__m('You do not have access to this action.'));
} else {
    $_GET = $container->get(Validator::class)->sanitize($_GET);
    $_POST = $container->get(Validator::class)->sanitize($_POST);
    $gibbonFamilyID = isset($_GET['gibbonFamilyID']) ? $_GET['gibbonFamilyID'] : '';
    $schoolYearID = isset($_GET['schoolYearID']) ? $_GET['schoolYearID'] : $_SESSION[$guid]["gibbonSchoolYearID"];

    if (empty($gibbonFamilyID)) {
        $page->addError(__m('Family ID is required.'));
        return;
    } else {
        $page->breadcrumbs->add(__m('Family Totals'), '/sepa_family_totals.php');
        $page->breadcrumbs->add(__m('Family Details'));

        $SepaGateway = $container->get(SepaGateway::class);
        $FamilySEPA = $SepaGateway->getFamilySEPA($gibbonFamilyID);
        $familyInfo = $SepaGateway->getFamilyInfo($gibbonFamilyID);

        // Get academic year dates for payment report button
        $academicYearData = $SepaGateway->getSchoolYearByID($schoolYearID);


        echo sepaMissingFeesWarning($SepaGateway, $schoolYearID, $session->get('absoluteURL'), $gibbonFamilyID);

        echo '<h2>';
        echo __m('Family Details');
        echo '</h2>';

        // Get family info
        if (!empty($familyInfo)) {
            echo '<h3>' . __m('Family: ') . htmlspecialchars($familyInfo[0]['name'] ?? '', ENT_QUOTES, 'UTF-8') . '</h3>';
        }

        // Fees Summary
        $feesSummary = $SepaGateway->getFamilyFeesSummary($gibbonFamilyID, $schoolYearID);
        $SepaPaymentAdjustmentGateway = $container->get(SepaPaymentAdjustmentGateway::class);
        $totalAdjustments = $SepaPaymentAdjustmentGateway->getFamilyTotalAdjustments($gibbonFamilyID, $schoolYearID);
        $totalPayments = $SepaGateway->getFamilyTotalPayments($gibbonFamilyID, $schoolYearID);
        $totalFees = $feesSummary[0]['totalFees'] ?? 0;
        $balance = $totalPayments + $totalAdjustments - $totalFees;

        if (!empty($feesSummary)) {
            echo '<h4>' . __m('Fees Summary') . '</h4>';
            echo '<p><strong>' . __m('Total Fees Owed: ') . '</strong>' . htmlspecialchars($totalFees, ENT_QUOTES, 'UTF-8') . ' €</p>';
            echo '<p><strong>' . __m('Total Adjustments: ') . '</strong>' . htmlspecialchars($totalAdjustments, ENT_QUOTES, 'UTF-8') . ' €</p>';
            echo '<p><strong>' . __m('Total Payments: ') . '</strong>' . htmlspecialchars($totalPayments, ENT_QUOTES, 'UTF-8') . ' €</p>';
            echo '<p><strong> <span style="color: ' . ($balance < 0 ? 'red' : 'green') . ';"> ' . __m('Balance: ') . htmlspecialchars($balance, ENT_QUOTES, 'UTF-8') . ' €</strong></span></p>';
        }

        if (empty($FamilySEPA)) {
            echo '<hr><span style="color: red;">' . __m('SEPA informaiton is not available for this family.') . '</span><hr></p>';

        } elseif (count($FamilySEPA) > 1) {
            echo '<hr><span style="color: red;">' . __m('Databased Error: more than one SEPA informaiton is entered for this family.') . '</span><hr></p>';
        }

        // Detailed Fees
        echo '<h4>' . __m('Fees Details') . '</h4>';
        $detailedFees = $SepaGateway->getFamilyEnrollmentFees($gibbonFamilyID, $schoolYearID);
        $table = DataTable::create('feesDetails');

        $table->addColumn('totalCost', __m('Total Cost'));
        $table->addColumn('monthsEnrolled', __m('Months Enrolled'));
        $table->addColumn('courseFee', __m('Fee'));
        $table->addColumn('childName', __m('Child Name'))->format(function ($row) {
            $name = htmlspecialchars($row['childName'], ENT_QUOTES, 'UTF-8');
            if (!empty($row['personDateEnd'])) {
                $name .= ' <span style="color: red;">(Left on ' . htmlspecialchars($row['personDateEnd'], ENT_QUOTES, 'UTF-8') . ')</span>';
            }
            return $name;
        });
        $table->addColumn('courseName', __m('Course'));

        // Only show edit action for admin users
        if ($session->get('gibbonRoleIDCurrentCategory') === 'Staff' &&
            isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_enrollment_edit.php')) {
            $table->addActionColumn()
                ->addParam('gibbonPersonID')
                ->addParam('gibbonCourseClassID')
                ->addParam('gibbonFamilyID')
                ->format(function ($values, $actions) {
                    $actions->addAction('edit', __m('Edit'))
                        ->setURL('/modules/Sepa/sepa_enrollment_edit.php');
                });
        }

        echo $table->render($detailedFees);

        // Adjustment Details
        if (!empty($FamilySEPA)) {
            $adjustmentEntries = $SepaPaymentAdjustmentGateway->getFamilyAdjustments($FamilySEPA[0]["gibbonSEPAID"], $schoolYearID );
            echo '<h4>' . __m('Adjustment Details') . '</h4>';
            $table3 = DataTable::create('adjustmentDetails');

            $table3->addHeaderAction('add', __m('Add Adjustment'))
                ->setURL('/modules/Sepa/sepa_payment_adjustment_add.php')
                ->addParam('gibbonSEPAID', $FamilySEPA[0]["gibbonSEPAID"])
                ->addParam('lockFamily', '1')
                ->addParam('family_details', $gibbonFamilyID)
                ->displayLabel();

            $table3->addColumn('amount', __m('Adjustment Amount'));
            $table3->addColumn('description', __m('Description'));
            $table3->addColumn('note', __m('Note'));
            $table3->addColumn('timestamp', __m('Timestamp'));
            $table3->addColumn('gibbonPersonID', __m('user'));
            

            $table3->addActionColumn()
                ->addParam('gibbonSEPAPaymentAdjustmentID')
                ->addParam('lockFamily', '1')
                ->addParam('family_details', $gibbonFamilyID)
                ->format(function ($values, $actions) {
                    $actions->addAction('edit', __m('Edit'))
                        ->setURL('/modules/Sepa/sepa_payment_adjustment_edit.php');

                    $actions->addAction('delete', __m('Delete'))
                        ->setURL('/modules/Sepa/sepa_payment_adjustment_delete.php');
                });

            echo $table3->render($adjustmentEntries);



            // Payment Details
            echo '<h4>' . __m('Payment Details') . '</h4>';
            $paymentEntries = $SepaGateway->getPaymentEntriesByFamily($FamilySEPA[0]["gibbonSEPAID"], $schoolYearID);

            $table2 = DataTable::create('paymentDetails');

            $table2->addHeaderAction('add', __m('Add Payment'))
                ->setURL('/modules/Sepa/sepa_payment_add.php')
                ->addParam('gibbonSEPAID', $FamilySEPA[0]["gibbonSEPAID"])
                ->addParam('lockFamily', '1')
                ->addParam('family_details', $gibbonFamilyID)
                ->displayLabel();

            // Add Payment Report button with academic year dates pre-filled
            if (!empty($academicYearData)) {
                $table2->addHeaderAction('report', __m('Payment Report'))
                    ->setURL('/modules/Sepa/sepa_payment_report.php')
                    ->addParam('gibbonSEPAID', $FamilySEPA[0]["gibbonSEPAID"])
                    ->addParam('fromDate', $academicYearData['firstDay'])
                    ->addParam('toDate', $academicYearData['lastDay'])
                    ->displayLabel();
            }

            $table2->addColumn('amount', __m('Amount'));
            $table2->addColumn('booking_date', __m('Booking Date'));
            $table2->addColumn('payer', __m('Payer'));
            $table2->addColumn('transaction_message', __m('Transaction Message'));
            $table2->addColumn('IBAN', __m('IBAN'))->format(function ($row) {
                return htmlspecialchars(IbanCipher::format(IbanCipher::decryptForDisplay($row['IBAN'])), ENT_QUOTES, 'UTF-8');
            });
            $table2->addColumn('transaction_reference', __m('Transaction Reference'));
            $table2->addColumn('note', __m('Note'));

            $table2->addActionColumn()
                ->addParam('gibbonSEPAPaymentRecordID')
                ->addParam('lockFamily', '1')
                ->addParam('family_details', $gibbonFamilyID)
                ->format(function ($values, $actions) {
                    $actions->addAction('edit', __m('Edit'))
                        ->setURL('/modules/Sepa/sepa_payment_edit.php');
                    $actions->addAction('delete', __m('Unlink'))
                        ->setURL('/modules/Sepa/sepa_payment_delete.php');
                });

            echo $table2->render($paymentEntries);

        }
    }
}
