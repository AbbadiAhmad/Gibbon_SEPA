<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community
Copyright © 2010, Gibbon Foundation
*/

use Gibbon\Forms\Form;
use Gibbon\Module\Sepa\Domain\SepaGateway;
use Gibbon\Data\Validator;


require_once __DIR__ . '/moduleFunctions.php';
require_once __DIR__ . '/../../gibbon.php';

if (isActionAccessible($guid, $connection2, "/modules/Sepa/sepa_enrollment_edit.php") == false) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {

    $_GET = $container->get(Validator::class)->sanitize($_GET);
    $_POST = $container->get(Validator::class)->sanitize($_POST);

    $gibbonPersonID = $_GET['gibbonPersonID'] ?? '';
    $gibbonCourseClassID = $_GET['gibbonCourseClassID'] ?? '';
    $gibbonFamilyID = $_GET['gibbonFamilyID'] ?? '';

    if (empty($gibbonPersonID) || empty($gibbonCourseClassID)) {
        $page->addError(__('You have not specified one or more required parameters.'));
        return;
    }

    $page->breadcrumbs
        ->add(__('Family Details'), 'sepa_family_details.php', ['gibbonFamilyID' => $gibbonFamilyID])
        ->add(__('Edit Enrollment'));

    $SepaGateway = $container->get(SepaGateway::class);

    $enrollment = $SepaGateway->getEnrollmentByIDs($gibbonPersonID, $gibbonCourseClassID);

    if (empty($enrollment)) {
        $page->addError(__('The specified enrollment cannot be found.'));
        return;
    }

    $form = Form::create('enrollment_edit', $session->get('absoluteURL') . '/modules/Sepa/sepa_enrollment_editProcess.php');

    $form->addHiddenValue('address', $session->get('address'));
    $form->addHiddenValue('gibbonPersonID', $gibbonPersonID);
    $form->addHiddenValue('gibbonCourseClassID', $gibbonCourseClassID);
    $form->addHiddenValue('gibbonFamilyID', $gibbonFamilyID);

    $row = $form->addRow();
    $row->addLabel('studentName', __('Student'));
    $row->addTextField('studentName')->setValue($enrollment['preferredName'] . ' ' . $enrollment['surname'])->readonly();

    $row = $form->addRow();
    $row->addLabel('courseName', __('Course'));
    $row->addTextField('courseName')->setValue($enrollment['courseName'])->readonly();

    $row = $form->addRow();
    $row->addLabel('className', __('Class'));
    $row->addTextField('className')->setValue($enrollment['className'])->readonly();

    $row = $form->addRow();
    $row->addLabel('dateEnrolled', __('Date Enrolled'));
    $row->addDate('dateEnrolled')->required()->setValue($enrollment['dateEnrolled']);

    $row = $form->addRow();
    $row->addLabel('dateUnenrolled', __('Date Unenrolled'));
    $row->addDate('dateUnenrolled')->setValue($enrollment['dateUnenrolled']);

    $row = $form->addRow();
    $row->addFooter();
    $row->addSubmit();

    echo $form->getOutput();
}
