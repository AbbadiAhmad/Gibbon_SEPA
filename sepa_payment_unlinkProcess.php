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

if (isActionAccessible($guid, $connection2, "/modules/Sepa/sepa_payment_unlink.php") == false) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    $_GET = $container->get(Validator::class)->sanitize($_GET);
    $_POST = $container->get(Validator::class)->sanitize($_POST);
    $gibbonSEPAPaymentRecordID = $_POST['gibbonSEPAPaymentRecordID'] ?? '';
    if (empty($gibbonSEPAPaymentRecordID)) {
        $page->addError(__('Invalid payment entry.'));
        return;
    }

    $SepaGateway = $container->get(SepaGateway::class);
    $result = $SepaGateway->updatePayment($gibbonSEPAPaymentRecordID, ['gibbonSEPAID' => null]);

    if ($result) {
        header("Location: {$session->get('absoluteURL')}/index.php?q=/modules/Sepa/sepa_family_details.php&gibbonFamilyID={$_GET['gibbonFamilyID']}&return=success0");
    } else {
        header("Location: {$session->get('absoluteURL')}/index.php?q=/modules/Sepa/sepa_payment_unlink.php&gibbonSEPAPaymentRecordID={$gibbonSEPAPaymentRecordID}&return=error2");
    }
}
