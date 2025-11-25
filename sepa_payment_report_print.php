<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community
Copyright © 2010, Gibbon Foundation
*/

use Gibbon\Module\Sepa\Domain\SepaGateway;
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

// Get date range from query parameters (already in database format Y-m-d)
$fromDate = $_GET['fromDate'] ?? '';
$toDate = $_GET['toDate'] ?? '';

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

// Fetch payments
$criteria = $SepaGateway->newQueryCriteria(false)
    ->sortBy(['booking_date']);

$payments = $SepaGateway->getPaymentsByDateRange($fromDate, $toDate, $criteria);
$totalSum = $SepaGateway->getPaymentsSumByDateRange($fromDate, $toDate);

// Get organization name from settings
$organizationName = $settingGateway->getSettingByScope('System', 'organisationName');
$organizationAddress = $settingGateway->getSettingByScope('System', 'organisationAddress');
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title><?php echo __('Payment Report'); ?></title>
    <style>
        @media print {
            .no-print {
                display: none;
            }
        }

        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            font-size: 12pt;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #333;
            padding-bottom: 20px;
        }

        .header h1 {
            margin: 5px 0;
            font-size: 24pt;
        }

        .header p {
            margin: 3px 0;
            color: #666;
        }

        .report-info {
            margin-bottom: 20px;
            padding: 10px;
            background-color: #f5f5f5;
            border-radius: 5px;
        }

        .report-info p {
            margin: 5px 0;
        }

        .summary {
            margin-bottom: 20px;
            padding: 15px;
            background-color: #e8f4f8;
            border-left: 4px solid #2196F3;
        }

        .summary h2 {
            margin-top: 0;
            color: #1976D2;
        }

        .summary p {
            margin: 8px 0;
            font-size: 14pt;
        }

        .payment-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        .payment-table th,
        .payment-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }

        .payment-table th {
            background-color: #4CAF50;
            color: white;
            font-weight: bold;
        }

        .payment-table tr:nth-child(even) {
            background-color: #f2f2f2;
        }

        .payment-table tr:hover {
            background-color: #ddd;
        }

        .amount {
            text-align: right;
        }

        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #333;
            text-align: center;
            font-size: 10pt;
            color: #666;
        }

        .no-print {
            margin-bottom: 20px;
        }

        .print-button {
            background-color: #4CAF50;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 5px;
            cursor: pointer;
            border: none;
            font-size: 14pt;
        }

        .print-button:hover {
            background-color: #45a049;
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button class="print-button" onclick="window.print();"><?php echo __('Print'); ?></button>
        <button class="print-button" onclick="window.close();"><?php echo __('Close'); ?></button>
    </div>

    <div class="header">
        <h1><?php echo $organizationName; ?></h1>
        <?php if (!empty($organizationAddress)): ?>
            <p><?php echo nl2br($organizationAddress); ?></p>
        <?php endif; ?>
        <h2><?php echo __('Payment Report'); ?></h2>
    </div>

    <div class="report-info">
        <p><strong><?php echo __('Report Generated'); ?>:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
        <p><strong><?php echo __('Generated By'); ?>:</strong> <?php echo $session->get('preferredName') . ' ' . $session->get('surname'); ?></p>
    </div>

    <div class="summary">
        <h2><?php echo __('Summary'); ?></h2>
        <p><strong><?php echo __('Period'); ?>:</strong> <?php echo dateConvertBack($guid, $fromDate); ?> - <?php echo dateConvertBack($guid, $toDate); ?></p>
        <p><strong><?php echo __('Total Number of Payments'); ?>:</strong> <?php echo $payments->getResultCount(); ?></p>
        <p><strong><?php echo __('Total Amount'); ?>:</strong> <?php echo number_format($totalSum, 2); ?></p>
    </div>

    <?php if ($payments->getResultCount() > 0): ?>
        <table class="payment-table">
            <thead>
                <tr>
                    <th><?php echo __('Date'); ?></th>
                    <th><?php echo __('Payer'); ?></th>
                    <th><?php echo __('Family'); ?></th>
                    <th><?php echo __('Amount'); ?></th>
                    <th><?php echo __('Method'); ?></th>
                    <th><?php echo __('Reference'); ?></th>
                    <th><?php echo __('Message'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payments as $payment): ?>
                    <tr>
                        <td><?php echo dateConvertBack($guid, $payment['booking_date']); ?></td>
                        <td><?php echo htmlspecialchars($payment['payer']); ?></td>
                        <td><?php echo htmlspecialchars($payment['familyName'] ?? '-'); ?></td>
                        <td class="amount"><?php echo number_format($payment['amount'], 2); ?></td>
                        <td><?php echo htmlspecialchars($payment['payment_method']); ?></td>
                        <td><?php echo htmlspecialchars($payment['transaction_reference'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($payment['transaction_message'] ?? '-'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="3"><?php echo __('Total'); ?></th>
                    <th class="amount"><?php echo number_format($totalSum, 2); ?></th>
                    <th colspan="3"></th>
                </tr>
            </tfoot>
        </table>
    <?php else: ?>
        <p><?php echo __('No payments found for the selected date range.'); ?></p>
    <?php endif; ?>

    <div class="footer">
        <p><?php echo __('This report was generated automatically by the SEPA Payment Management System'); ?></p>
        <p><?php echo sprintf(__('Generated on %s'), date('Y-m-d H:i:s')); ?></p>
    </div>
</body>
</html>
