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
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

use Gibbon\Services\Format;
use Gibbon\Forms\Form;
use Gibbon\Tables\DataTable;
use Gibbon\Module\Sepa\Domain\IssuesGateway;
use Gibbon\Module\Sepa\Domain\SepaGateway;
use Gibbon\Data\Validator;

$_GET = $container->get(Validator::class)->sanitize($_GET);
$_POST = $container->get(Validator::class)->sanitize($_POST);

// Module includes
require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_issues_view.php')) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    $page->breadcrumbs->add(__('Payment & Data Issues Dashboard'));

    $issuesGateway = $container->get(IssuesGateway::class);
    $sepaGateway = $container->get(SepaGateway::class);

    // Get current academic year (use session or default to current)
    $schoolYearID = $_SESSION[$guid]['gibbonSchoolYearID'] ?? null;

    if (!$schoolYearID) {
        $page->addError(__('No academic year selected.'));
        return;
    }

    // Get academic year info for display
    $yearInfo = $issuesGateway->getAcademicYearMonths($schoolYearID);

    // Get settings
    $oldDateThreshold = (int) $issuesGateway->getIssueSetting('sepa_old_date_threshold_years');
    $lowBalanceThreshold = (float) $issuesGateway->getIssueSetting('balance_method_attribute');
    $highBalanceThreshold = (float) $issuesGateway->getIssueSetting('balance_method_more_than_attribute');
    $balanceMethod = $issuesGateway->getIssueSetting('balance_method_less_than');

    // Get issue counts for summary
    $issueSummary = $issuesGateway->getIssueSummary($schoolYearID);

    // Display settings info
    echo '<div class="message" style="background-color: #f0f0f0; border-left: 4px solid #007BFF; padding: 12px; margin-bottom: 20px;">';
    echo '<h4 style="margin-top: 0;">' . __('Detection Settings') . '</h4>';
    echo '<p><strong>' . __('Academic Year Progress:') . '</strong> ' .
         sprintf(__('Month %d of %d (%.0f%% through year)'), $yearInfo['currentMonth'], $yearInfo['totalMonths'], $yearInfo['proportion'] * 100) . '</p>';
    echo '<p><strong>' . __('Old SEPA Threshold:') . '</strong> ' . $oldDateThreshold . ' ' . __('years') . '</p>';
    echo '<p><strong>' . __('Low Balance Threshold:') . '</strong> ' . $lowBalanceThreshold . ' (' . $balanceMethod . ')</p>';
    echo '<p><strong>' . __('High Balance Threshold:') . '</strong> ' . $highBalanceThreshold . ' ' . __('euros') . '</p>';
    echo '<div class="linkTop">';
    echo '<a class="button" href="' . $_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/Sepa/sepa_issues_settings.php">';
    echo __('Configure Settings');
    echo '</a>';
    echo '</div>';
    echo '</div>';

    // Display issue summary
    echo '<div class="message" style="background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 12px; margin-bottom: 20px;">';
    echo '<h4 style="margin-top: 0;">' . __('Issues Summary') . '</h4>';
    echo '<ul style="margin: 0; padding-left: 20px;">';
    echo '<li><strong>' . __('Similar IBANs:') . '</strong> ' . $issueSummary['similar_ibans'] . '</li>';
    echo '<li><strong>' . __('Similar Payers:') . '</strong> ' . $issueSummary['similar_payers'] . '</li>';
    echo '<li><strong>' . __('Old SEPA Dates:') . '</strong> ' . $issueSummary['old_sepa_dates'] . '</li>';
    echo '<li><strong>' . __('Families Without SEPA:') . '</strong> ' . $issueSummary['families_without_sepa'] . '</li>';
    echo '<li><strong>' . __('Low Balances:') . '</strong> ' . $issueSummary['low_balances'] . '</li>';
    echo '<li><strong>' . __('High Balances (Overpayment):') . '</strong> ' . $issueSummary['high_balances'] . '</li>';
    echo '</ul>';
    echo '</div>';

    // ========================================
    // ISSUE 1: Similar IBANs
    // ========================================
    if ($issueSummary['similar_ibans'] > 0) {
        echo '<h3 style="color: #dc3545;">🔴 ' . __('Similar IBANs') . ' (' . $issueSummary['similar_ibans'] . ')</h3>';
        echo '<p>' . __('Multiple families using the same masked IBAN (potential duplicates or shared accounts)') . '</p>';

        $criteria = $issuesGateway->newQueryCriteria(true)->pageSize(50);
        $similarIBANs = $issuesGateway->getSimilarIBANs($criteria);

        $table = DataTable::createPaginated('SimilarIBANs', $criteria);
        $table->addColumn('IBAN', __('IBAN'));
        $table->addColumn('familyCount', __('Family Count'));
        $table->addColumn('payers', __('Payers'))->width('30%');
        $table->addColumn('families', __('Families'))->width('30%');

        $table->addActionColumn()
            ->addParam('sepaIDs')
            ->addParam('familyIDs')
            ->format(function ($row, $actions) {
                $familyIDs = explode(',', $row['familyIDs']);
                if (!empty($familyIDs) && isset($familyIDs[0])) {
                    $actions->addAction('View', __('View Family'))
                        ->setIcon('zoom')
                        ->setURL('/modules/Sepa/sepa_family_details.php')
                        ->addParam('gibbonFamilyID', $familyIDs[0]);
                }
            });

        echo $table->render($similarIBANs);
    }

    // ========================================
    // ISSUE 2: Similar Payers
    // ========================================
    if ($issueSummary['similar_payers'] > 0) {
        echo '<h3 style="color: #ffc107;">⚠️ ' . __('Similar Payer Names') . ' (' . $issueSummary['similar_payers'] . ')</h3>';
        echo '<p>' . __('Payer names that sound similar (phonetic matching) - potential duplicates or typos') . '</p>';

        $criteria = $issuesGateway->newQueryCriteria(true)->pageSize(50);
        $similarPayers = $issuesGateway->getSimilarPayers($criteria);

        $table = DataTable::createPaginated('SimilarPayers', $criteria);
        $table->addColumn('payerVariations', __('Payer Name Variations'))->width('30%');
        $table->addColumn('recordCount', __('Record Count'));
        $table->addColumn('ibans', __('IBANs'))->width('25%');
        $table->addColumn('families', __('Families'))->width('25%');

        $table->addActionColumn()
            ->addParam('sepaIDs')
            ->addParam('familyIDs')
            ->format(function ($row, $actions) use ($guid) {
                $sepaIDs = explode(',', $row['sepaIDs']);
                $familyIDs = explode(',', $row['familyIDs']);

                if (!empty($familyIDs) && isset($familyIDs[0])) {
                    $actions->addAction('View', __('View Family'))
                        ->setIcon('zoom')
                        ->setURL('/modules/Sepa/sepa_family_details.php')
                        ->addParam('gibbonFamilyID', $familyIDs[0]);
                }

                if (!empty($sepaIDs) && isset($sepaIDs[0])) {
                    $actions->addAction('Edit', __('Edit SEPA'))
                        ->setIcon('config')
                        ->setURL('/modules/Sepa/sepa_family_edit.php')
                        ->addParam('gibbonSEPAID', $sepaIDs[0]);
                }
            });

        echo $table->render($similarPayers);
    }

    // ========================================
    // ISSUE 3: Old SEPA Dates
    // ========================================
    if ($issueSummary['old_sepa_dates'] > 0) {
        echo '<h3 style="color: #ffc107;">⚠️ ' . __('Old SEPA Authorization Dates') . ' (' . $issueSummary['old_sepa_dates'] . ')</h3>';
        echo '<p>' . sprintf(__('SEPA authorizations older than %d years (may need renewal)'), $oldDateThreshold) . '</p>';

        $criteria = $issuesGateway->newQueryCriteria(true)->pageSize(50);
        $oldSEPAs = $issuesGateway->getOldSEPADates($criteria, $oldDateThreshold);

        $table = DataTable::createPaginated('OldSEPADates', $criteria);
        $table->addColumn('familyName', __('Family'));
        $table->addColumn('payer', __('Payer'));
        $table->addColumn('SEPA_signedDate', __('Signed Date'))
            ->format(function ($row) {
                return Format::date($row['SEPA_signedDate']);
            });
        $table->addColumn('ageYears', __('Age (Years)'));

        $table->addActionColumn()
            ->addParam('gibbonFamilyID')
            ->addParam('gibbonSEPAID')
            ->format(function ($row, $actions) use ($guid) {
                $actions->addAction('View', __('View Family'))
                    ->setIcon('zoom')
                    ->setURL('/modules/Sepa/sepa_family_details.php');

                $actions->addAction('Edit', __('Edit SEPA'))
                    ->setIcon('config')
                    ->setURL('/modules/Sepa/sepa_family_edit.php');
            });

        echo $table->render($oldSEPAs);
    }

    // ========================================
    // ISSUE 4: Families Without SEPA
    // ========================================
    if ($issueSummary['families_without_sepa'] > 0) {
        echo '<h3 style="color: #17a2b8;">🔵 ' . __('Families Without SEPA') . ' (' . $issueSummary['families_without_sepa'] . ')</h3>';
        echo '<p>' . __('Families with enrolled students but no bank details on file') . '</p>';

        $criteria = $issuesGateway->newQueryCriteria(true)->pageSize(50);
        $familiesWithoutSEPA = $issuesGateway->getFamiliesWithoutSEPA($criteria, $schoolYearID);

        $table = DataTable::createPaginated('FamiliesWithoutSEPA', $criteria);
        $table->addColumn('familyName', __('Family'));
        $table->addColumn('studentCount', __('Students'));
        $table->addColumn('students', __('Student Names'))->width('40%');
        $table->addColumn('status', __('Status'));

        $table->addActionColumn()
            ->addParam('gibbonFamilyID')
            ->format(function ($row, $actions) use ($guid) {
                $actions->addAction('View', __('View Family'))
                    ->setIcon('zoom')
                    ->setURL('/modules/Sepa/sepa_family_details.php');

                $actions->addAction('Add', __('Add SEPA'))
                    ->setIcon('page_new')
                    ->setURL('/modules/Sepa/sepa_family_add.php');
            });

        echo $table->render($familiesWithoutSEPA);
    }

    // ========================================
    // ISSUE 5: Low Balances (Underpayment)
    // ========================================
    if ($issueSummary['low_balances'] > 0) {
        echo '<h3 style="color: #ffc107;">⚠️ ' . __('Low Balances (Underpayment)') . ' (' . $issueSummary['low_balances'] . ')</h3>';
        echo '<p>' . sprintf(__('Families with payments below expected amount (threshold: %.2f, method: %s)'), $lowBalanceThreshold, $balanceMethod) . '</p>';

        $criteria = $issuesGateway->newQueryCriteria(true)->pageSize(50);
        $lowBalances = $issuesGateway->getLowBalanceFamilies($criteria, $schoolYearID, $lowBalanceThreshold, $balanceMethod);

        $table = DataTable::createPaginated('LowBalances', $criteria);
        $table->addColumn('familyName', __('Family'));
        $table->addColumn('payer', __('Payer'));
        $table->addColumn('actualPaid', __('Paid'))
            ->format(function ($row) {
                return Format::currency($row['actualPaid'], 'EUR');
            });
        $table->addColumn('expectedNow', __('Expected'))
            ->format(function ($row) {
                return Format::currency($row['expectedNow'], 'EUR');
            });
        $table->addColumn('shortfall', __('Shortfall'))
            ->format(function ($row) {
                return '<span style="color: #dc3545; font-weight: bold;">' .
                       Format::currency($row['shortfall'], 'EUR') . '</span>';
            });
        $table->addColumn('totalFees', __('Total Fees'))
            ->format(function ($row) {
                return Format::currency($row['totalFees'], 'EUR');
            });

        $table->addActionColumn()
            ->addParam('gibbonFamilyID')
            ->addParam('gibbonSEPAID')
            ->format(function ($row, $actions) use ($guid) {
                $actions->addAction('View', __('View Family Balance'))
                    ->setIcon('zoom')
                    ->setURL('/modules/Sepa/sepa_family_details.php');

                $actions->addAction('Edit', __('Edit SEPA'))
                    ->setIcon('config')
                    ->setURL('/modules/Sepa/sepa_family_edit.php');
            });

        echo $table->render($lowBalances);
    }

    // ========================================
    // ISSUE 6: High Balances (Overpayment)
    // ========================================
    if ($issueSummary['high_balances'] > 0) {
        echo '<h3 style="color: #28a745;">✅ ' . __('High Balances (Overpayment)') . ' (' . $issueSummary['high_balances'] . ')</h3>';
        echo '<p>' . sprintf(__('Families with positive balance above %.2f euros (overpaid)'), $highBalanceThreshold) . '</p>';

        $criteria = $issuesGateway->newQueryCriteria(true)->pageSize(50);
        $highBalances = $issuesGateway->getHighBalanceFamilies($criteria, $schoolYearID, $highBalanceThreshold);

        $table = DataTable::createPaginated('HighBalances', $criteria);
        $table->addColumn('familyName', __('Family'));
        $table->addColumn('payer', __('Payer'));
        $table->addColumn('balance', __('Balance'))
            ->format(function ($row) {
                return '<span style="color: #28a745; font-weight: bold;">' .
                       Format::currency($row['balance'], 'EUR') . '</span>';
            });
        $table->addColumn('totalFees', __('Expected'))
            ->format(function ($row) {
                return Format::currency($row['totalFees'], 'EUR');
            });
        $table->addColumn('excess', __('Excess'))
            ->format(function ($row) {
                return Format::currency($row['excess'], 'EUR');
            });

        $table->addActionColumn()
            ->addParam('gibbonFamilyID')
            ->addParam('gibbonSEPAID')
            ->format(function ($row, $actions) use ($guid) {
                $actions->addAction('View', __('View Family Balance'))
                    ->setIcon('zoom')
                    ->setURL('/modules/Sepa/sepa_family_details.php');

                $actions->addAction('Edit', __('Edit SEPA'))
                    ->setIcon('config')
                    ->setURL('/modules/Sepa/sepa_family_edit.php');
            });

        echo $table->render($highBalances);
    }

    // Display message if no issues found
    if (array_sum($issueSummary) == 0) {
        echo '<div class="message" style="background-color: #d4edda; border-left: 4px solid #28a745; padding: 12px;">';
        echo '<h4 style="margin-top: 0; color: #155724;">✅ ' . __('All Clear!') . '</h4>';
        echo '<p style="margin-bottom: 0;">' . __('No issues detected in the system. All SEPA records and payments are in good standing.') . '</p>';
        echo '</div>';
    }
}
