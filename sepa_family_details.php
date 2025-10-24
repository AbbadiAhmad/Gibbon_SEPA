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
use Gibbon\Data\Validator;

$_GET = $container->get(Validator::class)->sanitize($_GET);
$_POST = $container->get(Validator::class)->sanitize($_POST);

// Module includes
require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_payment_view.php')) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    $gibbonFamilyID = isset($_GET['gibbonFamilyID']) ? $_GET['gibbonFamilyID'] : '';
    $schoolYearID = isset($_GET['schoolYearID']) ? $_GET['schoolYearID'] : $_SESSION[$guid]["gibbonSchoolYearID"];

    if (empty($gibbonFamilyID)) {
        $page->addError(__('Family ID is required.'));
    } else {
        $page->breadcrumbs->add(__('Family Totals'), '/modules/Sepa/sepa_family_totals.php');
        $page->breadcrumbs->add(__('Family Details'));

        $SepaGateway = $container->get(SepaGateway::class);

        echo '<h2>';
        echo __('Family Details');
        echo '</h2>';

        // Get family info
        $familyInfo = $SepaGateway->getFamilyInfo($gibbonFamilyID);
        if (!empty($familyInfo)) {
            echo '<h3>' . __('Family: ') . htmlspecialchars($familyInfo[0]['name'] ?? '', ENT_QUOTES, 'UTF-8') . '</h3>';
        }

        // Fees Summary
        $feesSummary = $SepaGateway->getFamilyFeesSummary($gibbonFamilyID, $schoolYearID);
        if (!empty($feesSummary)) {
            echo '<h4>' . __('Fees Summary') . '</h4>';
            echo '<p><strong>' . __('Total Fees Owed: ') . '</strong>' . htmlspecialchars($feesSummary[0]['totalFees'] ?? 0, ENT_QUOTES, 'UTF-8') . ' €</p>';
        }

        // Detailed Fees
        $detailedFees = $SepaGateway->getFamilyDetailedFees($gibbonFamilyID, $schoolYearID);
        if (!empty($detailedFees)) {
            echo '<h4>' . __('Detailed Fees') . '</h4>';
            echo '<table class="fullWidth" cellspacing="0">';
            echo '<thead>';
            echo '<tr>';
            echo '<th>' . __('Child Name') . '</th>';
            echo '<th>' . __('Course Name') . '</th>';
            echo '<th>' . __('Course Fee') . '</th>';
            echo '<th>' . __('Months Enrolled') . '</th>';
            echo '<th>' . __('Total Cost') . '</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';
            foreach ($detailedFees as $fee) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars($fee['childName'], ENT_QUOTES, 'UTF-8') . '</td>';
                echo '<td>' . htmlspecialchars($fee['courseName'], ENT_QUOTES, 'UTF-8') . '</td>';
                echo '<td>' . htmlspecialchars($fee['courseFee'], ENT_QUOTES, 'UTF-8') . ' €</td>';
                echo '<td>' . htmlspecialchars($fee['monthsEnrolled'], ENT_QUOTES, 'UTF-8') . '</td>';
                echo '<td>' . htmlspecialchars($fee['totalCost'], ENT_QUOTES, 'UTF-8') . ' €</td>';
                echo '</tr>';
            }
            echo '</tbody>';
            echo '</table>';
        }

        // Payment Details
        echo '<h4>' . __('Payment Details') . '</h4>';
        $paymentEntries = $SepaGateway->getPaymentEntriesByFamily($gibbonFamilyID, $schoolYearID);

        $table2 = DataTable::create('paymentDetails');

        $table2->addColumn('booking_date', __('Booking Date'));
        $table2->addColumn('payer', __('Payer'));
        $table2->addColumn('amount', __('Amount'));
        $table2->addColumn('transaction_message', __('Transaction Message'));
        $table2->addColumn('IBAN', __('IBAN'));
        $table2->addColumn('transaction_reference', __('Transaction Reference'));
        $table2->addColumn('note', __('Note'));

        echo $table2->render($paymentEntries);
    }
}
