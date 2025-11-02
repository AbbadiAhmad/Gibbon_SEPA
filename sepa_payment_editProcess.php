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

$URL = $gibbon->session->get('absoluteURL') . '/index.php?q=/modules/' . $gibbon->session->get('module') . '/sepa_payment_add.php';
$URLSuccess = $gibbon->session->get('absoluteURL') . '/index.php?q=/modules/' . $gibbon->session->get('module') . '/sepa_payment_manage.php';

if (isActionAccessible($guid, $connection2, "/modules/Sepa/sepa_payment_edit.php") == false) {
    // Access denied
    $URL = $URL . '&return=error0';
    header("Location: {$URL}");
} else {

    $_POST = $container->get(Validator::class)->sanitize($_POST);

    $gibbonSEPAPaymentRecordID = $_POST['gibbonSEPAPaymentRecordID'] ?? '';
    $family_details = $_POST['family_details'] ?? '';


    if (empty($gibbonSEPAPaymentRecordID)) {
        $page->addError(__('Invalid payment entry.'));
        return;
    }

    $SepaGateway = $container->get(SepaGateway::class);

    $paymentData = [
        'booking_date' => $_POST['booking_date'] ?? '',
        'payer' => $_POST['payer'] ?? '',
        'IBAN' => $_POST['IBAN'] ?? '',
        'transaction_reference' => $_POST['transaction_reference'] ?? '',
        'transaction_message' => $_POST['transaction_message'] ?? '',
        'amount' => $_POST['amount'] ?? '',
        'note' => $_POST['note'] ?? '',
        'academicYear' => $_POST['academicYear'] ?? '',
        'payment_method' => $_POST['payment_method'] ?? '',
        'gibbonSEPAID' => $_POST['gibbonSEPAID'] ?? null,
    ];

    // Try to match with existing SEPA record
    $sepaMatches = $SepaGateway->getSEPAForPaymentEntry($paymentData);
    if ($sepaMatches && count($sepaMatches) == 1) {
        $paymentData['gibbonSEPAID'] = $sepaMatches[0]['gibbonSEPAID'];
    }

    $result = $SepaGateway->updatePayment($gibbonSEPAPaymentRecordID, $paymentData);

    if ($result) {
        if (!empty($family_details)) {
            header("Location: {$session->get('absoluteURL')}/index.php?q=/modules/Sepa/sepa_family_details.php&gibbonFamilyID={$family_details}&return=success0");
        } else {
            header("Location: {$session->get('absoluteURL')}/index.php?q=/modules/Sepa/sepa_payment_manage.php&return=success1");
        }

    } else {
        header("Location: {$session->get('absoluteURL')}/index.php?q=/modules/Sepa/sepa_payment_edit.php&gibbonSEPAPaymentRecordID={$gibbonSEPAPaymentRecordID}");
    }
}
