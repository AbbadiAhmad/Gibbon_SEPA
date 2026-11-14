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
use Gibbon\Module\Sepa\Domain\SnapshotGateway;
use Gibbon\Module\Sepa\Domain\SepaPaymentAdjustmentGateway;
use Gibbon\Data\Validator;
use Gibbon\Forms\Form;


// Module includes
require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_balance_snapshot.php')) {
    // Access denied
    $page->addError(__m('You do not have access to this action.'));
} else {
    $page->breadcrumbs->add(__m('Balance Snapshot'));

    $_GET = $container->get(Validator::class)->sanitize($_GET);
    $_POST = $container->get(Validator::class)->sanitize($_POST);
    $schoolYearID = isset($_GET['schoolYearID']) ? $_GET['schoolYearID'] : $_SESSION[$guid]["gibbonSchoolYearID"];
    $selectedSnapshot = isset($_GET['snapshotDate']) ? $_GET['snapshotDate'] : 'current';
    $search = isset($_GET['search']) ? $_GET['search'] : '';

    $SepaGateway = $container->get(SepaGateway::class);
    $SnapshotGateway = $container->get(SnapshotGateway::class);
    $SepaPaymentAdjustmentGateway = $container->get(SepaPaymentAdjustmentGateway::class);

    echo '<h2>';
    echo __m('Balance Snapshot');
    echo '</h2>';

    // Get available snapshot dates
    $snapshotDates = $SnapshotGateway->getSnapshotDates($schoolYearID);
    $snapshotOptions = ['current' => __m('Current Status (Families with changes)')];

    foreach ($snapshotDates as $snapshot) {
        $date = \Gibbon\Services\Format::dateTime($snapshot['snapshotDate']);
        $snapshotOptions[$snapshot['snapshotDate']] = $date . ' (' . $snapshot['snapshotCount'] . ' families)';
    }

    // Combined search and snapshot selection form

    $form = Form::createSearch();


    $row = $form->addRow();
    $row->addLabel('snapshotDate', __m('Select Snapshot'));
    $row->addSelect('snapshotDate')
        ->fromArray($snapshotOptions)
        ->selected($selectedSnapshot);

    $row = $form->addRow();
    $row->addLabel('search', __m('Search For'))
        ->description(__m('Family Name, Payer Name'));
    $row->addTextField('search')->setValue($search);
    $form->addRow()->addSearchSubmit('', __m('Clear Search'));

    echo $form->getOutput();

    // Buttons for actions
    echo '<div class="linkTop">';
    if ($selectedSnapshot == 'current') {
        echo '<a href="' . $_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/Sepa/sepa_balance_snapshot_create.php&schoolYearID=' . $schoolYearID . '&search=' . urlencode($search) . '">' . __m('Create Snapshot') . '</a> | ';
    }
    echo '<a href="' . $_SESSION[$guid]['absoluteURL'] . '/modules/Sepa/sepa_balance_snapshot_export.php?schoolYearID=' . $schoolYearID . '&snapshotDate=' . urlencode($selectedSnapshot) . '">' . __m('Export to Excel') . '</a>';
    echo '</div>';

    echo '<h3>';
    if ($selectedSnapshot == 'current') {
        echo __m('Families with Changes in Fees or Adjustments Since Last Snapshot');
    } elseif (!empty($selectedSnapshot) && strtotime($selectedSnapshot) !== false) {
        echo __m('Snapshot from ') . \Gibbon\Services\Format::dateTime($selectedSnapshot);
    } else {
        echo __m('Invalid Snapshot');
    }
    echo '</h3>';


    if ($selectedSnapshot == 'current') {
        // Show families with changes in fees or adjustments since last snapshot
        // Get ALL families without pagination first
        $criteriaAll = $SepaGateway->newQueryCriteria(false)
            ->sortBy(['familyName'])
            ->fromPOST();
        $familyTotals = $SepaGateway->getFamilyTotals($schoolYearID, $criteriaAll, $search);

        // Get latest snapshots
        $latestSnapshots = $SnapshotGateway->getLatestSnapshotsByYear($schoolYearID);
        $snapshotsByFamily = [];
        foreach ($latestSnapshots as $snapshot) {
            $snapshotsByFamily[$snapshot['gibbonFamilyID']] = $snapshot;
        }

        // Filter to only show families with changes
        $familiesWithChanges = [];
        foreach ($familyTotals as $family) {
            $currentBalance = $family['balance'];
            // Calculate sum of owed fees and adjustments
            $currentFeesAndAdjustments = $family['totalDept'] + $family['paymentsAdjustment'];

            if (isset($snapshotsByFamily[$family['gibbonFamilyID']])) {
                $lastBalance = $snapshotsByFamily[$family['gibbonFamilyID']]['balance'];
                // Calculate sum of owed fees and adjustments from snapshot
                $lastTotalFees = $snapshotsByFamily[$family['gibbonFamilyID']]['totalFees'] ?? 0;
                $lastTotalAdjustments = $snapshotsByFamily[$family['gibbonFamilyID']]['totalAdjustments'] ?? 0;
                $lastFeesAndAdjustments = $lastTotalFees + $lastTotalAdjustments;

                // Only show if sum of fees and adjustments has changed
                if (abs($currentFeesAndAdjustments - $lastFeesAndAdjustments) > 0.01) {
                    $family['lastBalance'] = $lastBalance;
                    $family['balanceChange'] = $currentBalance - $lastBalance;
                    $family['lastTotalFees'] = $lastTotalFees;
                    $family['currentTotalFees'] = $family['totalDept'];
                    $family['lastTotalAdjustments'] = $lastTotalAdjustments;
                    $family['currentTotalAdjustments'] = $family['paymentsAdjustment'];
                    $family['lastFeesAndAdjustments'] = $lastFeesAndAdjustments;
                    $family['currentFeesAndAdjustments'] = $currentFeesAndAdjustments;
                    $familiesWithChanges[] = $family;
                }
            } else {
                // No previous snapshot, show all families
                $family['lastBalance'] = 0;
                $family['balanceChange'] = $currentBalance;
                $family['lastTotalFees'] = 0;
                $family['currentTotalFees'] = $family['totalDept'];
                $family['lastTotalAdjustments'] = 0;
                $family['currentTotalAdjustments'] = $family['paymentsAdjustment'];
                $family['lastFeesAndAdjustments'] = 0;
                $family['currentFeesAndAdjustments'] = $currentFeesAndAdjustments;
                $familiesWithChanges[] = $family;
            }
        }

        // Convert to data collection
        $data = new \Gibbon\Domain\DataSet($familiesWithChanges);
        $table = DataTable::create('balanceSnapshot');
    } else {
        // Show specific snapshot
        // Get the data to display
        $criteria = $SepaGateway->newQueryCriteria(true)
            ->sortBy(['familyName'])
            ->fromPOST();

        $data = $SnapshotGateway->getSnapshotsByDate($criteria, $selectedSnapshot, $schoolYearID, $search);
        $table = DataTable::createPaginated('balanceSnapshot', $criteria);
    }

    $table->addColumn('familyName', __m('Family Name'));
    $table->addColumn('payer', __m('Payer'));

    if ($selectedSnapshot == 'current') {
        $table->addColumn('lastBalance', __m('Last Balance (Snapshot)'))->format(function ($row) {
            return number_format($row['lastBalance'], 2) . ' €';
        });
        $table->addColumn('balance', __m('Current Balance'))->format(function ($row) {
            $balance = $row['balance'];
            $color = $balance < 0 ? 'red' : 'green';
            return '<span style="color: ' . $color . ';">' . number_format($balance, 2) . ' €</span>';
        });
        $table->addColumn('lastTotalFees', __m('Last Total Fees'))->format(function ($row) {
            return number_format($row['lastTotalFees'], 2) . ' €';
        });
        $table->addColumn('currentTotalFees', __m('Current Total Fees'))->format(function ($row) {
            return number_format($row['currentTotalFees'], 2) . ' €';
        });
        $table->addColumn('lastTotalAdjustments', __m('Last Adjustments'))->format(function ($row) {
            return number_format($row['lastTotalAdjustments'], 2) . ' €';
        });
        $table->addColumn('currentTotalAdjustments', __m('Current Adjustments'))->format(function ($row) {
            return number_format($row['currentTotalAdjustments'], 2) . ' €';
        });
        $table->addColumn('lastFeesAndAdjustments', __m('Last Fees+Adj Sum'))->format(function ($row) {
            return number_format($row['lastFeesAndAdjustments'], 2) . ' €';
        });
        $table->addColumn('currentFeesAndAdjustments', __m('Current Fees+Adj Sum'))->format(function ($row) {
            $value = $row['currentFeesAndAdjustments'];
            $color = $value < 0 ? 'red' : 'green';
            return '<span style="color: ' . $color . ';">' . number_format($value, 2) . ' €</span>';
        });
    } else {
        $table->addColumn('balance', __m('Balance'))->format(function ($row) {
            $balance = $row['balance'];
            $color = $balance < 0 ? 'red' : 'green';
            return '<span style="color: ' . $color . ';">' . number_format($balance, 2) . ' €</span>';
        });
        $table->addColumn('totalFees', __m('Total Fees'))->format(function ($row) {
            return number_format($row['totalFees'] ?? 0, 2) . ' €';
        });
        $table->addColumn('totalAdjustments', __m('Adjustments'))->format(function ($row) {
            return number_format($row['totalAdjustments'] ?? 0, 2) . ' €';
        });
        $table->addColumn('feesAndAdjustments', __m('Fees+Adj Sum'))->format(function ($row) {
            $fees = $row['totalFees'] ?? 0;
            $adjustments = $row['totalAdjustments'] ?? 0;
            $sum = $fees + $adjustments;
            $color = $sum < 0 ? 'red' : 'green';
            return '<span style="color: ' . $color . ';">' . number_format($sum, 2) . ' €</span>';
        });
    }

    $table->addActionColumn()
        ->addParam('schoolYearID', $schoolYearID)
        ->addParam('snapshotDate', $selectedSnapshot)
        ->addParam('search', $search)
        ->format(function ($row, $actions) use ($selectedSnapshot) {
            $actions->addAction('view', __m('View Details'))
                ->setURL('/modules/Sepa/sepa_balance_snapshot_details.php')
                ->addParam('gibbonFamilyID', $row['gibbonFamilyID'])
                ->addParam('snapshotID', isset($row['gibbonSEPABalanceSnapshotID']) ? $row['gibbonSEPABalanceSnapshotID'] : '');
        });

    echo $table->render($data);
}
