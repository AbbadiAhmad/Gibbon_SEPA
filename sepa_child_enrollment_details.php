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
use Gibbon\Services\Format;


$_GET = $container->get(Validator::class)->sanitize($_GET);
$_POST = $container->get(Validator::class)->sanitize($_POST);

// Module includes
require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_payment_view.php')) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    $page->breadcrumbs->add(__('Child Enrollment Details'));

    $schoolYearID = isset($_GET['schoolYearID']) ? $_GET['schoolYearID'] : $_SESSION[$guid]["gibbonSchoolYearID"];
    $search = isset($_GET['search']) ? $_GET['search'] : '';


    $SepaGateway = $container->get(SepaGateway::class);
    $criteria = $SepaGateway->newQueryCriteria(true)
        ->searchBy(['childID', 'preferredName', 'gibbonFamilyID', 'className'], $search)
        ->sortBy(['gibbonFamilyID','childID'])
        ->fromPOST();

    $form = Form::createSearch();
    $row = $form->addRow();
    $row->addLabel('search', __('Search For'))
        ->description(__('payer, booking date, amount, transaction message, IBAN, transaction reference, note'));
    $row->addTextField('search')->setValue($criteria->getSearchText());
    $form->addRow()->addSearchSubmit('', __('Clear Search'));
    echo $form->getOutput();

    echo '<h2>';
    echo __('Child Enrollment Details');
    echo '</h2>';

    $enrollmentDetails = $SepaGateway->getChildEnrollmentDetails($schoolYearID, $criteria);

    $table = DataTable::createPaginated('enrollmentDetails', $criteria);


    $table->addColumn('childID', __('Child ID'));
    $table->addColumn('preferredName', __('Preferred Name'));
    $table->addColumn('gibbonFamilyID', __('Family ID'));
    $table->addColumn('gibbonCourseClassID', __('ClassID'));
    $table->addColumn('shortName', __('Class shortName'));
    $table->addColumn('dateEnrolled', __('dateEnrolled'));
    $table->addColumn('dateUnenrolled', __('dateUnenrolled'));
    $table->addColumn('startDate', __('startDate'));
    $table->addColumn('lastDate', __('lastDate'));
    $table->addColumn('monthsEnrolled', __('Months Enrolled'));
    $table->addColumn('courseFee', __('Course Fee'));
    $table->addColumn('total', __('Total'));

    echo $table->render($enrollmentDetails);
}
