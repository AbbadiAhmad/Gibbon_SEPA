<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community
Copyright © 2010, Gibbon Foundation
*/

use Gibbon\Forms\Form;
use Gibbon\Tables\DataTable;
use Gibbon\Module\Sepa\Domain\SepaGateway;
use Gibbon\Data\Validator;
$_GET = $container->get(Validator::class)->sanitize($_GET);
$_POST = $container->get(Validator::class)->sanitize($_POST);

require_once __DIR__ . '/moduleFunctions.php';
require_once __DIR__ . '/../../gibbon.php';

if (isActionAccessible($guid, $connection2, "/modules/Sepa/sepa_payment_manage.php") == false) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    $page->breadcrumbs
        ->add(__('Manage SEPA Payment Entries'));

    $SepaGateway = $container->get(SepaGateway::class);

    $search = $_GET['search'] ?? '';

    // CRITERIA
    $criteria = $SepaGateway->newQueryCriteria(true)
        ->searchBy(['payer', 'booking_date', 'amount', 'transaction_message', 'IBAN', 'transaction_reference', 'note'], $search)
        ->sortBy(['timestamp'])
        ->fromPOST();

    $form = Form::createSearch();

    $row = $form->addRow();
        $row->addLabel('search', __('Search For'))
            ->description(__('payer, booking date, amount, transaction message, IBAN, transaction reference, note'));
        $row->addTextField('search')->setValue($criteria->getSearchText());

    $form->addRow()->addSearchSubmit('', __('Clear Search'));

    echo $form->getOutput();

    $payments = $SepaGateway->getAllPayments($criteria);

    // DATA TABLE
    $table = DataTable::createPaginated('payments', $criteria);
    $table->setTitle(__('Manage SEPA Payment Entries'));

    $table->addHeaderAction('add', __('Add'))
        ->setURL('/modules/Sepa/sepa_payment_add.php')
        ->addParam('search', $search)
        ->displayLabel();

    $table->addMetaData('filterOptions', [
        'academicYear:' . $session->get('gibbonSchoolYearID') => __('Academic Year').': '.__('Current'),
        'payment_method:card' => __('Payment Method').': '.__('Card'),
        'payment_method:cash' => __('Payment Method').': '.__('Cash'),
        'payment_method:bank_transfer' => __('Payment Method').': '.__('Bank Transfer'),
    ]);

    $table->addColumn('booking_date', __('Booking Date'))
        ->sortable(['booking_date']);

    $table->addColumn('payer', __('payer'))
        ->sortable(['payer']);
    $table->addColumn('familyName', __('Family Name'))
        ->sortable(['familyName']);

    $table->addColumn('amount', __('Amount'))
        ->sortable(['amount']);

    $table->addColumn('payment_method', __('Payment Method'))
        ->sortable(['payment_method']);

    $table->addColumn('transaction_message', __('Transaction Message'))
        ->sortable(['transaction_message']);

    $table->addColumn('yearName', __('Academic Year'))
        ->sortable(['yearName']);

    $table->addColumn('IBAN', __('IBAN'))
        ->sortable(['IBAN']);

    $table->addColumn('transaction_reference', __('Transaction Reference'))
        ->sortable(['transaction_reference']);

    $table->addColumn('note', __('Note'))
        ->sortable(['note']);

    $table->addColumn('gibbonUser', __('Entry User'))
        ->sortable(['gibbonUser']);

    // $table->addColumn('timestamp', __('Entry Timestamp'))
    //     ->sortable(['timestamp']);

    $table->addActionColumn()
        ->addParam('gibbonSEPAPaymentRecordID')
        ->format(function ($values, $actions) {
            $actions->addAction('edit', __('Edit'))
                ->setURL('/modules/Sepa/sepa_payment_edit.php');
            $actions->addAction('delete', __('Delete'))
                ->setURL('/modules/Sepa/sepa_payment_delete.php');
        });

    echo $table->render($payments);
}
