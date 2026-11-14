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
use Gibbon\Module\Sepa\Domain\SepaGateway;

// Module includes
require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_courses_fee_view.php')) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    $page->breadcrumbs->add(__('SEPA Payment Entries')); // show page navigation link

    $CoursesFeeGateway = $container->get(CoursesFeeGateway::class);
    $criteria = $CoursesFeeGateway->newQueryCriteria(true)
        ->fromPOST();
    $schoolYearID = sepaSelectedSchoolYear($_GET, $session->get('gibbonSchoolYearID'));
    $coursesFee = $CoursesFeeGateway->queryCoursesFees($criteria, $schoolYearID);

    // Academic year filter
    $filter = Form::create('schoolYearFilter', $session->get('absoluteURL') . '/index.php', 'get');
    $filter->setClass('noIntBorder fullWidth');
    $filter->addHiddenValue('q', '/modules/Sepa/sepa_courses_fee_view.php');
    sepaAddSchoolYearRow($filter, $container->get(SepaGateway::class)->getAllSchoolYears(), $schoolYearID);
    $filter->addRow()->addSubmit(__('Go'));
    echo $filter->getOutput();

    //
    echo '<h2>';
    echo __('View Payment Entries');
    echo '</h2>';

    // Result of a copy from the previous year (set by the copy process)
    if (!empty($_SESSION[$guid]['sepaFeesCopyReport'])) {
        $copy = $_SESSION[$guid]['sepaFeesCopyReport'];
        unset($_SESSION[$guid]['sepaFeesCopyReport']);
        $text = sprintf(__('%1$d fee(s) copied from %2$s.'), $copy['copied'], htmlspecialchars($copy['previousYear'], ENT_QUOTES, 'UTF-8'));
        if (!empty($copy['unmatched'])) {
            $text .= ' ' . sprintf(__('No match with a fee in that year (left empty): %s'), htmlspecialchars(implode(', ', $copy['unmatched']), ENT_QUOTES, 'UTF-8'));
        }
        echo '<div class="' . (empty($copy['unmatched']) ? 'success' : 'warning') . '">' . $text . '</div>';
    }

    // Courses without a fee
    $withoutFee = array_filter($coursesFee->toArray(), function ($course) {
        return $course['gibbonSepaCoursesCostID'] === null;
    });
    if (!empty($withoutFee)) {
        echo '<div class="warning">' . sprintf(__('%d course(s) have no fee yet. The balances and totals count them with 0.'), count($withoutFee)) . '</div>';

        // Copy the fees of the previous academic year
        $previousYear = $schoolYearID !== 'all' ? $CoursesFeeGateway->getPreviousSchoolYear($schoolYearID) : null;
        if ($previousYear) {
            $copyForm = Form::create('copyFees', $session->get('absoluteURL') . '/modules/' . $session->get('module') . '/sepa_courses_fee_copyProcess.php');
            $copyForm->addHiddenValue('address', $session->get('address'));
            $copyForm->addHiddenValue('schoolYearID', $schoolYearID);
            $row = $copyForm->addRow();
            $row->addContent(sprintf(__('Courses are compared by name with %s; matching courses get the same fee, courses without a match stay empty. Fees that are set are not changed.'), htmlspecialchars($previousYear['name'], ENT_QUOTES, 'UTF-8')));
            $row->addSubmit(sprintf(__('Copy fees from %s'), $previousYear['name']));
            echo $copyForm->getOutput();
        }
    }

    // QUERY



    // DATA TABLE
    $form = Form::create('coursesFee', $session->get('absoluteURL') . '/modules/' . $session->get('module') . '/sepa_courses_fee_updateProcess.php');
    $form->addHiddenValue('address', $session->get('address'));
    $form->addHiddenValue('schoolYearID', $schoolYearID);

    // BASIC INFORMATION
    $form->addRow()->addHeading('Courses Fee', __('Courses Fee'));
    $textField = [];
    foreach ($coursesFee as $course) {
        $row = $form->addRow();
        $label = $schoolYearID === 'all' ? $course['nameShort'] . ' (' . $course['yearName'] . ')' : $course['nameShort'];
        if ($course['gibbonSepaCoursesCostID'] === null) {
            $label .= ' - ' . __('not set');
        }
        $row->addLabel($course['nameShort'], $label);
        $textField = $row->addTextField($course['gibbonCourseID'])->setValue($course['Fees'])->maxLength(5);
    }
    $row = $form->addRow();
    $row->addFooter();
    $row->addSubmit();
    echo $form->getOutput();
}
