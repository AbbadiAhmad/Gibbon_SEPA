<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community
Copyright © 2010, Gibbon Foundation
*/

use Gibbon\Forms\Form;
use Gibbon\Module\Sepa\Domain\SepaDiscountGateway;
use Gibbon\Module\Sepa\Domain\SepaGateway;
use Gibbon\Data\Validator;
use Gibbon\Domain\QueryCriteria;


require_once __DIR__ . '/moduleFunctions.php';
require_once __DIR__ . '/../../gibbon.php';

if (isActionAccessible($guid, $connection2, "/modules/Sepa/sepa_discount_add.php") == false) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    $_GET = $container->get(Validator::class)->sanitize($_GET);
    $_POST = $container->get(Validator::class)->sanitize($_POST);
    $page->breadcrumbs
        ->add(__('Manage SEPA Discounts'), '/sepa_discount_manage.php')
        ->add(__('Add Discount'));

    $SepaDiscountGateway = $container->get(SepaDiscountGateway::class);
    $SepaGateway = $container->get(SepaGateway::class);
    $criteria = $container->get(QueryCriteria::class);

    $editLink = '';
    if (isset($_GET['editID'])) {
        $editLink = $session->get('absoluteURL') . '/index.php?q=/modules/Sepa/sepa_discount_edit.php&gibbonSEPADiscountID=' . $_GET['editID'];
    }
    $page->return->setEditLink($editLink);

    $form = Form::create('discount', $session->get('absoluteURL') . '/modules/Sepa/sepa_discount_addProcess.php');
    $form->addHiddenValue('address', $session->get('address'));

    $gibbonSEPAID = $_GET['gibbonSEPAID'] ?? null;
    $sepaList = $SepaGateway->getSEPAList($criteria, null);
    $row = $form->addRow();
    $row->addLabel('gibbonSEPAID', __('SEPA Account'));
    $sepaOptions = array_column($sepaList->toArray(), 'payer', 'gibbonSEPAID');
    $select = $row->addSelect('gibbonSEPAID')->fromArray($sepaOptions)->placeholder(__(''))->required();
    if ($gibbonSEPAID && array_key_exists($gibbonSEPAID, $sepaOptions)) {
        $select->selected($gibbonSEPAID)->disabled();
    }

    $row = $form->addRow();
    $row->addLabel('discountAmount', __('Discount Amount'));
    $row->addNumber('discountAmount')->required()->decimalPlaces(2)->minimum(0.01);

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
