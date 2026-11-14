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
use Gibbon\Services\Format;
use Gibbon\Data\Validator;
$_GET = $container->get(Validator::class)->sanitize($_GET);
$_POST = $container->get(Validator::class)->sanitize($_POST);

require_once __DIR__ . '/moduleFunctions.php';
require_once __DIR__ . '/../../gibbon.php';

if (isActionAccessible($guid, $connection2, "/modules/Sepa/sepa_payment_report.php") == false) {
    // Access denied
    $page->addError(__m('You do not have access to this action.'));
} else {
    $page->breadcrumbs
        ->add(__m('Payment Report by Date Range'));

    $SepaGateway = $container->get(SepaGateway::class);

    // Get year type and SEPA ID from query parameters
    $yearType = $_GET['yearType'] ?? 'academic';
    $gibbonSEPAID = $_GET['gibbonSEPAID'] ?? '';

    // Calculate date range based on year type
    $currentYear = date('Y');
    $fromDate = $_GET['fromDate'] ?? $currentYear . '-01-01';
    $toDate = $_GET['toDate'] ?? $currentYear . '-12-31';
    $gibbonSEPAID = $_GET['gibbonSEPAID'] ?? '';

    // Get all SEPA accounts for dropdown
    $criteria = $SepaGateway->newQueryCriteria(false)
        ->sortBy(['payer']);
    $sepaList = $SepaGateway->getSEPAList($criteria, null);

    // Create form for date range and SEPA selection
    $form = Form::create('dateRangeForm', $session->get('absoluteURL') . '/index.php?q=/modules/Sepa/sepa_payment_report.php');
    $form->setTitle(__m('Select Date Range and SEPA Account'));
    $form->setMethod('GET');
    $form->addHiddenValue('q', '/modules/Sepa/sepa_payment_report.php');

    $row = $form->addRow();
    $row->addLabel('fromDate', __m('From Date'));
    $row->addDate('fromDate')
        ->required()
        ->setValue($fromDate);

    $row = $form->addRow();
    $row->addLabel('toDate', __m('To Date'));
    $row->addDate('toDate')
        ->required()
        ->setValue($toDate);

    $row = $form->addRow();
    $row->addLabel('gibbonSEPAID', __m('SEPA Account'));
    $sepaOptions = ['all' => __m('All SEPA Accounts')];
    foreach ($sepaList as $sepa) {

        $sepaOptions[$sepa['gibbonSEPAID']] = $sepa['payer'];
    }
    $row->addSelect('gibbonSEPAID')
        ->fromArray($sepaOptions)
        ->selected($gibbonSEPAID);

    $row = $form->addRow();
    $row->addSubmit(__m('Generate Report'));

    echo $form->getOutput();

    // Display results if dates are provided AND a specific SEPA account is selected
    if (!empty($fromDate) && !empty($toDate)) {
        // Check if a specific SEPA account is selected
        if (empty($gibbonSEPAID) || $gibbonSEPAID === 'all') {
            echo "<div class='message'>" . __m('Please select a specific SEPA account to generate the report.') . "</div>";
        } else {
            // Convert dates from display format to database format if needed
            $fromDateDB = Format::dateConvert($fromDate);
            $toDateDB = Format::dateConvert($toDate);

            // Validate date range
            if (strtotime($fromDateDB) > strtotime($toDateDB)) {
                echo "<div class='error'>" . __m('From Date must be before or equal to To Date') . "</div>";
            } else {
                // CRITERIA
                $criteria = $SepaGateway->newQueryCriteria(true)
                    ->sortBy(['booking_date'])
                    ->fromPOST();

                // Use SEPA ID filter if selected (not "all")
                $sepaFilter = ($gibbonSEPAID !== '' && $gibbonSEPAID !== 'all') ? $gibbonSEPAID : null;

                $payments = $SepaGateway->getPaymentsByDateRange($fromDateDB, $toDateDB, $criteria, $sepaFilter);
                $totalSum = $SepaGateway->getPaymentsSumByDateRange($fromDateDB, $toDateDB, $sepaFilter);

                // Get family member information if SEPA account is selected
                $familyInfo = '';
                if ($sepaFilter) {
                    // Get SEPA data
                    $sepaListCriteria = $SepaGateway->newQueryCriteria(false);
                    $sepaListData = $SepaGateway->getSEPAList($sepaListCriteria, $sepaFilter);
                    $sepaDataArray = $sepaListData->toArray();

                    if (!empty($sepaDataArray) && isset($sepaDataArray[0])) {
                        $sepaData = $sepaDataArray[0];

                        if ($sepaData && !empty($sepaData['gibbonFamilyID'])) {
                            $adults = $SepaGateway->selectAdultsByFamily($sepaData['gibbonFamilyID'])->fetchAll();
                            $children = $SepaGateway->selectChildrenByFamily($sepaData['gibbonFamilyID'])->fetchAll();

                            $familyInfo .= "<h3>" . __m('Family Information') . "</h3>";
                            $familyInfo .= "<p><strong>" . __m('Family') . ":</strong> " . htmlspecialchars($sepaData['payer']) . "</p>";

                            if (!empty($adults)) {
                                $adultCount = 0;
                                foreach ($adults as $adult) {
                                    $adultCount++;
                                    $adultName = $adult['preferredName'] . ' ' . $adult['surname'];
                                    $adultLabel = ($adultCount === 1) ? __m('First Adult') : __m('Second Adult');
                                    $familyInfo .= "<p><strong>" . $adultLabel . ":</strong> " . htmlspecialchars($adultName) . "</p>";
                                    if ($adultCount >= 2)
                                        break; // Only show first 2 adults
                                }
                            }

                            if (!empty($children)) {
                                $familyInfo .= "<p><strong>" . __m('Children') . ":</strong></p><ul>";
                                foreach ($children as $child) {
                                    $childName = $child['preferredName'] . ' ' . $child['surname'];
                                    $familyInfo .= "<li>" . htmlspecialchars($childName) . "</li>";
                                }
                                $familyInfo .= "</ul>";
                            }
                        }
                    }
                }

                // Display summary with RTL support
                $textDir = ($session->get('i18n')['direction'] ?? 'ltr');
                echo "<div dir='" . $textDir . "'>";
                echo "<h3>" . __m('Summary') . "</h3>";
                echo "<p><strong>" . __m('From Date') . ":</strong>" . Format::date($fromDateDB) . "</p>";
                echo "<p><strong>" . __m('To Date') . ":</strong> " . Format::date($toDateDB) . "</p>";
                echo "<p><strong>" . __m('Total Payments') . ":</strong> " . $payments->getResultCount() . "</p>";
                echo "<p><strong>" . __m('Total Amount') . ":</strong> " . number_format($totalSum, 2) . "</p>";
                echo "</div>";

                // Display family info with RTL support
                if (!empty($familyInfo)) {
                    echo "<div dir='" . $textDir . "'>";
                    echo $familyInfo;
                    echo "</div>";
                }

                // Add print button with POST form
                echo "<p><div>";
                echo "<form method='POST' action='" . $session->get('absoluteURL') . "/index.php?q=/modules/Sepa/sepa_payment_report_print.php' target='_blank'>";
                echo "<input type='hidden' name='fromDate' value='" . htmlspecialchars($fromDateDB) . "'>";
                echo "<input type='hidden' name='toDate' value='" . htmlspecialchars($toDateDB) . "'>";
                if ($sepaFilter) {
                    echo "<input type='hidden' name='gibbonSEPAID' value='" . htmlspecialchars($sepaFilter) . "'>";
                }
                echo "<button type='submit' class='button'>" . __m('Print Report') . "</button>";
                echo "</form>";
                echo "</div></p>";

                // DATA TABLE - Showing only Date and Amount
                $table = DataTable::createPaginated('paymentsReport', $criteria);
                $table->setTitle(__m('Payment Report'));

                $table->addColumn('booking_date', __m('Booking Date'))
                    ->sortable(['booking_date']);

                $table->addColumn('amount', __m('Amount'))
                    ->format(function ($row) {
                        return number_format($row['amount'], 2);
                    })
                    ->sortable(['amount']);


                $table->addColumn('payer', __m('Payer'))
                    ->sortable(['payer']);

                $table->addColumn('familyName', __m('Family Name'))
                    ->sortable(['familyName']);

                $table->addColumn('payment_method', __m('Payment Method'))
                    ->sortable(['payment_method']);

                $table->addColumn('transaction_message', __m('Transaction Message'))
                    ->sortable(['transaction_message']);

                $table->addColumn('IBAN', __m('IBAN'))
                    ->notSortable()
                    ->format(function ($row) {
                        return htmlspecialchars(IbanCipher::format(IbanCipher::decryptForDisplay($row['IBAN'])), ENT_QUOTES, 'UTF-8');
                    });

                $table->addColumn('transaction_reference', __m('Transaction Reference'))
                    ->sortable(['transaction_reference']);

                $table->addColumn('note', __m('Note'))
                    ->sortable(['note']);

                echo $table->render($payments);
            }
        }
    }
}
