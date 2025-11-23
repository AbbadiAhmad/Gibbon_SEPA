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

$_GET = $container->get(Validator::class)->sanitize($_GET);
$_POST = $container->get(Validator::class)->sanitize($_POST);

// Module includes
require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_balance_snapshot.php')) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    $page->breadcrumbs->add(__('Balance Snapshot'));

    $schoolYearID = isset($_GET['schoolYearID']) ? $_GET['schoolYearID'] : $_SESSION[$guid]["gibbonSchoolYearID"];
    $selectedSnapshot = isset($_GET['snapshotDate']) ? $_GET['snapshotDate'] : 'current';

    $SepaGateway = $container->get(SepaGateway::class);
    $SnapshotGateway = $container->get(SnapshotGateway::class);
    $SepaPaymentAdjustmentGateway = $container->get(SepaPaymentAdjustmentGateway::class);

    echo '<h2>';
    echo __('Balance Snapshot');
    echo '</h2>';

    // Get available snapshot dates
    $snapshotDates = $SnapshotGateway->getSnapshotDates($schoolYearID);
    $snapshotOptions = ['current' => __('Current Status (Families with changes)')];

    foreach ($snapshotDates as $snapshot) {
        $date = date('Y-m-d H:i', strtotime($snapshot['snapshotDate']));
        $snapshotOptions[$snapshot['snapshotDate']] = $date . ' (' . $snapshot['snapshotCount'] . ' families)';
    }

    // Snapshot selection form
    $form = Form::create('snapshotSelect', $_SESSION[$guid]['absoluteURL'] . '/index.php', 'get');
    $form->setClass('noIntBorder fullWidth');

    $form->addHiddenValue('q', '/modules/Sepa/sepa_balance_snapshot.php');
    $form->addHiddenValue('schoolYearID', $schoolYearID);

    $row = $form->addRow();
    $row->addLabel('snapshotDate', __('Select Snapshot'));
    $row->addSelect('snapshotDate')
        ->fromArray($snapshotOptions)
        ->selected($selectedSnapshot)
        ->placeholder(__('Select a snapshot'));

    $row = $form->addRow();
    $row->addSubmit(__('View'));

    echo $form->getOutput();

    // Button to create new snapshot
    if ($selectedSnapshot == 'current') {
        echo '<div class="linkTop">';
        echo '<a href="' . $_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/Sepa/sepa_balance_snapshot_create.php&schoolYearID=' . $schoolYearID . '">' . __('Create Snapshot') . '</a>';
        echo '</div>';
    }

    echo '<h3>';
    if ($selectedSnapshot == 'current') {
        echo __('Families with Balance Changes Since Last Snapshot');
    } else {
        echo __('Snapshot from ') . date('Y-m-d H:i', strtotime($selectedSnapshot));
    }
    echo '</h3>';

    // Get the data to display
    $criteria = $SepaGateway->newQueryCriteria(true)
        ->searchBy(['familyName'])
        ->fromPOST();

    if ($selectedSnapshot == 'current') {
        // Show families with balance changes since last snapshot
        $familyTotals = $SepaGateway->getFamilyTotals($schoolYearID, $criteria);

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

            if (isset($snapshotsByFamily[$family['gibbonFamilyID']])) {
                $lastBalance = $snapshotsByFamily[$family['gibbonFamilyID']]['balance'];
                // Only show if balance has changed
                if (abs($currentBalance - $lastBalance) > 0.01) {
                    $family['lastBalance'] = $lastBalance;
                    $family['balanceChange'] = $currentBalance - $lastBalance;
                    $familiesWithChanges[] = $family;
                }
            } else {
                // No previous snapshot, show all families
                $family['lastBalance'] = 0;
                $family['balanceChange'] = $currentBalance;
                $familiesWithChanges[] = $family;
            }
        }

        // Convert to data collection
        $data = new \Gibbon\Domain\DataSet($familiesWithChanges);
    } else {
        // Show specific snapshot - need to parse JSON data for display
        $snapshotsRaw = $SnapshotGateway->getSnapshotsByDate($criteria, $selectedSnapshot, $schoolYearID);

        // Parse JSON data and add calculated fields
        $snapshotsProcessed = [];
        foreach ($snapshotsRaw as $snapshot) {
            $snapshotData = json_decode($snapshot['snapshotData'], true);
            $snapshot['totalFees'] = $snapshotData['balance']['totalFees'] ?? 0;
            $snapshot['totalPayments'] = $snapshotData['balance']['totalPayments'] ?? 0;
            $snapshot['totalAdjustments'] = $snapshotData['balance']['totalAdjustments'] ?? 0;
            $snapshotsProcessed[] = $snapshot;
        }

        $data = new \Gibbon\Domain\DataSet($snapshotsProcessed);
    }

    $table = DataTable::createPaginated('balanceSnapshot', $criteria);

    $table->addColumn('familyName', __('Family Name'));
    $table->addColumn('payer', __('Payer'));

    if ($selectedSnapshot == 'current') {
        $table->addColumn('lastBalance', __('Last Balance (Snapshot)'))->format(function ($row) {
            return number_format($row['lastBalance'], 2) . ' €';
        });
        $table->addColumn('balance', __('Current Balance'))->format(function ($row) {
            $balance = $row['balance'];
            $color = $balance < 0 ? 'red' : 'green';
            return '<span style="color: ' . $color . ';">' . number_format($balance, 2) . ' €</span>';
        });
    } else {
        $table->addColumn('balance', __('Balance'))->format(function ($row) {
            $balance = $row['balance'];
            $color = $balance < 0 ? 'red' : 'green';
            return '<span style="color: ' . $color . ';">' . number_format($balance, 2) . ' €</span>';
        });
    }

    $table->addActionColumn()
        ->addParam('schoolYearID', $schoolYearID)
        ->addParam('snapshotDate', $selectedSnapshot)
        ->format(function ($row, $actions) use ($selectedSnapshot) {
            $actions->addAction('view', __('View Details'))
                ->setURL('/modules/Sepa/sepa_balance_snapshot_details.php')
                ->addParam('gibbonFamilyID', $row['gibbonFamilyID'])
                ->addParam('snapshotID', isset($row['gibbonSEPABalanceSnapshotID']) ? $row['gibbonSEPABalanceSnapshotID'] : '');
        });

    echo $table->render($data);
}
