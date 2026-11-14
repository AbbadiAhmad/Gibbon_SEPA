<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community
Copyright © 2010, Gibbon Foundation
*/

use Gibbon\Forms\Form;
use Gibbon\Tables\DataTable;
use Gibbon\Module\Sepa\Domain\SepaGateway;
use Gibbon\Module\Sepa\Domain\SepaPaymentAdjustmentGateway;
use Gibbon\Data\Validator;

$_GET = $container->get(Validator::class)->sanitize($_GET);
$_POST = $container->get(Validator::class)->sanitize($_POST);

require_once __DIR__ . '/moduleFunctions.php';
require_once __DIR__ . '/../../gibbon.php';

if (isActionAccessible($guid, $connection2, "/modules/Sepa/sepa_payment_adjustment_manage.php") == false) {
    // Access denied
    $page->addError(__m('You do not have access to this action.'));
} else {
    $page->breadcrumbs
        ->add(__m('Payment Adjustment'));

    $adjustmentGateway = $container->get(SepaPaymentAdjustmentGateway::class);

    if (!empty($_SESSION[$guid]['sepaBulkNotice'])) {
        echo "<div class='success'>" . htmlspecialchars($_SESSION[$guid]['sepaBulkNotice'], ENT_QUOTES, 'UTF-8') . '</div>';
        unset($_SESSION[$guid]['sepaBulkNotice']);
    }

    $search = $_GET['search'] ?? '';
    $schoolYears = $container->get(SepaGateway::class)->getAllSchoolYears();
    $schoolYearID = sepaSelectedSchoolYear($_GET, $session->get('gibbonSchoolYearID'));

    // CRITERIA
    $criteria = $adjustmentGateway->newQueryCriteria(true)
        ->searchBy(['description', 'note'], $search)
        ->sortBy(['timestamp'])
        ->fromPOST();

    $form = Form::createSearch();

    $row = $form->addRow();
    $row->addLabel('search', __m('Search For'))
        ->description(__m('description, note'));
    $row->addTextField('search')->setValue($criteria->getSearchText());

    sepaAddSchoolYearRow($form, $schoolYears, $schoolYearID);

    $form->addRow()->addSearchSubmit('', __m('Clear Search'));

    echo $form->getOutput();

    $adjustment = $adjustmentGateway->getAllAdjustment($criteria, $schoolYearID);

    // DATA TABLE
    $table = DataTable::createPaginated('adjustment', $criteria);
    $table->setTitle(__m('Payment Adjustment'));

    $table->addHeaderAction('add', __m('Add'))
        ->setURL('/modules/Sepa/sepa_payment_adjustment_add.php')
        ->addParam('search', $search)
        ->addParam('schoolYearID', $schoolYearID)
        ->displayLabel();

    if (isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_payment_adjustment_bulk.php')) {
        $table->addHeaderAction('bulk', __m('Bulk entry'))
            ->setURL('/modules/Sepa/sepa_payment_adjustment_bulk.php')
            ->setIcon('copy')
            ->displayLabel();
    }

    $table->addColumn('familyName', __m('Family Name'))
        ->sortable(['familyName']);

    $table->addColumn('amount', __m('PaymentAdjustment Amount'))
        ->sortable(['amount']);

    $table->addColumn('description', __m('Description'))
        ->sortable(['description']);

    $table->addColumn('note', __m('Note'))
        ->sortable(['note']);

    $table->addColumn('gibbonPersonID', __m('User'));

    $table->addColumn('yearName', __m('Academic Year'))
        ->sortable(['yearName']);

    $table->addColumn('timestamp', __m('Timestamp'))
        ->sortable(['timestamp']);

    $table->addActionColumn()
        ->addParam('gibbonSEPAPaymentAdjustmentID')
        ->format(function ($values, $actions) {
            $actions->addAction('edit', __m('Edit'))
                ->setURL('/modules/Sepa/sepa_payment_adjustment_edit.php');
            $actions->addAction('delete', __m('Delete'))
                ->setURL('/modules/Sepa/sepa_payment_adjustment_delete.php');
        });

    echo $table->render($adjustment);
}
