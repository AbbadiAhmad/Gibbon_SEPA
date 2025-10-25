<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community
Copyright © 2010, Gibbon Foundation
*/

use Gibbon\Forms\Form;
use Gibbon\Module\Sepa\Domain\SepaDiscountGateway;
use Gibbon\Data\Validator;

$_GET = $container->get(Validator::class)->sanitize($_GET);
$_POST = $container->get(Validator::class)->sanitize($_POST);

require_once __DIR__ . '/moduleFunctions.php';
require_once __DIR__ . '/../../gibbon.php';

if (isActionAccessible($guid, $connection2, "/modules/Sepa/sepa_discount_edit.php") == false) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    $gibbonSEPADiscountID = $_GET['gibbonSEPADiscountID'] ?? '';

    if (empty($gibbonSEPADiscountID)) {
        $page->addError(__('You have not specified one or more required parameters.'));
        return;
    }

    $page->breadcrumbs
        ->add(__('Manage SEPA Discounts'), '/modules/Sepa/sepa_discount_manage.php')
        ->add(__('Edit Discount'));

    $SepaDiscountGateway = $container->get(SepaDiscountGateway::class);

    $discount = $SepaDiscountGateway->getDiscountByID($gibbonSEPADiscountID);

    if (empty($discount)) {
        $page->addError(__('The specified discount cannot be found.'));
        return;
    }

    $form = Form::create('discount', $session->get('absoluteURL') . '/modules/Sepa/sepa_discount_editProcess.php');

    $form->addHiddenValue('address', $session->get('address'));
    $form->addHiddenValue('gibbonSEPADiscountID', $gibbonSEPADiscountID);

    $row = $form->addRow();
        $row->addLabel('gibbonSEPAID', __('SEPA ID'));
        $row->addNumber('gibbonSEPAID')->required()->maxLength(8)->setValue($discount['gibbonSEPAID']);

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
