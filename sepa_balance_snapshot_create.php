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

use Gibbon\Module\Sepa\Domain\SepaGateway;
use Gibbon\Module\Sepa\Domain\SnapshotGateway;
use Gibbon\Module\Sepa\Domain\SepaPaymentAdjustmentGateway;
use Gibbon\Data\Validator;
use Gibbon\Forms\Form;
use Gibbon\Forms\DatabaseFormFactory;

$_GET = $container->get(Validator::class)->sanitize($_GET);
$_POST = $container->get(Validator::class)->sanitize($_POST);

// Module includes
require_once __DIR__ . '/moduleFunctions.php';

$schoolYearID = isset($_GET['schoolYearID']) ? $_GET['schoolYearID'] : $_SESSION[$guid]["gibbonSchoolYearID"];
$search = isset($_GET['search']) ? $_GET['search'] : '';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_balance_snapshot.php')) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    $page->breadcrumbs
        ->add(__('Balance Snapshot'), 'sepa_balance_snapshot.php')
        ->add(__('Create Snapshot'));

    $SepaGateway = $container->get(SepaGateway::class);
    $SnapshotGateway = $container->get(SnapshotGateway::class);
    $SepaPaymentAdjustmentGateway = $container->get(SepaPaymentAdjustmentGateway::class);

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        // Process snapshot creation
        $snapshotDate = date('Y-m-d H:i:s');
        $gibbonPersonID = $_SESSION[$guid]['gibbonPersonID'];

        // Get all families with balance changes
        $criteria = $SepaGateway->newQueryCriteria(false);
        $familyTotals = $SepaGateway->getFamilyTotals($schoolYearID, $criteria);

        // Get latest snapshots
        $latestSnapshots = $SnapshotGateway->getLatestSnapshotsByYear($schoolYearID);
        $snapshotsByFamily = [];
        foreach ($latestSnapshots as $snapshot) {
            $snapshotsByFamily[$snapshot['gibbonFamilyID']] = $snapshot;
        }

        $successCount = 0;
        $errorCount = 0;

        foreach ($familyTotals as $family) {
            $gibbonFamilyID = $family['gibbonFamilyID'];
            $currentBalance = $family['balance'];

            // Check if balance has changed
            $hasChanged = true;
            if (isset($snapshotsByFamily[$gibbonFamilyID])) {
                $lastBalance = $snapshotsByFamily[$gibbonFamilyID]['balance'];
                $hasChanged = abs($currentBalance - $lastBalance) > 0.01;
            }

            if (!$hasChanged) {
                continue; // Skip families with no changes
            }

            // Get detailed data for this family
            $FamilySEPA = $SepaGateway->getFamilySEPA($gibbonFamilyID);
            $gibbonSEPAID = !empty($FamilySEPA) ? $FamilySEPA[0]['gibbonSEPAID'] : null;

            // Get fees details
            $feesDetails = $SepaGateway->getFamilyEnrollmentFees($gibbonFamilyID, $schoolYearID);
            $feesSummary = $SepaGateway->getFamilyFeesSummary($gibbonFamilyID, $schoolYearID);

            // Get payment entries
            $payments = [];
            if ($gibbonSEPAID) {
                $paymentEntries = $SepaGateway->getPaymentEntriesByFamily($gibbonSEPAID, $schoolYearID);
                foreach ($paymentEntries as $payment) {
                    $payments[] = [
                        'gibbonSEPAPaymentRecordID' => $payment['gibbonSEPAPaymentRecordID'],
                        'booking_date' => $payment['booking_date'],
                        'amount' => $payment['amount'],
                        'payer' => $payment['payer'],
                        'IBAN' => $payment['IBAN'],
                        'transaction_reference' => $payment['transaction_reference'],
                        'transaction_message' => $payment['transaction_message'],
                        'payment_method' => $payment['payment_method'],
                        'note' => $payment['note'],
                        'timestamp' => $payment['timestamp']
                    ];
                }
            }

            // Get adjustment entries
            $adjustments = [];
            if ($gibbonSEPAID) {
                $adjustmentEntries = $SepaPaymentAdjustmentGateway->getFamilyAdjustments($gibbonSEPAID, $schoolYearID);
                foreach ($adjustmentEntries as $adjustment) {
                    $adjustments[] = [
                        'gibbonSEPAPaymentAdjustmentID' => $adjustment['gibbonSEPAPaymentAdjustmentID'],
                        'amount' => $adjustment['amount'],
                        'description' => $adjustment['description'],
                        'note' => $adjustment['note'],
                        'gibbonPersonID' => $adjustment['gibbonPersonID'],
                        'timestamp' => $adjustment['timestamp']
                    ];
                }
            }

            // Get SEPA info
            $sepaInfo = [];
            if (!empty($FamilySEPA)) {
                $sepaInfo = [
                    'gibbonSEPAID' => $FamilySEPA[0]['gibbonSEPAID'],
                    'payer' => $FamilySEPA[0]['payer'],
                    'IBAN' => $FamilySEPA[0]['IBAN'],
                    'BIC' => $FamilySEPA[0]['BIC'],
                    'SEPA_signedDate' => $FamilySEPA[0]['SEPA_signedDate']
                ];
            }

            // Prepare snapshot data
            $totalFees = $feesSummary[0]['totalFees'] ?? 0;
            $totalPayments = $SepaGateway->getFamilyTotalPayments($gibbonFamilyID, $schoolYearID);
            $totalAdjustments = $SepaPaymentAdjustmentGateway->getFamilyTotalAdjustments($gibbonFamilyID, $schoolYearID);
            $balance = $totalPayments + $totalAdjustments - $totalFees;

            $snapshotData = [
                'gibbonFamilyID' => $gibbonFamilyID,
                'schoolYearID' => $schoolYearID,
                'familyName' => $family['familyName'],
                'snapshotDate' => $snapshotDate,
                'snapshotTimestamp' => time(),
                'sepaInfo' => $sepaInfo,
                'balance' => [
                    'totalFees' => $totalFees,
                    'totalPayments' => $totalPayments,
                    'totalAdjustments' => $totalAdjustments,
                    'balance' => $balance,
                    'currency' => 'EUR'
                ],
                'fees' => is_array($feesDetails) ? $feesDetails : $feesDetails->toArray(),
                'payments' => $payments,
                'adjustments' => $adjustments
            ];

            // Insert snapshot
            $data = [
                'gibbonFamilyID' => $gibbonFamilyID,
                'gibbonSEPAID' => $gibbonSEPAID,
                'academicYear' => $schoolYearID,
                'snapshotDate' => $snapshotDate,
                'balance' => $balance,
                'snapshotData' => json_encode($snapshotData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                'gibbonPersonID' => $gibbonPersonID
            ];

            $inserted = $SnapshotGateway->insertSnapshot($data);
            if ($inserted) {
                $successCount++;
            } else {
                $errorCount++;
            }
        }

        // Redirect with success message
        if ($successCount > 0) {
            $page->addMessage(__('Snapshot created successfully for {count} families.', ['count' => $successCount]));
        }
        if ($errorCount > 0) {
            $page->addError(__('Failed to create snapshot for {count} families.', ['count' => $errorCount]));
        }

        echo '<div class="linkTop">';
        echo '<a href="' . $_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/Sepa/sepa_balance_snapshot.php&schoolYearID=' . $schoolYearID . '&search=' . urlencode($search) . '">' . __('Back to Snapshots') . '</a>';
        echo '</div>';
    } else {
        // Display confirmation form
        echo '<h2>';
        echo __('Create Balance Snapshot');
        echo '</h2>';

        // Get families with balance changes
        $criteria = $SepaGateway->newQueryCriteria(false);
        $familyTotals = $SepaGateway->getFamilyTotals($schoolYearID, $criteria);

        // Get latest snapshots
        $latestSnapshots = $SnapshotGateway->getLatestSnapshotsByYear($schoolYearID);
        $snapshotsByFamily = [];
        foreach ($latestSnapshots as $snapshot) {
            $snapshotsByFamily[$snapshot['gibbonFamilyID']] = $snapshot;
        }

        // Count families with changes
        $familiesWithChanges = 0;
        foreach ($familyTotals as $family) {
            $currentBalance = $family['balance'];

            if (isset($snapshotsByFamily[$family['gibbonFamilyID']])) {
                $lastBalance = $snapshotsByFamily[$family['gibbonFamilyID']]['balance'];
                if (abs($currentBalance - $lastBalance) > 0.01) {
                    $familiesWithChanges++;
                }
            } else {
                $familiesWithChanges++;
            }
        }

        if ($familiesWithChanges == 0) {
            echo '<div class="message">';
            echo __('No families with balance changes found.');
            echo '</div>';

            echo '<div class="linkTop">';
            echo '<a href="' . $_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/Sepa/sepa_balance_snapshot.php&schoolYearID=' . $schoolYearID . '">' . __('Back to Snapshots') . '</a>';
            echo '</div>';
        } else {
            echo '<p>';
            echo __('This will create a snapshot for {count} families with balance changes since the last snapshot.', ['count' => $familiesWithChanges]);
            echo '</p>';

            echo '<p>';
            echo __('The snapshot will record the current balance, fees, payments, and adjustments for each family.');
            echo '</p>';

            $form = Form::create('snapshotCreate', $_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/Sepa/sepa_balance_snapshot_create.php&schoolYearID=' . $schoolYearID);
            $form->addHiddenValue('address', $_SESSION[$guid]['address']);

            $row = $form->addRow();
            $row->addLabel('info', __('Families to snapshot'))->description(__('Number of families'));
            $row->addTextField('info')->readonly()->setValue($familiesWithChanges);

            $row = $form->addRow();
            $row->addFooter();
            $row->addSubmit(__('Create Snapshot'));

            echo $form->getOutput();
        }
    }
}
