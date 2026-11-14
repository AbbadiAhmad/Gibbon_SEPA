<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community
Copyright © 2010, Gibbon Foundation
*/

use Gibbon\Forms\Form;
use Gibbon\Tables\DataTable;
use Gibbon\Module\Sepa\Domain\SepaGateway;
use Gibbon\Module\Sepa\Security\IbanCipher;
use Gibbon\Data\Validator;
$_GET = $container->get(Validator::class)->sanitize($_GET);
$_POST = $container->get(Validator::class)->sanitize($_POST);

require_once __DIR__ . '/moduleFunctions.php';
require_once __DIR__ . '/../../gibbon.php';

if (isActionAccessible($guid, $connection2, "/modules/Sepa/sepa_payment_manage.php") == false) {
    // Access denied
    $page->addError(__m('You do not have access to this action.'));
} else {
    $page->breadcrumbs
        ->add(__m('Manage SEPA Payment Entries'));

    $SepaGateway = $container->get(SepaGateway::class);

    $search = $_GET['search'] ?? '';
    $schoolYears = $SepaGateway->getAllSchoolYears();
    $schoolYearID = sepaSelectedSchoolYear($_GET, $session->get('gibbonSchoolYearID'));

    // CRITERIA
    $criteria = $SepaGateway->newQueryCriteria(true)
        ->searchBy(['gibbonSEPAPaymentEntry.payer', 'gibbonSEPAPaymentEntry.booking_date', 'gibbonSEPAPaymentEntry.amount', 'gibbonSEPAPaymentEntry.transaction_message', 'gibbonSEPAPaymentEntry.transaction_reference', 'gibbonSEPAPaymentEntry.note'], $search)
        ->sortBy(['timestamp'])
        ->fromPOST();

    $form = Form::createSearch();

    $row = $form->addRow();
        $row->addLabel('search', __m('Search For'))
            ->description(__m('payer, booking date, amount, transaction message, transaction reference, note'));
        $row->addTextField('search')->setValue($criteria->getSearchText());

    sepaAddSchoolYearRow($form, $schoolYears, $schoolYearID);

    $form->addRow()->addSearchSubmit('', __m('Clear Search'));

    echo $form->getOutput();

    $payments = $SepaGateway->getAllPayments($criteria, $schoolYearID);

    // DATA TABLE
    $table = DataTable::createPaginated('payments', $criteria);
    $table->setTitle(__m('Manage SEPA Payment Entries'));

    $table->addHeaderAction('add', __m('Add'))
        ->setURL('/modules/Sepa/sepa_payment_add.php')
        ->addParam('search', $search)
        ->addParam('schoolYearID', $schoolYearID)
        ->displayLabel();

    $table->addMetaData('filterOptions', [
        'payment_method:card' => __m('Payment Method').': '.__m('Card'),
        'payment_method:cash' => __m('Payment Method').': '.__m('Cash'),
        'payment_method:bank_transfer' => __m('Payment Method').': '.__m('Bank Transfer'),
    ]);

    $table->addColumn('booking_date', __m('Booking Date'))
        ->sortable(['booking_date']);

    $table->addColumn('payer', __m('payer'))
        ->sortable(['payer']);
    $table->addColumn('familyName', __m('Family Name'))
        ->sortable(['familyName']);

    $table->addColumn('amount', __m('Amount'))
        ->sortable(['amount']);

    $table->addColumn('payment_method', __m('Payment Method'))
        ->sortable(['payment_method']);

    $table->addColumn('transaction_message', __m('Transaction Message'))
        ->sortable(['transaction_message']);

    $table->addColumn('yearName', __m('Academic Year'))
        ->sortable(['yearName']);

    $table->addColumn('IBAN', __m('IBAN'))
        ->notSortable()
        ->format(function ($row) {
            return htmlspecialchars(IbanCipher::format(IbanCipher::decryptForDisplay($row['IBAN'])), ENT_QUOTES, 'UTF-8');
        });

    $table->addColumn('transaction_reference', __m('Transaction Reference'))
        ->sortable(['transaction_reference']);

    $table->addColumn('note', __m('Note'))
        ->sortable(['note']);

    $table->addColumn('gibbonUser', __m('Entry User'))
        ->sortable(['gibbonUser']);

    // $table->addColumn('timestamp', __m('Entry Timestamp'))
    //     ->sortable(['timestamp']);

    $table->addActionColumn()
        ->addParam('gibbonSEPAPaymentRecordID')
        ->format(function ($values, $actions) {
            $actions->addAction('edit', __m('Edit'))
                ->setURL('/modules/Sepa/sepa_payment_edit.php');
            $actions->addAction('delete', __m('Delete'))
                ->setURL('/modules/Sepa/sepa_payment_delete.php');
        });

    echo $table->render($payments);
}
