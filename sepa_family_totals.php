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
    $page->breadcrumbs->add(__('Family Totals'));

    $schoolYearID = isset($_GET['schoolYearID']) ? $_GET['schoolYearID'] : $_SESSION[$guid]["gibbonSchoolYearID"];

    $SepaGateway = $container->get(SepaGateway::class);

    echo '<h2>';
    echo __('Family Totals');
    echo '</h2>';

    $criteria = $SepaGateway->newQueryCriteria(true)
        ->searchBy(['familyName'])
        ->fromPOST();

    $familyTotals = $SepaGateway->getFamilyTotals($schoolYearID, $criteria);

    $table = DataTable::createPaginated('familyTotals', $criteria);

    //$table->addColumn('gibbonFamilyID', __('Family ID'));
    $table->addColumn('familyName', __('Family Name'));
    $table->addColumn('sepaName', __('SEPA Name'));
    $table->addColumn('totalDept', __('Total Dept'));
    $table->addColumn('payments', __('Payments'));

    echo $table->render($familyTotals);
}
