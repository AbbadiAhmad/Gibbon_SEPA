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
use Gibbon\Module\Sepa\Domain\CoursesFeeGateway;

// Module includes
require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_payment_view.php')) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    $page->breadcrumbs->add(__('SEPA Payment Entries')); // show page navigation link

    $CoursesFeeGateway = $container->get(CoursesFeeGateway::class);
    $criteria = $CoursesFeeGateway->newQueryCriteria(true)
        ->fromPOST();
    $coursesFee = $CoursesFeeGateway->queryCoursesFees($criteria, $_SESSION[$guid]["gibbonSchoolYearID"]);

    //
    echo '<h2>';
    echo __('View Payment Entries');
    echo '</h2>';

    // QUERY



    // DATA TABLE
    $form = Form::create('coursesFee', $session->get('absoluteURL') . '/modules/' . $session->get('module') . '/sepa_courses_fee_updateProcess.php');
    $form->addHiddenValue('address', $session->get('address'));

    // BASIC INFORMATION
    $form->addRow()->addHeading('Courses Fee', __('Courses Fee'));
    $textField = [];
    foreach ($coursesFee as $course) {
        $row = $form->addRow();
        $row->addLabel($course['nameShort'], $course['nameShort']);
        $textField = $row->addTextField($course['gibbonCourseID'])->setValue($course['Fees'])->maxLength(4);
    }
    $row = $form->addRow();
    $row->addFooter();
    $row->addSubmit();
    echo $form->getOutput();
}
