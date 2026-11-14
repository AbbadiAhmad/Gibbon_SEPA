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
use Gibbon\Forms\Form;
use Gibbon\Tables\DataTable;
use Gibbon\Module\Sepa\Domain\SepaGateway;
use Gibbon\Data\Validator;

// Module includes
require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_check.php')) {
    $page->addError(__m('You do not have access to this action.'));
} else {
    $page->breadcrumbs->add(__m('Check SEPA Data'));

    $_GET = $container->get(Validator::class)->sanitize($_GET);
    $SepaGateway = $container->get(SepaGateway::class);
    $schoolYearID = !empty($_GET['schoolYearID']) ? $_GET['schoolYearID'] : $_SESSION[$guid]['gibbonSchoolYearID'];
    $balanceFilter = ($_GET['balanceFilter'] ?? 'nonzero') === 'all' ? 'all' : 'nonzero';

    $form = Form::create('checkFilter', $session->get('absoluteURL') . '/index.php', 'get');
    $form->setClass('noIntBorder fullWidth');
    $form->addHiddenValue('q', '/modules/Sepa/sepa_check.php');

    $row = $form->addRow();
    $row->addLabel('schoolYearID', __m('Academic Year'))->description(__m('Families with enrolled students in this year must have a SEPA.'));
    $row->addSelect('schoolYearID')->fromArray($SepaGateway->getAllSchoolYears())->selected($schoolYearID);

    $row = $form->addRow();
    $row->addLabel('balanceFilter', __m('Check'))->description(__m('The balance is the balance of the selected academic year.'));
    $row->addSelect('balanceFilter')
        ->fromArray(['nonzero' => __m('Only SEPAs with a balance not zero'), 'all' => __m('All SEPAs')])
        ->selected($balanceFilter);

    // Column filters
    $allIssues = $SepaGateway->getSEPAIssues($schoolYearID, $balanceFilter === 'nonzero');
    $severity = in_array($_GET['severity'] ?? '', ['Error', 'Warning'], true) ? $_GET['severity'] : '';
    $problem = $_GET['problem'] ?? '';
    $search = $_GET['search'] ?? '';
    $problems = array_unique(array_column($allIssues, 'problem'));
    sort($problems);

    $row = $form->addRow();
    $row->addLabel('severity', __m('Type'));
    $row->addSelect('severity')->fromArray(['Error' => __m('Error'), 'Warning' => __m('Warning')])->selected($severity)->placeholder(__m('All'));

    $row = $form->addRow();
    $row->addLabel('problem', __m('Problem'));
    $row->addSelect('problem')->fromArray(array_combine($problems, $problems))->selected($problem)->placeholder(__m('All'));

    $row = $form->addRow();
    $row->addLabel('search', __m('Family / Payer'));
    $row->addTextField('search')->setValue($search);

    $row = $form->addRow();
    $row->addSubmit(__m('Check'));
    echo $form->getOutput();

    $issues = SepaGateway::filterSEPAIssues($allIssues, $severity, $problem, $search);

    $errors = count(array_filter($issues, function ($i) {
        return $i['severity'] === 'Error';
    }));
    echo sepaMissingFeesWarning($SepaGateway, $schoolYearID, $session->get('absoluteURL'));
    echo '<h2>' . __m('Check SEPA Data') . '</h2>';
    if (empty($issues)) {
        echo "<div class='success'>" . __m('No problems found.') . '</div>';
    } else {
        echo "<div class='" . ($errors ? 'error' : 'warning') . "'>" . sprintf(__m('%1$d errors and %2$d warnings found.'), $errors, count($issues) - $errors) . '</div>';

        $table = DataTable::create('sepaCheck');
        $table->addHeaderAction('export', __m('Export to Excel'))
            ->setURL('/modules/Sepa/sepa_check_export.php')
            ->addParam('schoolYearID', $schoolYearID)
            ->addParam('balanceFilter', $balanceFilter)
            ->addParam('severity', $severity)
            ->addParam('problem', $problem)
            ->addParam('search', $search)
            ->directLink()
            ->displayLabel();
        $table->addColumn('severity', __m('Type'))->format(function ($row) {
            return $row['severity'] === 'Error'
                ? '<span style="color: red; font-weight: bold;">' . __m('Error') . '</span>'
                : '<span style="color: #c60;">' . __m('Warning') . '</span>';
        });
        $table->addColumn('familyName', __m('Family'));
        $table->addColumn('payer', __m('Payer'));
        $table->addColumn('balance', __m('Balance'))->format(function ($row) {
            return number_format($row['balance'], 2);
        });
        $table->addColumn('problem', __m('Problem'));
        $canMergeSepa = isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_merge.php');
        $table->addActionColumn()->format(function ($row, $actions) use ($schoolYearID, $balanceFilter, $severity, $problem, $search, $canMergeSepa) {
            // Merge this SEPA with another SEPA (needs the Merge SEPA permission)
            if ($canMergeSepa && !empty($row['sepa'])) {
                $actions->addAction('merge', __m('Merge with other SEPA'))
                    ->setIcon('copy')
                    ->setURL('/modules/Sepa/sepa_merge.php')
                    ->addParam('gibbonSEPAID', $row['sepa']['gibbonSEPAID']);
            }
            $action = $actions->addAction($row['link'][0], $row['link'][0] === 'add' ? __m('Add SEPA') : __m('Edit'))
                ->setURL($row['link'][1]);
            foreach ($row['link'][2] as $name => $value) {
                $action->addParam($name, $value);
            }
            // come back to this page (same filters) after saving
            $action->addParam('returnTo', 'check')
                ->addParam('schoolYearID', $schoolYearID)
                ->addParam('balanceFilter', $balanceFilter)
                ->addParam('severity', $severity)
                ->addParam('problem', $problem)
                ->addParam('search', $search);
        });

        echo $table->render($issues);
    }
}
