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

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_child_enrollment_details.php')) {
    // Access denied
    $page->addError(__m('You do not have access to this action.'));
} else {
    $page->breadcrumbs->add(__m('Child Enrollment Details'));

    $schoolYearID = sepaSelectedSchoolYear($_GET, $session->get('gibbonSchoolYearID'));
    $search = isset($_GET['search']) ? $_GET['search'] : '';


    $SepaGateway = $container->get(SepaGateway::class);
    $criteria = $SepaGateway->newQueryCriteria(true)
        ->searchBy(['gibbonPerson.preferredName','gibbonPerson.surname'], $search)
        ->sortBy(['total'])
        ->fromPOST();

    $form = Form::createSearch();
    $row = $form->addRow();
    $row->addLabel('search', __m('Search For'))
        ->description(__m('Student name'));
    $row->addTextField('search')->setValue($criteria->getSearchText());
    sepaAddSchoolYearRow($form, $SepaGateway->getAllSchoolYears(), $schoolYearID);

    $form->addRow()->addSearchSubmit('', __m('Clear Search'));
    echo $form->getOutput();

    echo '<h2>';
    echo __m('Child Enrollment Details');
    echo '</h2>';
    echo sepaMissingFeesWarning($SepaGateway, $schoolYearID, $session->get('absoluteURL'));
    echo '<p>' . __m('Rows in red: the end date lies before the start date (see the Issue column).') . '</p>';

    $enrollmentDetails = $SepaGateway->getChildEnrollmentDetails($schoolYearID, $criteria);

    $table = DataTable::createPaginated('enrollmentDetails', $criteria);

    // Enrollments whose end date lies before the start date are marked red
    $table->modifyRows(function ($row, $tableRow) {
        return SepaGateway::getEnrollmentDateProblems($row) ? $tableRow->addClass('error') : $tableRow;
    });


   // $table->addColumn('childID', __m('Child ID'));
    $table->addColumn('student_name', __m('Student Name'));
    
    $table->addColumn('familyName', __m('Family'));
    if ($schoolYearID === 'all') {
        $table->addColumn('yearName', __m('Academic Year'));
    }
    //$table->addColumn('gibbonCourseClassID', __m('ClassID'));
    $table->addColumn('shortName', __m('Course'));
    $table->addColumn('dateEnrolled', __m('dateEnrolled'));
    $table->addColumn('dateUnenrolled', __m('dateUnenrolled'));
    $table->addColumn('personDateEnd', __m('Left date'));
    $table->addColumn('startDate', __m('Calc. start'));
    $table->addColumn('lastDate', __m('Calc. end'));
    $table->addColumn('monthsEnrolled', __m('Months Enrolled'));
    $table->addColumn('courseFee', __m('Course Fee'));
    $table->addColumn('total', __m('Total'));
    $table->addColumn('issue', __m('Issue'))
        ->notSortable()
        ->format(function ($row) {
            return implode('<br>', array_map('htmlspecialchars', SepaGateway::getEnrollmentDateProblems($row)));
        });

    echo $table->render($enrollmentDetails);
}
