<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community
Copyright © 2010, Gibbon Foundation
*/

use Gibbon\Forms\Form;
use Gibbon\Domain\QueryCriteria;
use Gibbon\Module\Sepa\Domain\SepaGateway;
use Gibbon\Data\Validator;
$_GET = $container->get(Validator::class)->sanitize($_GET);
$_POST = $container->get(Validator::class)->sanitize($_POST);

require_once __DIR__ . '/moduleFunctions.php';
require_once __DIR__ . '/../../gibbon.php';

if (isActionAccessible($guid, $connection2, "/modules/Sepa/sepa_payment_edit.php") == false) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    $gibbonSEPAPaymentRecordID = $_GET['gibbonSEPAPaymentRecordID'] ?? '';
    if (empty($gibbonSEPAPaymentRecordID)) {
        $page->addError(__('Invalid payment entry.'));
        return;
    }

    $page->breadcrumbs
        ->add(__('Manage SEPA Payment Entries'), 'sepa_payment_manage.php')
        ->add(__('Edit Payment Entry'));

    $SepaGateway = $container->get(SepaGateway::class);
    $payment = $SepaGateway->getPaymentByID($gibbonSEPAPaymentRecordID);
    $criteria = $container->get(QueryCriteria::class);

    $sepaList = $SepaGateway->getSEPAList($criteria, null);


    if (!$payment) {
        $page->addError(__('Payment entry not found.'));
        return;
    }

    $form = Form::create('paymentEdit', $session->get('absoluteURL') . '/modules/Sepa/sepa_payment_editProcess.php');

    $form->addHiddenValue('address', $_SESSION[$guid]['address']);
    $form->addHiddenValue('gibbonSEPAPaymentRecordID', $gibbonSEPAPaymentRecordID);

    $row = $form->addRow();
    $row->addLabel('booking_date', __('Booking Date'));
    $row->addDate('booking_date')->required()->setValue($payment['booking_date']);

    $row = $form->addRow();
    $row->addLabel('academicYear', __('Academic Year'));
    $row->addSelect('academicYear')->fromArray([$_SESSION[$guid]["gibbonSchoolYearID"] => $_SESSION[$guid]["gibbonSchoolYearName"]])->selected($_SESSION[$guid]["gibbonSchoolYearID"])->disabled();

    $row = $form->addRow();
    $row->addLabel('gibbonSEPAID', __('SEPA Account'));
    
    $sepaOptions = array_column($sepaList->toArray(), 'payer', 'gibbonSEPAID');
    $sepaOptions = array_combine(
    array_map(fn($k) => (string)(int)$k, array_keys($sepaOptions)),
    $sepaOptions);

    $select = $row->addSelect('gibbonSEPAID')->fromArray($sepaOptions)->placeholder(__(''));
    if ($payment['gibbonSEPAID'] && array_key_exists($payment['gibbonSEPAID'], $sepaOptions)) {
        $select->selected($payment['gibbonSEPAID'])->disabled();
    }

    $row = $form->addRow();
    $row->addLabel('payment_method', __('Payment Method'));
    $select = $row->addSelect('payment_method')->fromArray(['card' => 'Card', 'cash' => 'Cash', 'bank_transfer' => 'Bank Transfer', 'SEPA' => 'SEPA',])->required();
    $select->selected($payment['payment_method']);

    $row = $form->addRow();
    $row->addLabel('amount', __('Amount'));
    $row->addNumber('amount')->required()->decimalPlaces(2)->minimum(0)->setValue($payment['amount']);

    $row = $form->addRow();
    $row->addLabel('payer', __('Payer'));
    $row->addTextField('payer')->required()->maxLength(100)->setValue($payment['payer']);

    $row = $form->addRow();
    $row->addLabel('IBAN', __('IBAN'));
    $row->addTextField('IBAN')->maxLength(34)->setValue($payment['IBAN']);

    $row = $form->addRow();
    $row->addLabel('transaction_reference', __('Transaction Reference'));
    $row->addTextField('transaction_reference')->maxLength(255)->setValue($payment['transaction_reference']);

    $row = $form->addRow();
    $row->addLabel('transaction_message', __('Transaction Message'));
    $row->addTextField('transaction_message')->maxLength(255)->setValue($payment['transaction_message']);

    $row = $form->addRow();
    $row->addLabel('note', __('Note'));
    $row->addTextArea('note')->setValue($payment['note']);

    $row = $form->addRow();
    $row->addFooter();
    $row->addSubmit();

    echo $form->getOutput();
}
