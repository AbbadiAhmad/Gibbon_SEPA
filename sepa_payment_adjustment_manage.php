<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community
Copyright © 2010, Gibbon Foundation
*/

use Gibbon\Forms\Form;
use Gibbon\Tables\DataTable;
use Gibbon\Module\Sepa\Domain\SepaPaymentAdjustmentGateway;
use Gibbon\Data\Validator;

$_GET = $container->get(Validator::class)->sanitize($_GET);
$_POST = $container->get(Validator::class)->sanitize($_POST);

require_once __DIR__ . '/moduleFunctions.php';
require_once __DIR__ . '/../../gibbon.php';

if (isActionAccessible($guid, $connection2, "/modules/Sepa/sepa_payment_adjustment_manage.php") == false) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    $page->breadcrumbs
        ->add(__('Payment Adjustment'));

    $adjustmentGateway = $container->get(SepaPaymentAdjustmentGateway::class);

    $search = $_GET['search'] ?? '';

    // CRITERIA
    $criteria = $adjustmentGateway->newQueryCriteria(true)
        ->searchBy(['description', 'note'], $search)
        ->sortBy(['timestamp'])
        ->fromPOST();

    $form = Form::createSearch();

    $row = $form->addRow();
    $row->addLabel('search', __('Search For'))
        ->description(__('description, note'));
    $row->addTextField('search')->setValue($criteria->getSearchText());

    $form->addRow()->addSearchSubmit('', __('Clear Search'));

    echo $form->getOutput();

    $adjustment = $adjustmentGateway->getAllAdjustment($criteria);

    // DATA TABLE
    $table = DataTable::createPaginated('adjustment', $criteria);
    $table->setTitle(__('Payment Adjustment'));

    $table->addHeaderAction('add', __('Add'))
        ->setURL('/modules/Sepa/sepa_payment_adjustment_add.php')
        ->addParam('search', $search)
        ->displayLabel();

    $table->addColumn('familyName', __('Family Name'))
        ->sortable(['familyName']);

    $table->addColumn('amount', __('PaymentAdjustment Amount'))
        ->sortable(['amount']);

    $table->addColumn('description', __('Description'))
        ->sortable(['description']);

    $table->addColumn('note', __('Note'))
        ->sortable(['note']);

    $table->addColumn('gibbonPersonID', __('User'));

    $table->addColumn('yearName', __('Academic Year'))
        ->sortable(['yearName']);

    $table->addColumn('timestamp', __('Timestamp'))
        ->sortable(['timestamp']);

    $table->addActionColumn()
        ->addParam('gibbonSEPAPaymentAdjustmentID')
        ->format(function ($values, $actions) {
            $actions->addAction('edit', __('Edit'))
                ->setURL('/modules/Sepa/sepa_payment_adjustment_edit.php');
            $actions->addAction('delete', __('Delete'))
                ->setURL('/modules/Sepa/sepa_payment_adjustment_delete.php');
        });

    echo $table->render($adjustment);
}
