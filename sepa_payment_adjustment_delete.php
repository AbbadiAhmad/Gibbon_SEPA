<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community
Copyright © 2010, Gibbon Foundation
*/

use Gibbon\Forms\Form;
use Gibbon\Module\Sepa\Domain\SepaPaymentAdjustmentGateway;
use Gibbon\Data\Validator;


require_once __DIR__ . '/moduleFunctions.php';
require_once __DIR__ . '/../../gibbon.php';

if (isActionAccessible($guid, $connection2, "/modules/Sepa/sepa_payment_adjustment_delete.php") == false) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    $_GET = $container->get(Validator::class)->sanitize($_GET);
    $_POST = $container->get(Validator::class)->sanitize($_POST);

    $gibbonSEPAPaymentAdjustmentID = $_GET['gibbonSEPAPaymentAdjustmentID'] ?? '';
    $family_details = $_GET['family_details'] ?? '';


    if (empty($gibbonSEPAPaymentAdjustmentID)) {
        $page->addError(__('You have not specified one or more required parameters.'));
        return;
    }

    $page->breadcrumbs
        ->add(__('Payment Adjustment'), '/sepa_payment_adjustment_manage.php')
        ->add(__('Delete Ajustment'));

    $adjustmentGateway = $container->get(SepaPaymentAdjustmentGateway::class);

    $payment_adjustment = $adjustmentGateway->getPaymentAdjustmentByID($gibbonSEPAPaymentAdjustmentID);

    if (empty($payment_adjustment)) {
        $page->addError(__('The specified payment_adjustment cannot be found.'));
        return;
    }


    $form = Form::create('delete', $session->get('absoluteURL') . '/modules/Sepa/sepa_payment_adjustment_deleteProcess.php');

    $form->addHiddenValue('address', $session->get('address'));
    $form->addHiddenValue('gibbonSEPAPaymentAdjustmentID', $gibbonSEPAPaymentAdjustmentID);
    $form->addHiddenValue('family_details', $family_details);

    $row = $form->addRow();
    $row->addLabel('delete', __('Are you sure you want to delete this payment_adjustment?'));
    $row->addTextArea('delete')->setValue(__('This operation cannot be undone, and may lead to loss of vital data in your system. PROCEED WITH CAUTION!'))->readonly();

    $row = $form->addRow();
    $row->addFooter();
    $row->addSubmit(__('Delete'));

    echo $form->getOutput();
}
