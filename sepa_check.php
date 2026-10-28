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
    $page->addError(__('You do not have access to this action.'));
} else {
    $page->breadcrumbs->add(__('Check SEPA Data'));

    $_GET = $container->get(Validator::class)->sanitize($_GET);
    $SepaGateway = $container->get(SepaGateway::class);
    $schoolYearID = !empty($_GET['schoolYearID']) ? $_GET['schoolYearID'] : $_SESSION[$guid]['gibbonSchoolYearID'];
    $balanceFilter = ($_GET['balanceFilter'] ?? 'nonzero') === 'all' ? 'all' : 'nonzero';

    $form = Form::create('checkFilter', $session->get('absoluteURL') . '/index.php', 'get');
    $form->setClass('noIntBorder fullWidth');
    $form->addHiddenValue('q', '/modules/Sepa/sepa_check.php');

    $row = $form->addRow();
    $row->addLabel('schoolYearID', __('Academic Year'))->description(__('Families with enrolled students in this year must have a SEPA.'));
    $row->addSelect('schoolYearID')->fromArray($SepaGateway->getAllSchoolYears())->selected($schoolYearID);

    $row = $form->addRow();
    $row->addLabel('balanceFilter', __('Check'))->description(__('The balance is the balance of the selected academic year.'));
    $row->addSelect('balanceFilter')
        ->fromArray(['nonzero' => __('Only SEPAs with a balance not zero'), 'all' => __('All SEPAs')])
        ->selected($balanceFilter);

    $row = $form->addRow();
    $row->addSubmit(__('Check'));
    echo $form->getOutput();

    $issues = $SepaGateway->getSEPAIssues($schoolYearID, $balanceFilter === 'nonzero');

    $errors = count(array_filter($issues, function ($i) {
        return $i['severity'] === 'Error';
    }));
    echo '<h2>' . __('Check SEPA Data') . '</h2>';
    if (empty($issues)) {
        echo "<div class='success'>" . __('No problems found.') . '</div>';
    } else {
        echo "<div class='" . ($errors ? 'error' : 'warning') . "'>" . sprintf(__('%1$d errors and %2$d warnings found.'), $errors, count($issues) - $errors) . '</div>';

        $table = DataTable::create('sepaCheck');
        $table->addHeaderAction('export', __('Export to Excel'))
            ->setURL('/modules/Sepa/sepa_check_export.php')
            ->addParam('schoolYearID', $schoolYearID)
            ->addParam('balanceFilter', $balanceFilter)
            ->directLink()
            ->displayLabel();
        $table->addColumn('severity', __('Type'))->format(function ($row) {
            return $row['severity'] === 'Error'
                ? '<span style="color: red; font-weight: bold;">' . __('Error') . '</span>'
                : '<span style="color: #c60;">' . __('Warning') . '</span>';
        });
        $table->addColumn('familyName', __('Family'));
        $table->addColumn('payer', __('Payer'));
        $table->addColumn('balance', __('Balance'))->format(function ($row) {
            return number_format($row['balance'], 2);
        });
        $table->addColumn('problem', __('Problem'));
        $table->addActionColumn()->format(function ($row, $actions) {
            $action = $actions->addAction($row['link'][0], $row['link'][0] === 'add' ? __('Add SEPA') : __('Edit'))
                ->setURL($row['link'][1]);
            foreach ($row['link'][2] as $name => $value) {
                $action->addParam($name, $value);
            }
        });

        echo $table->render($issues);
    }
}
