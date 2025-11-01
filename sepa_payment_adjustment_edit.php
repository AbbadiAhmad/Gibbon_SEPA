<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community
Copyright © 2010, Gibbon Foundation
*/

use Gibbon\Forms\Form;
use Gibbon\Module\Sepa\Domain\SepaPaymentAdjustmentGateway;
use Gibbon\Data\Validator;
use Gibbon\Module\Sepa\Domain\SepaGateway;


require_once __DIR__ . '/moduleFunctions.php';
require_once __DIR__ . '/../../gibbon.php';

if (isActionAccessible($guid, $connection2, "/modules/Sepa/sepa_discount_edit.php") == false) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {


    $_GET = $container->get(Validator::class)->sanitize($_GET);
    $_POST = $container->get(Validator::class)->sanitize($_POST);

    $gibbonSEPADiscountID = $_GET['gibbonSEPADiscountID'] ?? '';
    $family_details = $_GET['family_details'] ?? '';


    if (empty($gibbonSEPADiscountID)) {
        $page->addError(__('You have not specified one or more required parameters.'));
        return;
    }

    $page->breadcrumbs
        ->add(__('Payment Adjustment'), '/sepa_payment_adjustment_manage.php')
        ->add(__('Edit Payment Adjustment'));

    $SepaDiscountGateway = $container->get(SepaPaymentAdjustmentGateway::class);

    $discount = $SepaDiscountGateway->getDiscountByID($gibbonSEPADiscountID);

    if (empty($discount)) {
        $page->addError(__('The specified discount cannot be found.'));
        return;
    }

    $form = Form::create('discount', $session->get('absoluteURL') . '/modules/Sepa/sepa_discount_editProcess.php');

    $form->addHiddenValue('address', $session->get('address'));
    $form->addHiddenValue('gibbonSEPADiscountID', $gibbonSEPADiscountID);
    $form->addHiddenValue('family_details', $family_details);


    $SepaGateway = $container->get(SepaGateway::class);
    $criteria = $SepaGateway->newQueryCriteria(false)->sortBy(['payer']);
    $gibbonSEPAID = $discount['gibbonSEPAID'] ?? null;

    $sepaList = $SepaGateway->getSEPAList($criteria, null);
    $row = $form->addRow();
    $row->addLabel('gibbonSEPAID', __('SEPA Account'));
    $sepaOptions = array_column($sepaList->toArray(), 'payer', 'gibbonSEPAID');
    $select = $row->addSelect('gibbonSEPAID')->fromArray($sepaOptions)->placeholder(__(''))->required();
    if ($gibbonSEPAID && array_key_exists($gibbonSEPAID, $sepaOptions)) {
        $select->selected($gibbonSEPAID)->disabled();
        $form->addHiddenValue('gibbonSEPAID', $gibbonSEPAID);
    }


    $row = $form->addRow();
    $row->addLabel('discountAmount', __('Discount Amount'));
    $row->addNumber('discountAmount')->required()->decimalPlaces(2)->minimum(0.01)->setValue($discount['discountAmount']);

    $row = $form->addRow();
    $row->addLabel('description', __('Description'));
    $row->addTextArea('description')->required()->setRows(3)->setValue($discount['description']);

    $row = $form->addRow();
    $row->addLabel('note', __('Note'));
    $row->addTextArea('note')->setRows(3)->setValue($discount['note']);

    $row = $form->addRow();
    $row->addFooter();
    $row->addSubmit();

    echo $form->getOutput();
}
