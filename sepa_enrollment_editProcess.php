<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community
Copyright © 2010, Gibbon Foundation
*/

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

    $gibbonPersonID = $_POST['gibbonPersonID'] ?? '';
    $gibbonCourseClassID = $_POST['gibbonCourseClassID'] ?? '';
    $gibbonFamilyID = $_POST['gibbonFamilyID'] ?? '';

    if (empty($gibbonPersonID) || empty($gibbonCourseClassID)) {
        $page->addError(__('You have not specified one or more required parameters.'));
        return;
    }

    $dateEnrolled = $_POST['dateEnrolled'] ?? '';
    $dateUnenrolled = $_POST['dateUnenrolled'] ?? null;

    if (empty($dateEnrolled)) {
        $page->addError(__('Date enrolled is required.'));
        return;
    }

    // Convert empty string to null for dateUnenrolled
    if (empty($dateUnenrolled)) {
        $dateUnenrolled = null;
    }

    $SepaGateway = $container->get(SepaGateway::class);

    $updated = $SepaGateway->updateEnrollmentDates($gibbonPersonID, $gibbonCourseClassID, $dateEnrolled, $dateUnenrolled);

    if ($updated) {
        header("Location: {$session->get('absoluteURL')}/index.php?q=/modules/Sepa/sepa_family_details.php&gibbonFamilyID={$gibbonFamilyID}&return=success0");
    } else {
        header("Location: {$session->get('absoluteURL')}/index.php?q=/modules/Sepa/sepa_enrollment_edit.php&gibbonPersonID={$gibbonPersonID}&gibbonCourseClassID={$gibbonCourseClassID}&gibbonFamilyID={$gibbonFamilyID}&return=error1");
    }
}
