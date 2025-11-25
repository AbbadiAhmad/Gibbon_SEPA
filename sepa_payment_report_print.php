<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community
Copyright © 2010, Gibbon Foundation
*/

use Gibbon\Module\Sepa\Domain\SepaGateway;
use Gibbon\Services\Format;
use Gibbon\Data\Validator;
use Gibbon\Domain\System\SettingGateway;

$_GET = $container->get(Validator::class)->sanitize($_GET);

require_once __DIR__ . '/moduleFunctions.php';
require_once __DIR__ . '/../../gibbon.php';

if (isActionAccessible($guid, $connection2, "/modules/Sepa/sepa_payment_report_print.php") == false) {
    // Access denied
    echo "<div class='error'>" . __('You do not have access to this action.') . "</div>";
    exit;
}

$SepaGateway = $container->get(SepaGateway::class);
$settingGateway = $container->get(SettingGateway::class);

// Get date range and SEPA ID from query parameters (already in database format Y-m-d)
$fromDate = $_GET['fromDate'] ?? '';
$toDate = $_GET['toDate'] ?? '';
$gibbonSEPAID = $_GET['gibbonSEPAID'] ?? '';

if (empty($fromDate) || empty($toDate)) {
    echo "<div class='error'>" . __('Please provide both from and to dates.') . "</div>";
    exit;
}

// Validate date range
if (strtotime($fromDate) > strtotime($toDate)) {
    echo "<div class='error'>" . __('From Date must be before or equal to To Date') . "</div>";
    exit;
}

// Dates are already in Y-m-d format from the report page
$sepaFilter = (!empty($gibbonSEPAID) && $gibbonSEPAID !== 'all') ? $gibbonSEPAID : null;

// Fetch payments
$criteria = $SepaGateway->newQueryCriteria(false)
    ->sortBy(['booking_date']);

$payments = $SepaGateway->getPaymentsByDateRange($fromDate, $toDate, $criteria, $sepaFilter);
$totalSum = $SepaGateway->getPaymentsSumByDateRange($fromDate, $toDate, $sepaFilter);

// Get organization name from settings
$organizationName = $settingGateway->getSettingByScope('System', 'organisationName');
$organizationAddress = $settingGateway->getSettingByScope('System', 'organisationAddress');

// Get SEPA account info if filtering
$sepaAccountInfo = '';
if ($sepaFilter) {
    $sepaData = $SepaGateway->selectOne($sepaFilter);
    if ($sepaData) {
        $sepaAccountInfo = '<p><strong>SEPA Account:</strong> ' . htmlspecialchars($sepaData['payer']) . ' (' . htmlspecialchars($sepaData['IBAN']) . ')</p>';
    }
}

// Build payment table HTML
$paymentTableHtml = '';
if ($payments->getResultCount() > 0) {
    $paymentTableHtml .= '<table class="payment-table">';
    $paymentTableHtml .= '<thead>';
    $paymentTableHtml .= '<tr>';
    $paymentTableHtml .= '<th>Date</th>';
    $paymentTableHtml .= '<th>Payer</th>';
    $paymentTableHtml .= '<th>Family</th>';
    $paymentTableHtml .= '<th>Amount</th>';
    $paymentTableHtml .= '<th>Method</th>';
    $paymentTableHtml .= '<th>Reference</th>';
    $paymentTableHtml .= '<th>Message</th>';
    $paymentTableHtml .= '</tr>';
    $paymentTableHtml .= '</thead>';
    $paymentTableHtml .= '<tbody>';

    foreach ($payments as $payment) {
        $paymentTableHtml .= '<tr>';
        $paymentTableHtml .= '<td>' . Format::date($payment['booking_date']) . '</td>';
        $paymentTableHtml .= '<td>' . htmlspecialchars($payment['payer']) . '</td>';
        $paymentTableHtml .= '<td>' . htmlspecialchars($payment['familyName'] ?? '-') . '</td>';
        $paymentTableHtml .= '<td class="amount">' . number_format($payment['amount'], 2) . '</td>';
        $paymentTableHtml .= '<td>' . htmlspecialchars($payment['payment_method']) . '</td>';
        $paymentTableHtml .= '<td>' . htmlspecialchars($payment['transaction_reference'] ?? '-') . '</td>';
        $paymentTableHtml .= '<td>' . htmlspecialchars($payment['transaction_message'] ?? '-') . '</td>';
        $paymentTableHtml .= '</tr>';
    }

    $paymentTableHtml .= '</tbody>';
    $paymentTableHtml .= '<tfoot>';
    $paymentTableHtml .= '<tr>';
    $paymentTableHtml .= '<th colspan="3">Total</th>';
    $paymentTableHtml .= '<th class="amount">' . number_format($totalSum, 2) . '</th>';
    $paymentTableHtml .= '<th colspan="3"></th>';
    $paymentTableHtml .= '</tr>';
    $paymentTableHtml .= '</tfoot>';
    $paymentTableHtml .= '</table>';
} else {
    $paymentTableHtml = '<p>No payments found for the selected date range.</p>';
}

// Prepare template data
$templateData = [
    'ORGANIZATION_NAME' => $organizationName,
    'ORGANIZATION_ADDRESS' => !empty($organizationAddress) ? '<p>' . nl2br(htmlspecialchars($organizationAddress)) . '</p>' : '',
    'REPORT_TITLE' => 'Payment Report',
    'GENERATED_DATE' => date('Y-m-d H:i:s'),
    'GENERATED_BY' => $session->get('preferredName') . ' ' . $session->get('surname'),
    'FROM_DATE' => Format::date($fromDate),
    'TO_DATE' => Format::date($toDate),
    'TOTAL_PAYMENTS' => $payments->getResultCount(),
    'TOTAL_AMOUNT' => number_format($totalSum, 2),
    'PAYMENT_TABLE' => $paymentTableHtml,
    'SEPA_ACCOUNT_INFO' => $sepaAccountInfo
];

// Path to template file
$templatePath = __DIR__ . '/templates/payment_report_template.html';

// Render and output the template
echo renderTemplate($templatePath, $templateData, true);
