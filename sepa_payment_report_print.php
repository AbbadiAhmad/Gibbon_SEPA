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
$_POST = $container->get(Validator::class)->sanitize($_POST);

require_once __DIR__ . '/moduleFunctions.php';
require_once __DIR__ . '/../../gibbon.php';

// Check access
if (isActionAccessible($guid, $connection2, "/modules/Sepa/sepa_payment_report_print.php") == false) {
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'></head><body>";
    echo "<div class='error'>You do not have access to this action.</div>";
    echo "</body></html>";
    exit;
}

$SepaGateway = $container->get(SepaGateway::class);
$settingGateway = $container->get(SettingGateway::class);

// Get date range and SEPA ID from POST parameters (secure from tampering)
$fromDate = $_POST['fromDate'] ?? '';
$toDate = $_POST['toDate'] ?? '';
$gibbonSEPAID = $_POST['gibbonSEPAID'] ?? '';

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

// Get SEPA account info and family information if filtering
$sepaAccountInfo = '';
$familyInfoHtml = '';
if ($sepaFilter) {
    // Get SEPA data using getFamilySEPA with the SEPA ID
    $sepaListCriteria = $SepaGateway->newQueryCriteria(false);
    $sepaListData = $SepaGateway->getSEPAList($sepaListCriteria, $sepaFilter);
    $sepaData = $sepaListData->toArray();

    if (!empty($sepaData) && isset($sepaData[0])) {
        $sepaData = $sepaData[0];
        // Removed IBAN from display for security
        $sepaAccountInfo = '<p><strong>SEPA Account:</strong> ' . htmlspecialchars($sepaData['payer']) . '</p>';

        // Get family members information
        if (!empty($sepaData['gibbonFamilyID'])) {
            $adults = $SepaGateway->selectAdultsByFamily($sepaData['gibbonFamilyID'])->fetchAll();
            $children = $SepaGateway->selectChildrenByFamily($sepaData['gibbonFamilyID'])->fetchAll();

            $familyInfoHtml = '<div class="family-info">';
            $familyInfoHtml .= '<h3>Family Information</h3>';
            $familyInfoHtml .= '<p><strong>Family:</strong> ' . htmlspecialchars($sepaData['payer']) . '</p>';

            if (!empty($adults)) {
                $adultCount = 0;
                foreach ($adults as $adult) {
                    $adultCount++;
                    $adultName = $adult['preferredName'] . ' ' . $adult['surname'];
                    $adultLabel = ($adultCount === 1) ? 'First Adult' : 'Second Adult';
                    $familyInfoHtml .= '<p><strong>' . $adultLabel . ':</strong> ' . htmlspecialchars($adultName) . '</p>';
                    if ($adultCount >= 2) break; // Only show first 2 adults
                }
            }

            if (!empty($children)) {
                $familyInfoHtml .= '<p><strong>Children:</strong></p>';
                $familyInfoHtml .= '<ul>';
                foreach ($children as $child) {
                    $childName = $child['preferredName'] . ' ' . $child['surname'];
                    $familyInfoHtml .= '<li>' . htmlspecialchars($childName) . '</li>';
                }
                $familyInfoHtml .= '</ul>';
            }

            $familyInfoHtml .= '</div>';
        }
    }
}

// Build payment table HTML - Simplified to show only Date and Amount
$paymentTableHtml = '';
if ($payments->getResultCount() > 0) {
    $paymentTableHtml .= '<table class="payment-table">';
    $paymentTableHtml .= '<thead>';
    $paymentTableHtml .= '<tr>';
    $paymentTableHtml .= '<th>Date</th>';
    $paymentTableHtml .= '<th>Amount</th>';
    // Commented out columns - uncomment if needed
    // $paymentTableHtml .= '<th>Payer</th>';
    // $paymentTableHtml .= '<th>Family</th>';
    // $paymentTableHtml .= '<th>Method</th>';
    // $paymentTableHtml .= '<th>Reference</th>';
    // $paymentTableHtml .= '<th>Message</th>';
    $paymentTableHtml .= '</tr>';
    $paymentTableHtml .= '</thead>';
    $paymentTableHtml .= '<tbody>';

    foreach ($payments as $payment) {
        $paymentTableHtml .= '<tr>';
        $paymentTableHtml .= '<td>' . Format::date($payment['booking_date']) . '</td>';
        $paymentTableHtml .= '<td class="amount">' . number_format($payment['amount'], 2) . '</td>';
        // Commented out columns - uncomment if needed
        // $paymentTableHtml .= '<td>' . htmlspecialchars($payment['payer']) . '</td>';
        // $paymentTableHtml .= '<td>' . htmlspecialchars($payment['familyName'] ?? '-') . '</td>';
        // $paymentTableHtml .= '<td>' . htmlspecialchars($payment['payment_method']) . '</td>';
        // $paymentTableHtml .= '<td>' . htmlspecialchars($payment['transaction_reference'] ?? '-') . '</td>';
        // $paymentTableHtml .= '<td>' . htmlspecialchars($payment['transaction_message'] ?? '-') . '</td>';
        $paymentTableHtml .= '</tr>';
    }

    $paymentTableHtml .= '</tbody>';
    $paymentTableHtml .= '<tfoot>';
    $paymentTableHtml .= '<tr class="total-row">';
    $paymentTableHtml .= '<th>Total</th>';
    $paymentTableHtml .= '<th class="amount">' . number_format($totalSum, 2) . '</th>';
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
    'SEPA_ACCOUNT_INFO' => $sepaAccountInfo,
    'FAMILY_INFO' => $familyInfoHtml
];

// Path to template file
$templatePath = __DIR__ . '/templates/payment_report_template.html';

// Render the template
$htmlOutput = renderTemplate($templatePath, $templateData, true);

// Output directly as standalone HTML (bypass Gibbon's page wrapper)
header('Content-Type: text/html; charset=utf-8');
echo $htmlOutput;
exit; // Important: exit to prevent Gibbon from adding its layout
