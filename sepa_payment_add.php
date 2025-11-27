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


require_once __DIR__ . '/moduleFunctions.php';
require_once __DIR__ . '/../../gibbon.php';

if (isActionAccessible($guid, $connection2, "/modules/Sepa/sepa_payment_add.php") == false) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    $_GET = $container->get(Validator::class)->sanitize($_GET);
    $_POST = $container->get(Validator::class)->sanitize($_POST);

    $gibbonSEPAID = $_GET['gibbonSEPAID'] ?? null;
    $lockFamily = $_GET['lockFamily'] ?? false;
    $family_details = $_GET['family_details'] ?? '';


    $page->breadcrumbs
        ->add(__('Manage SEPA Payment Entries'), 'sepa_payment_manage.php')
        ->add(__('Add Payment Entry'));

    $SepaGateway = $container->get(SepaGateway::class);

    $criteria = $SepaGateway->newQueryCriteria(false)
        ->sortBy(['payer'])
    ;
    $sepaList = $SepaGateway->getSEPAList($criteria, null);
    $schoolYears = $SepaGateway->getAllSchoolYears();


    $form = Form::create('paymentAdd', $session->get('absoluteURL') . '/modules/Sepa/sepa_payment_addProcess.php');

    $form->addHiddenValue('address', $_SESSION[$guid]['address']);

    $row = $form->addRow();
    $row->addLabel('booking_date', __('Booking Date'));
    $row->addDate('booking_date')->required()->setValue(date('Y-m-d'));

    $row = $form->addRow();
    $row->addLabel('academicYear', __('Academic Year'));
    $row->addSelect('academicYear')
        ->fromArray($schoolYears)
        ->required()
        ->selected($_SESSION[$guid]["gibbonSchoolYearID"])
        ->placeholder(__('Please select...'));

    $form->addHiddenValue('family_details', $family_details);

    $row = $form->addRow();
    $row->addLabel('gibbonSEPAID', __('SEPA Account'));
    $sepaOptions = array_column($sepaList->toArray(), 'payer', 'gibbonSEPAID');
    $select = $row->addSelect('gibbonSEPAID')->fromArray($sepaOptions)->placeholder(__(''))->required();
    if ($gibbonSEPAID && array_key_exists($gibbonSEPAID, $sepaOptions)) {
        $select->selected($gibbonSEPAID);
        if ($lockFamily) {
            $select->disabled();
            $form->addHiddenValue('gibbonSEPAID', $gibbonSEPAID);
        }
    }

    $row = $form->addRow();
    $row->addLabel('payment_method', __('Payment Method'));
    $row->addSelect('payment_method')->fromArray(['card' => 'Card', 'cash' => 'Cash', 'bank_transfer' => 'Bank Transfer', 'SEPA' => 'SEPA',])->placeholder(__(''))->required();

    $row = $form->addRow();
    $row->addLabel('amount', __('Amount'));
    $row->addNumber('amount')->required()->decimalPlaces(2)->minimum(0);

    $row = $form->addRow();
    $row->addLabel('payer', __('Payer'));
    $row->addTextField('payer')->required()->maxLength(100);

    $row = $form->addRow();
    $row->addLabel('IBAN', __('IBAN'));
    $row->addTextField('IBAN')->maxLength(34);

    $row = $form->addRow();
    $row->addLabel('transaction_reference', __('Transaction Reference'));
    $row->addTextField('transaction_reference')->maxLength(255);

    $row = $form->addRow();
    $row->addLabel('transaction_message', __('Transaction Message from the bank'));
    $row->addTextField('transaction_message')->maxLength(255);

    $row = $form->addRow();
    $row->addLabel('note', __('Note: additional information'));
    $row->addTextArea('note');

    $row = $form->addRow();
    $row->addFooter();
    $row->addSubmit();

    echo $form->getOutput();
}
