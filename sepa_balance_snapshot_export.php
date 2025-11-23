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
use Gibbon\Tables\Renderer\SpreadsheetRenderer;
use Gibbon\Module\Sepa\Domain\SepaGateway;
use Gibbon\Module\Sepa\Domain\SnapshotGateway;
use Gibbon\Module\Sepa\Domain\SepaPaymentAdjustmentGateway;

require_once __DIR__ . '/../../gibbon.php';

// Module includes
require_once __DIR__ . '/moduleFunctions.php';
require_once __DIR__ . '/src/Domain/SepaGateway.php';
require_once __DIR__ . '/src/Domain/SnapshotGateway.php';
require_once __DIR__ . '/src/Domain/SepaPaymentAdjustmentGateway.php';

if (isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_balance_snapshot.php') == false) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    $schoolYearID = isset($_GET['schoolYearID']) ? $_GET['schoolYearID'] : $_SESSION[$guid]["gibbonSchoolYearID"];
    $selectedSnapshot = isset($_GET['snapshotDate']) ? $_GET['snapshotDate'] : 'current';

    // Manually instantiate gateway classes
    $SepaGateway = new SepaGateway($pdo);
    $SnapshotGateway = new SnapshotGateway($pdo);
    $SepaPaymentAdjustmentGateway = new SepaPaymentAdjustmentGateway($pdo);

    $exportData = [];

    if ($selectedSnapshot == 'current') {
        // Export current status vs last snapshot
        $criteriaAll = $SepaGateway->newQueryCriteria(false);
        $familyTotals = $SepaGateway->getFamilyTotals($schoolYearID, $criteriaAll);

        // Get latest snapshots
        $latestSnapshots = $SnapshotGateway->getLatestSnapshotsByYear($schoolYearID);
        $snapshotsByFamily = [];
        foreach ($latestSnapshots as $snapshot) {
            $snapshotsByFamily[$snapshot['gibbonFamilyID']] = $snapshot;
        }

        // Build export data
        foreach ($familyTotals as $family) {
            $currentBalance = $family['balance'];
            $lastBalance = 0;

            if (isset($snapshotsByFamily[$family['gibbonFamilyID']])) {
                $lastBalance = $snapshotsByFamily[$family['gibbonFamilyID']]['balance'];
            }

            // Only include families with changes
            if (abs($currentBalance - $lastBalance) > 0.01 || !isset($snapshotsByFamily[$family['gibbonFamilyID']])) {
                // Get SEPA details
                $FamilySEPA = $SepaGateway->getFamilySEPA($family['gibbonFamilyID']);
                $sepaInfo = !empty($FamilySEPA) ? $FamilySEPA[0] : null;

                $exportData[] = [
                    'Family Name' => $family['familyName'],
                    'School Year ID' => $schoolYearID,
                    'SEPA ID' => $sepaInfo['gibbonSEPAID'] ?? '',
                    'Payer' => $sepaInfo['payer'] ?? '',
                    'IBAN' => isset($sepaInfo['IBAN']) ? str_replace(' ', '', $sepaInfo['IBAN']) : '',
                    'SEPA Signed Date' => $sepaInfo['SEPA_signedDate'] ?? '',
                    'Old Balance' => number_format($lastBalance, 2, '.', ''),
                    'New Balance' => number_format($currentBalance, 2, '.', '')
                ];
            }
        }
    } else {
        // Export specific snapshot vs previous snapshot
        $criteria = $SepaGateway->newQueryCriteria(false);
        $currentSnapshots = $SnapshotGateway->getSnapshotsByDate($criteria, $selectedSnapshot, $schoolYearID);

        foreach ($currentSnapshots as $snapshot) {
            $currentBalance = $snapshot['balance'];

            // Get previous snapshot for this family
            $previousSnapshot = null;
            $allSnapshots = $SnapshotGateway->getSnapshotsByFamily($snapshot['gibbonFamilyID'], $schoolYearID);
            $foundCurrent = false;

            foreach ($allSnapshots as $snap) {
                if ($foundCurrent) {
                    $previousSnapshot = $snap;
                    break;
                }
                if ($snap['snapshotDate'] == $selectedSnapshot) {
                    $foundCurrent = true;
                }
            }

            $lastBalance = $previousSnapshot ? $previousSnapshot['balance'] : 0;

            $exportData[] = [
                'Family Name' => $snapshot['familyName'],
                'School Year ID' => $snapshot['academicYear'],
                'SEPA ID' => $snapshot['gibbonSEPAID'] ?? '',
                'Payer' => $snapshot['payer'] ?? '',
                'IBAN' => isset($snapshot['IBAN']) ? str_replace(' ', '', $snapshot['IBAN']) : '',
                'SEPA Signed Date' => $snapshot['snapshotData'] ? (json_decode($snapshot['snapshotData'], true)['sepaInfo']['SEPA_signedDate'] ?? '') : '',
                'Old Balance' => number_format($lastBalance, 2, '.', ''),
                'New Balance' => number_format($currentBalance, 2, '.', '')
            ];
        }
    }

    if (empty($exportData)) {
        $exportData = [['Error' => 'No data available for export']];
    }

    $renderer = new SpreadsheetRenderer();
    $table = DataTable::create('balanceSnapshotExport', $renderer);
    $table->setTitle('Balance Snapshot');

    $filename = 'balance_snapshot_' . date('Y-m-d_H-i-s') . '.xlsx';

    // Set document properties
    $table->addMetaData('creator', 'Gibbon')
        ->addMetaData('filename', $filename);

    foreach ($exportData[0] as $colName => $value) {
        $table->addColumn($colName, $colName);
    }

    echo $table->render($exportData);
}
