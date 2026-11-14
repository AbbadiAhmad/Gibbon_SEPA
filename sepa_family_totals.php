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

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_family_totals.php')) {
    // Access denied
    $page->addError(__m('You do not have access to this action.'));
} else {
    $page->breadcrumbs->add(__m('Family Totals'));

    $_GET = $container->get(Validator::class)->sanitize($_GET);
    $_POST = $container->get(Validator::class)->sanitize($_POST);

    $schoolYearID = isset($_GET['schoolYearID']) ? $_GET['schoolYearID'] : $_SESSION[$guid]["gibbonSchoolYearID"];
    $search = isset($_GET['search']) ? $_GET['search'] : '';

    $SepaGateway = $container->get(SepaGateway::class);

    $criteria = $SepaGateway->newQueryCriteria(true)
        ->sortBy(['balance'])
        ->fromPOST();

    $criteria->addFilterRules([
        'search' => function ($query, $search) {
            if (!empty($search)) {
                return $query->where('(gibbonFamily.name LIKE :search OR gibbonSEPA.payer LIKE :search)')
                    ->bindValue('search', '%' . $search . '%');
            }
            return $query;
        }
    ]);

    if (!empty($search)) {
        $criteria->filterBy('search', $search);
    }

    $form = Form::createSearch();

    $row = $form->addRow();
    $row->addLabel('search', __m('Search For'))
        ->description(__m('Family Name, Payer'));
    $row->addTextField('search')->setValue($search);

    $form->addRow()->addSearchSubmit('', __m('Clear Search'));

    echo $form->getOutput();

    echo sepaMissingFeesWarning($SepaGateway, $schoolYearID, $session->get('absoluteURL'));

    echo '<h2>';
    echo __m('Family Totals');
    echo '</h2>';

    $familyTotals = $SepaGateway->getFamilyTotals($schoolYearID, $criteria);

    $table = DataTable::createPaginated('familyTotals', $criteria);

    //$table->addMetaData('filterOptions', ['unpaidNoSepa' => __m('Unpaid without SEPA')]);

    //$table->addColumn('gibbonFamilyID', __m('Family ID'));
    $table->addHeaderAction('due', __m('Due in Period'))
        ->setURL('/modules/Sepa/sepa_family_due.php')
        ->addParam('schoolYearID', $schoolYearID)
        ->displayLabel();

    $table->addHeaderAction('export', __m('Export to Excel'))
        ->setURL('/modules/Sepa/sepa_family_balance_export.php')
        ->addParam('schoolYearID', $schoolYearID)
        ->addParam('search', $search)
        ->directLink()
        ->displayLabel();

    $table->addColumn('familyName', __m('Family Name'));
    $table->addColumn('payer', __m('Payer'));
    $table->addColumn('totalDept', __m('Total Dept'))->format(function ($row) {
        return number_format($row['totalDept'], 2);
    });
    $table->addColumn('payments', __m('Payments'))->format(function ($row) {
        return number_format($row['payments'], 2);
    });
    $table->addColumn('paymentsAdjustment', __m('Payments Adjustment'))->format(function ($row) {
        return number_format($row['paymentsAdjustment'], 2);
    });
    $table->addColumn('balance', __m('Balance'))->format(function ($row) {
        return number_format($row['balance'], 2);
    });

    $table->addActionColumn()
        ->addParam('schoolYearID', $schoolYearID)
        ->addParam('search', $search)
        ->format(function ($row, $actions) {
            $actions->addAction('view', __m('View Details'))
                ->setURL('/modules/Sepa/sepa_family_details.php')
                ->addParam('gibbonFamilyID', $row['gibbonFamilyID']);
            ;
        });

    echo $table->render($familyTotals);
}
