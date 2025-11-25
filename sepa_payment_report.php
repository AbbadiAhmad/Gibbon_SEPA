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

if (isActionAccessible($guid, $connection2, "/modules/Sepa/sepa_payment_report.php") == false) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    $page->breadcrumbs
        ->add(__('Payment Report by Date Range'));

    $SepaGateway = $container->get(SepaGateway::class);

    // Get date range from query parameters
    $fromDate = $_GET['fromDate'] ?? '';
    $toDate = $_GET['toDate'] ?? '';

    // Create form for date range selection
    $form = Form::create('dateRangeForm', $session->get('absoluteURL') . '/index.php?q=/modules/Sepa/sepa_payment_report.php');
    $form->setTitle(__('Select Date Range'));

    $row = $form->addRow();
        $row->addLabel('fromDate', __('From Date'));
        $row->addDate('fromDate')
            ->required()
            ->setValue($fromDate);

    $row = $form->addRow();
        $row->addLabel('toDate', __('To Date'));
        $row->addDate('toDate')
            ->required()
            ->setValue($toDate);

    $row = $form->addRow();
        $row->addSubmit(__('Generate Report'));

    echo $form->getOutput();

    // Display results if dates are provided
    if (!empty($fromDate) && !empty($toDate)) {
        // Validate date range
        if (strtotime($fromDate) > strtotime($toDate)) {
            echo "<div class='error'>" . __('From Date must be before or equal to To Date') . "</div>";
        } else {
            // CRITERIA
            $criteria = $SepaGateway->newQueryCriteria(true)
                ->sortBy(['booking_date'])
                ->fromPOST();

            $payments = $SepaGateway->getPaymentsByDateRange($fromDate, $toDate, $criteria);
            $totalSum = $SepaGateway->getPaymentsSumByDateRange($fromDate, $toDate);

            // Display summary
            echo "<div class='linkTop'>";
            echo "<h3>" . __('Summary') . "</h3>";
            echo "<p><strong>" . __('From Date') . ":</strong> " . dateConvertBack($guid, $fromDate) . "</p>";
            echo "<p><strong>" . __('To Date') . ":</strong> " . dateConvertBack($guid, $toDate) . "</p>";
            echo "<p><strong>" . __('Total Payments') . ":</strong> " . $payments->getResultCount() . "</p>";
            echo "<p><strong>" . __('Total Amount') . ":</strong> " . number_format($totalSum, 2) . "</p>";
            echo "</div>";

            // Add print button
            echo "<div class='linkTop'>";
            echo "<a href='" . $session->get('absoluteURL') . "/index.php?q=/modules/Sepa/sepa_payment_report_print.php&fromDate=" . $fromDate . "&toDate=" . $toDate . "' target='_blank' class='button'>" . __('Print Report') . "</a>";
            echo "</div>";

            // DATA TABLE
            $table = DataTable::createPaginated('paymentsReport', $criteria);
            $table->setTitle(__('Payment Report'));

            $table->addColumn('booking_date', __('Booking Date'))
                ->sortable(['booking_date']);

            $table->addColumn('payer', __('Payer'))
                ->sortable(['payer']);

            $table->addColumn('familyName', __('Family Name'))
                ->sortable(['familyName']);

            $table->addColumn('amount', __('Amount'))
                ->format(function ($row) {
                    return number_format($row['amount'], 2);
                })
                ->sortable(['amount']);

            $table->addColumn('payment_method', __('Payment Method'))
                ->sortable(['payment_method']);

            $table->addColumn('transaction_message', __('Transaction Message'))
                ->sortable(['transaction_message']);

            $table->addColumn('IBAN', __('IBAN'))
                ->sortable(['IBAN']);

            $table->addColumn('transaction_reference', __('Transaction Reference'))
                ->sortable(['transaction_reference']);

            $table->addColumn('note', __('Note'))
                ->sortable(['note']);

            echo $table->render($payments);
        }
    }
}
