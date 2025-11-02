<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community
Copyright © 2010, Gibbon Foundation
*/

use Gibbon\Forms\Form;
use Gibbon\Module\Sepa\Domain\SepaPaymentAdjustmentGateway;
use Gibbon\Module\Sepa\Domain\SepaGateway;
use Gibbon\Data\Validator;


require_once __DIR__ . '/moduleFunctions.php';
require_once __DIR__ . '/../../gibbon.php';

if (isActionAccessible($guid, $connection2, "/modules/Sepa/sepa_payment_adjustment_add.php") == false) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    $_GET = $container->get(Validator::class)->sanitize($_GET);
    $_POST = $container->get(Validator::class)->sanitize($_POST);
    $family_details = $_GET['family_details'] ?? '';
    
    $page->breadcrumbs
        ->add(__('Payment adjustment'), '/sepa_payment_adjustment_manage.php')
        ->add(__('Add payment_adjustment'));

    $adjustmentGateway = $container->get(SepaPaymentAdjustmentGateway::class);
    $SepaGateway = $container->get(SepaGateway::class);
    $criteria = $SepaGateway->newQueryCriteria(false)->sortBy(['payer']);

    $editLink = '';
    if (isset($_GET['editID'])) {
        $editLink = $session->get('absoluteURL') . '/index.php?q=/modules/Sepa/sepa_payment_adjustment_edit.php&gibbonSEPAPaymentAdjustmentID=' . $_GET['editID'];
    }
    $page->return->setEditLink($editLink);

    $form = Form::create('payment_adjustment', $session->get('absoluteURL') . '/modules/Sepa/sepa_payment_adjustment_addProcess.php');
    $form->addHiddenValue('address', $session->get('address'));
    $form->addHiddenValue('family_details', $family_details);
    
    // SEPA Id=> Names
    $gibbonSEPAID = $_GET['gibbonSEPAID'] ?? null;
    $sepaList = $SepaGateway->getSEPAList($criteria, null);
    $sepaOptions = array_column($sepaList->toArray(), 'payer', 'gibbonSEPAID');

    $row = $form->addRow();
    $row->addLabel('gibbonSEPAID_l', __('SEPA Account'));
    $select = $row->addSelect('gibbonSEPAID')->fromArray($sepaOptions)->placeholder(__(''))->required();
    if ($gibbonSEPAID && array_key_exists($gibbonSEPAID, $sepaOptions)) {
        $select->selected($gibbonSEPAID)->disabled();
        $form->addHiddenValue('gibbonSEPAID', $gibbonSEPAID);
    }

    $row = $form->addRow();
    $row->addLabel('amount', __('Amount'));
    $row->addNumber('amount')->required()->decimalPlaces(2)->minimum(0.01);

    $row = $form->addRow();
    $row->addLabel('description', __('Description'));
    $row->addTextArea('description')->required()->setRows(3);

    $row = $form->addRow();
    $row->addLabel('note', __('Note'));
    $row->addTextArea('note')->setRows(3);

    $form->addHiddenValue('gibbonPersonID', $session->get('gibbonPersonID'));

    $row = $form->addRow();
    $row->addFooter();
    $row->addSubmit();

    echo $form->getOutput();
}
