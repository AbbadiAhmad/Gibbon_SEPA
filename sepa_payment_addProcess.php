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

if (isActionAccessible($guid, $connection2, "/modules/Sepa/sepa_payment_add.php") == false) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {

    $_POST = $container->get(Validator::class)->sanitize($_POST);
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

    $result = $SepaGateway->insertPayment($paymentData, $_SESSION[$guid]["username"]);

    if ($result) {
        header("Location: {$session->get('absoluteURL')}/index.php?q=/modules/Sepa/sepa_payment_manage.php&return=success0");
    } else {
        header("Location: {$session->get('absoluteURL')}/index.php?q=/modules/Sepa/sepa_payment_add.php");
    }
}
