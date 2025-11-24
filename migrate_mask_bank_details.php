<?php
/*
Gibbon SEPA Module - Bank Details Masking Migration Script
This script:
1. Exports all current IBAN/BIC data to CSV (for external secure storage)
2. Masks all IBANs in the database (format: XX****XXX)
3. Sets all BIC codes to NULL

IMPORTANT: Run this script ONCE to migrate existing data
BACKUP YOUR DATABASE BEFORE RUNNING THIS SCRIPT!
*/

use Gibbon\Module\Sepa\Domain\SepaGateway;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Include Gibbon core
require_once __DIR__ . '/../../gibbon.php';

// Check if user is logged in and has admin access
if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_family_view.php')) {
    die("Error: You do not have permission to run this migration script.");
}

echo "<!DOCTYPE html><html><head><title>SEPA Bank Details Migration</title>";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    .success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 10px; margin: 10px 0; border-radius: 4px; }
    .error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 10px; margin: 10px 0; border-radius: 4px; }
    .warning { background: #fff3cd; border: 1px solid #ffeeba; color: #856404; padding: 10px; margin: 10px 0; border-radius: 4px; }
    .info { background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; padding: 10px; margin: 10px 0; border-radius: 4px; }
    table { border-collapse: collapse; width: 100%; margin: 20px 0; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background-color: #f2f2f2; }
</style></head><body>";

echo "<h1>SEPA Bank Details Masking Migration</h1>";

// Get mode from query parameter
$mode = $_GET['mode'] ?? 'preview';

if ($mode === 'preview') {
    echo "<div class='warning'>";
    echo "<h2>⚠️ PREVIEW MODE</h2>";
    echo "<p><strong>IMPORTANT:</strong> This script will permanently mask IBAN data in your database.</p>";
    echo "<p><strong>Before proceeding:</strong></p>";
    echo "<ul>";
    echo "<li>✅ Backup your database</li>";
    echo "<li>✅ Review the data below to ensure it's correct</li>";
    echo "<li>✅ Download the export file to store full bank details externally</li>";
    echo "</ul>";
    echo "</div>";
}

try {
    // Step 1: Fetch all current SEPA data
    $sql = "SELECT gibbonSEPAID, gibbonFamilyID, payer, IBAN, BIC, SEPA_signedDate, note FROM gibbonSEPA ORDER BY gibbonFamilyID";
    $result = $connection2->query($sql);
    $sepaRecords = $result->fetchAll(PDO::FETCH_ASSOC);

    if (empty($sepaRecords)) {
        echo "<div class='info'>No SEPA records found in database. Nothing to migrate.</div>";
        echo "</body></html>";
        exit;
    }

    echo "<div class='info'>";
    echo "<strong>Found " . count($sepaRecords) . " SEPA records in database</strong>";
    echo "</div>";

    // Step 2: Create export file with full data
    if ($mode === 'preview' || $mode === 'export') {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Set headers
        $sheet->setCellValue('A1', 'gibbonSEPAID');
        $sheet->setCellValue('B1', 'gibbonFamilyID');
        $sheet->setCellValue('C1', 'Payer');
        $sheet->setCellValue('D1', 'IBAN (Full)');
        $sheet->setCellValue('E1', 'BIC');
        $sheet->setCellValue('F1', 'Signed Date');
        $sheet->setCellValue('G1', 'Note');

        // Style headers
        $headerStyle = [
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'CCCCCC']
            ]
        ];
        $sheet->getStyle('A1:G1')->applyFromArray($headerStyle);

        // Add data
        $row = 2;
        foreach ($sepaRecords as $record) {
            $sheet->setCellValue('A' . $row, $record['gibbonSEPAID']);
            $sheet->setCellValue('B' . $row, $record['gibbonFamilyID']);
            $sheet->setCellValue('C' . $row, $record['payer']);
            $sheet->setCellValue('D' . $row, $record['IBAN']);
            $sheet->setCellValue('E' . $row, $record['BIC']);
            $sheet->setCellValue('F' . $row, $record['SEPA_signedDate']);
            $sheet->setCellValue('G' . $row, $record['note']);
            $row++;
        }

        // Auto-size columns
        foreach (range('A', 'G') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Save file
        $exportFileName = 'sepa_bank_details_full_backup_' . date('Y-m-d_His') . '.xlsx';
        $exportFilePath = __DIR__ . '/exports/' . $exportFileName;

        // Create exports directory if it doesn't exist
        if (!is_dir(__DIR__ . '/exports')) {
            mkdir(__DIR__ . '/exports', 0755, true);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($exportFilePath);

        echo "<div class='success'>";
        echo "<h3>✅ Export File Created</h3>";
        echo "<p><strong>File:</strong> <a href='modules/Sepa/exports/{$exportFileName}' download>{$exportFileName}</a></p>";
        echo "<p><strong>Location:</strong> {$exportFilePath}</p>";
        echo "<p><strong>⚠️ IMPORTANT:</strong> Download this file and store it securely OUTSIDE this web application. This contains full IBAN/BIC data.</p>";
        echo "</div>";
    }

    // Step 3: Show preview of what will be masked
    if ($mode === 'preview') {
        echo "<h2>Preview: Data to be Masked</h2>";
        echo "<table>";
        echo "<thead><tr>";
        echo "<th>gibbonSEPAID</th>";
        echo "<th>Family ID</th>";
        echo "<th>Payer</th>";
        echo "<th>Current IBAN</th>";
        echo "<th>Masked IBAN</th>";
        echo "<th>Current BIC</th>";
        echo "<th>Masked BIC</th>";
        echo "</tr></thead>";
        echo "<tbody>";

        $sepaGateway = $container->get(SepaGateway::class);

        foreach ($sepaRecords as $record) {
            $maskedIBAN = $sepaGateway->maskIBAN($record['IBAN']);
            $maskedBIC = $sepaGateway->maskBIC($record['BIC']);

            echo "<tr>";
            echo "<td>{$record['gibbonSEPAID']}</td>";
            echo "<td>{$record['gibbonFamilyID']}</td>";
            echo "<td>" . htmlspecialchars($record['payer']) . "</td>";
            echo "<td>" . htmlspecialchars($record['IBAN']) . "</td>";
            echo "<td><strong>" . htmlspecialchars($maskedIBAN ?? 'NULL') . "</strong></td>";
            echo "<td>" . htmlspecialchars($record['BIC']) . "</td>";
            echo "<td><strong>" . htmlspecialchars($maskedBIC ?? 'NULL') . "</strong></td>";
            echo "</tr>";
        }

        echo "</tbody></table>";

        echo "<div class='warning'>";
        echo "<h3>Ready to Proceed?</h3>";
        echo "<p>If the preview above looks correct and you have downloaded the export file:</p>";
        echo "<p><a href='?mode=execute' style='background: #dc3545; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block;'>🔒 Execute Migration (Permanent)</a></p>";
        echo "</div>";
    }

    // Step 4: Execute migration
    if ($mode === 'execute') {
        echo "<div class='warning'>";
        echo "<h2>🔄 Executing Migration...</h2>";
        echo "</div>";

        $sepaGateway = $container->get(SepaGateway::class);
        $successCount = 0;
        $errorCount = 0;
        $errors = [];

        foreach ($sepaRecords as $record) {
            try {
                $maskedIBAN = $sepaGateway->maskIBAN($record['IBAN']);
                $maskedBIC = $sepaGateway->maskBIC($record['BIC']);

                $updateSql = "UPDATE gibbonSEPA SET IBAN = :IBAN, BIC = :BIC WHERE gibbonSEPAID = :gibbonSEPAID";
                $stmt = $connection2->prepare($updateSql);
                $stmt->execute([
                    'IBAN' => $maskedIBAN,
                    'BIC' => $maskedBIC,
                    'gibbonSEPAID' => $record['gibbonSEPAID']
                ]);

                $successCount++;
            } catch (PDOException $e) {
                $errorCount++;
                $errors[] = "Record ID {$record['gibbonSEPAID']}: " . $e->getMessage();
            }
        }

        // Also update gibbonSEPAPaymentEntry table
        echo "<div class='info'>";
        echo "<h3>Updating Payment Entry table...</h3>";
        echo "</div>";

        $paymentSql = "SELECT gibbonSEPAPaymentRecordID, IBAN FROM gibbonSEPAPaymentEntry WHERE IBAN IS NOT NULL AND IBAN != ''";
        $paymentResult = $connection2->query($paymentSql);
        $paymentRecords = $paymentResult->fetchAll(PDO::FETCH_ASSOC);

        $paymentSuccessCount = 0;
        $paymentErrorCount = 0;

        foreach ($paymentRecords as $payment) {
            try {
                $maskedIBAN = $sepaGateway->maskIBAN($payment['IBAN']);

                $updatePaymentSql = "UPDATE gibbonSEPAPaymentEntry SET IBAN = :IBAN WHERE gibbonSEPAPaymentRecordID = :id";
                $stmt = $connection2->prepare($updatePaymentSql);
                $stmt->execute([
                    'IBAN' => $maskedIBAN,
                    'id' => $payment['gibbonSEPAPaymentRecordID']
                ]);

                $paymentSuccessCount++;
            } catch (PDOException $e) {
                $paymentErrorCount++;
                $errors[] = "Payment Record ID {$payment['gibbonSEPAPaymentRecordID']}: " . $e->getMessage();
            }
        }

        // Display results
        if ($errorCount === 0 && $paymentErrorCount === 0) {
            echo "<div class='success'>";
            echo "<h2>✅ Migration Completed Successfully!</h2>";
            echo "<p><strong>SEPA Records masked:</strong> {$successCount}</p>";
            echo "<p><strong>Payment Entry records masked:</strong> {$paymentSuccessCount}</p>";
            echo "<p><strong>All IBAN data has been masked in the format: XX****XXX</strong></p>";
            echo "<p><strong>All BIC codes have been set to NULL</strong></p>";
            echo "</div>";

            echo "<div class='warning'>";
            echo "<h3>⚠️ Next Steps:</h3>";
            echo "<ul>";
            echo "<li>✅ Verify masked data in the SEPA module</li>";
            echo "<li>✅ Ensure the exported Excel file is stored securely OUTSIDE this application</li>";
            echo "<li>✅ Delete this migration script file (migrate_mask_bank_details.php) for security</li>";
            echo "<li>✅ Delete the exports/ directory after downloading the backup file</li>";
            echo "</ul>";
            echo "</div>";
        } else {
            echo "<div class='error'>";
            echo "<h2>⚠️ Migration Completed with Errors</h2>";
            echo "<p><strong>Successful SEPA updates:</strong> {$successCount}</p>";
            echo "<p><strong>Failed SEPA updates:</strong> {$errorCount}</p>";
            echo "<p><strong>Successful Payment Entry updates:</strong> {$paymentSuccessCount}</p>";
            echo "<p><strong>Failed Payment Entry updates:</strong> {$paymentErrorCount}</p>";
            echo "<h3>Errors:</h3>";
            echo "<ul>";
            foreach ($errors as $error) {
                echo "<li>" . htmlspecialchars($error) . "</li>";
            }
            echo "</ul>";
            echo "</div>";
        }
    }

} catch (Exception $e) {
    echo "<div class='error'>";
    echo "<h2>❌ Error</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}

echo "</body></html>";
