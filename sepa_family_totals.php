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

$_GET = $container->get(Validator::class)->sanitize($_GET);
$_POST = $container->get(Validator::class)->sanitize($_POST);

// Module includes
require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_family_totals.php')) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    $page->breadcrumbs->add(__('Family Totals'));

    $schoolYearID = isset($_GET['schoolYearID']) ? $_GET['schoolYearID'] : $_SESSION[$guid]["gibbonSchoolYearID"];
    $search = isset($_GET['search']) ? $_GET['search'] : '';

    $SepaGateway = $container->get(SepaGateway::class);

    $criteria = $SepaGateway->newQueryCriteria(true)
        ->searchBy(['familyName', 'sepaName'], $search)
        ->fromPOST();

    $criteria->addFilterRules([
        'unpaidNoSepa' => function ($query, $unpaidNoSepa) {
            if ($unpaidNoSepa == 'unpaidNoSepa') {
                return $query->having('sepaName IS NULL')
                    ->having('balance != 0')
                    ->having('totalDept = 0');
            }
            return $query;
        },
    ]);

    $form = Form::createSearch();

    $row = $form->addRow();
        $row->addLabel('search', __('Search For'))
            ->description(__('Family Name, SEPA Name'));
        $row->addTextField('search')->setValue($criteria->getSearchText());

    $form->addRow()->addSearchSubmit('', __('Clear Search'));

    echo $form->getOutput();

    echo '<h2>';
    echo __('Family Totals');
    echo '</h2>';

    $familyTotals = $SepaGateway->getFamilyTotals($schoolYearID, $criteria);

    $table = DataTable::createPaginated('familyTotals', $criteria);

    $table->addMetaData('filterOptions', ['unpaidNoSepa' => __('Unpaid without SEPA')]);

    //$table->addColumn('gibbonFamilyID', __('Family ID'));
    $table->addColumn('familyName', __('Family Name'));
    $table->addColumn('sepaName', __('SEPA Name'));
    $table->addColumn('totalDept', __('Total Dept'))->format(function ($row) {
        return number_format($row['totalDept'], 2);
    });
    $table->addColumn('payments', __('Payments'))->format(function ($row) {
        return number_format($row['payments'], 2);
    });
    $table->addColumn('paymentsAdjustment', __('Payments Adjustment'))->format(function ($row) {
        return number_format($row['paymentsAdjustment'], 2);
    });
    $table->addColumn('balance', __('Balance'))->format(function ($row) {
        return number_format($row['balance'], 2);
    });

    $table->addActionColumn()
        ->addParam('schoolYearID', $schoolYearID)
        ->addParam('search', $criteria->getSearchText(true))
        ->format(function ($row, $actions) {
            $actions->addAction('view', __('View Details'))
                ->setURL('/modules/Sepa/sepa_family_details.php')
                ->addParam('gibbonFamilyID', $row['gibbonFamilyID']);
            ;
        });

    echo $table->render($familyTotals);
}
