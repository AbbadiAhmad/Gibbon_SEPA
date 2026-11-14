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

if (isActionAccessible($guid, $connection2, "/modules/Sepa/sepa_payment_delete.php") == false) {
    // Access denied
    $page->addError(__m('You do not have access to this action.'));
} else {
    $_GET = $container->get(Validator::class)->sanitize($_GET);
    $_POST = $container->get(Validator::class)->sanitize($_POST);

    $gibbonSEPAPaymentRecordID = $_GET['gibbonSEPAPaymentRecordID'] ?? '';
    $family_details = $_GET['family_details'] ?? '';


    if (empty($gibbonSEPAPaymentRecordID)) {
        $page->addError(__m('Invalid payment entry.'));
        return;
    }

    $page->breadcrumbs
        ->add(__m('Manage SEPA Payment Entries'), 'sepa_payment_manage.php')
        ->add(__m('Delete Payment Entry'));

    $SepaGateway = $container->get(SepaGateway::class);
    $payment = $SepaGateway->getPaymentByID($gibbonSEPAPaymentRecordID);

    if (!$payment) {
        $page->addError(__m('Payment entry not found.'));
        return;
    }

    $form = Form::create('paymentDelete', $session->get('absoluteURL') . '/modules/Sepa/sepa_payment_deleteProcess.php');

    $form->addHiddenValue('address', $_SESSION[$guid]['address']);
    $form->addHiddenValue('gibbonSEPAPaymentRecordID', $gibbonSEPAPaymentRecordID);
    $form->addHiddenValue('family_details', $family_details);

    $row = $form->addRow();
    $row->addContent(__m('Are you sure you want to delete this payment entry? This action cannot be undone.'));

    $row = $form->addRow();
    $row->addContent(__m('Booking Date: ') . $payment['booking_date']);

    $row = $form->addRow();
    $row->addContent(__m('Payer Name: ') . $payment['payer']);

    $row = $form->addRow();
    $row->addContent(__m('Amount: ') . $payment['amount']);

    $row = $form->addRow();
    $row->addFooter();
    $row->addSubmit(__m('Delete'));

    echo $form->getOutput();
}
