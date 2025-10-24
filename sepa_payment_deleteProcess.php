<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community
Copyright © 2010, Gibbon Foundation
*/

use Gibbon\Module\Sepa\Domain\SepaGateway;
use Gibbon\Data\Validator;
$_GET = $container->get(Validator::class)->sanitize($_GET);
$_POST = $container->get(Validator::class)->sanitize($_POST);

require_once __DIR__ . '/moduleFunctions.php';
require_once __DIR__ . '/../../gibbon.php';

if (isActionAccessible($guid, $connection2, "/modules/Sepa/sepa_payment_delete.php") == false) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    $gibbonSEPAPaymentRecordID = $_POST['gibbonSEPAPaymentRecordID'] ?? '';
    if (empty($gibbonSEPAPaymentRecordID)) {
        $page->addError(__('Invalid payment entry.'));
        return;
    }

    $SepaGateway = $container->get(SepaGateway::class);
    $result = $SepaGateway->deletePayment($gibbonSEPAPaymentRecordID);

    if ($result) {
        $page->addSuccess(__('Payment entry deleted successfully.'));
        header("Location: {$session->get('absoluteURL')}/index.php?q=/modules/Sepa/sepa_payment_manage.php");
    } else {
        $page->addError(__('Failed to delete payment entry.'));
        header("Location: {$session->get('absoluteURL')}/index.php?q=/modules/Sepa/sepa_payment_delete.php&gibbonSEPAPaymentRecordID={$gibbonSEPAPaymentRecordID}");
    }
}
