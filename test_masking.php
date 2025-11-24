<?php
/*
Test script for IBAN/BIC masking functionality
Run this to verify masking works correctly before migration
*/

use Gibbon\Module\Sepa\Domain\SepaGateway;

require_once __DIR__ . '/../../gibbon.php';

// Check if user is logged in
if (!$gibbon->session->exists('username')) {
    die("Error: You must be logged in to run this test.");
}

$sepaGateway = $container->get(SepaGateway::class);

echo "<!DOCTYPE html><html><head><title>SEPA Masking Test</title>";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    .success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 10px; margin: 10px 0; }
    .error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 10px; margin: 10px 0; }
    table { border-collapse: collapse; width: 100%; margin: 20px 0; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background-color: #f2f2f2; }
</style></head><body>";

echo "<h1>SEPA IBAN/BIC Masking Test</h1>";

// Test cases
$testCases = [
    // Valid IBANs from different countries
    ['input' => 'DE89370400440532013000', 'expected' => 'DE****000', 'description' => 'German IBAN (22 chars)'],
    ['input' => 'GB82WEST12345698765432', 'expected' => 'GB****432', 'description' => 'UK IBAN (22 chars)'],
    ['input' => 'FR1420041010050500013M02606', 'expected' => 'FR****606', 'description' => 'French IBAN (27 chars)'],
    ['input' => 'IT60X0542811101000000123456', 'expected' => 'IT****456', 'description' => 'Italian IBAN (27 chars)'],
    ['input' => 'ES9121000418450200051332', 'expected' => 'ES****332', 'description' => 'Spanish IBAN (24 chars)'],

    // IBANs with spaces (should be removed)
    ['input' => 'DE89 3704 0044 0532 0130 00', 'expected' => 'DE****000', 'description' => 'IBAN with spaces'],
    ['input' => 'GB82 WEST 1234 5698 7654 32', 'expected' => 'GB****432', 'description' => 'IBAN with spaces (UK format)'],

    // Lowercase (should convert to uppercase)
    ['input' => 'de89370400440532013000', 'expected' => 'DE****000', 'description' => 'Lowercase IBAN'],

    // Edge cases
    ['input' => 'AB123', 'expected' => 'AB****123', 'description' => 'Minimum valid length (5 chars)'],
    ['input' => 'ABCD', 'expected' => null, 'description' => 'Too short (4 chars) - should return NULL'],
    ['input' => '', 'expected' => null, 'description' => 'Empty string - should return NULL'],
    ['input' => null, 'expected' => null, 'description' => 'NULL input - should return NULL'],
    ['input' => '   ', 'expected' => null, 'description' => 'Whitespace only - should return NULL'],
];

echo "<h2>IBAN Masking Tests</h2>";
echo "<table>";
echo "<thead><tr>";
echo "<th>Test Case</th>";
echo "<th>Input</th>";
echo "<th>Expected</th>";
echo "<th>Actual</th>";
echo "<th>Result</th>";
echo "</tr></thead>";
echo "<tbody>";

$passedTests = 0;
$failedTests = 0;

foreach ($testCases as $test) {
    $actual = $sepaGateway->maskIBAN($test['input']);
    $passed = ($actual === $test['expected']);

    if ($passed) {
        $passedTests++;
        $resultClass = 'success';
        $resultText = '✅ PASS';
    } else {
        $failedTests++;
        $resultClass = 'error';
        $resultText = '❌ FAIL';
    }

    echo "<tr>";
    echo "<td>" . htmlspecialchars($test['description']) . "</td>";
    echo "<td>" . htmlspecialchars($test['input'] ?? 'NULL') . "</td>";
    echo "<td>" . htmlspecialchars($test['expected'] ?? 'NULL') . "</td>";
    echo "<td>" . htmlspecialchars($actual ?? 'NULL') . "</td>";
    echo "<td class='{$resultClass}'>{$resultText}</td>";
    echo "</tr>";
}

echo "</tbody></table>";

// BIC tests
echo "<h2>BIC Masking Tests</h2>";
echo "<p>BIC should always return NULL (not stored per security requirements)</p>";

$bicTests = [
    'DEUTDEFF500',
    'COBADEFF',
    'BNPAFRPP',
    '',
    null,
];

echo "<table>";
echo "<thead><tr>";
echo "<th>Input BIC</th>";
echo "<th>Expected</th>";
echo "<th>Actual</th>";
echo "<th>Result</th>";
echo "</tr></thead>";
echo "<tbody>";

foreach ($bicTests as $bic) {
    $actual = $sepaGateway->maskBIC($bic);
    $passed = ($actual === null);

    if ($passed) {
        $passedTests++;
        $resultClass = 'success';
        $resultText = '✅ PASS';
    } else {
        $failedTests++;
        $resultClass = 'error';
        $resultText = '❌ FAIL';
    }

    echo "<tr>";
    echo "<td>" . htmlspecialchars($bic ?? 'NULL') . "</td>";
    echo "<td>NULL</td>";
    echo "<td>" . htmlspecialchars($actual ?? 'NULL') . "</td>";
    echo "<td class='{$resultClass}'>{$resultText}</td>";
    echo "</tr>";
}

echo "</tbody></table>";

// Summary
echo "<div class='" . ($failedTests === 0 ? 'success' : 'error') . "'>";
echo "<h2>Test Summary</h2>";
echo "<p><strong>Total Tests:</strong> " . ($passedTests + $failedTests) . "</p>";
echo "<p><strong>Passed:</strong> {$passedTests}</p>";
echo "<p><strong>Failed:</strong> {$failedTests}</p>";

if ($failedTests === 0) {
    echo "<p>✅ All tests passed! Masking functionality is working correctly.</p>";
    echo "<p>You can proceed with the migration.</p>";
} else {
    echo "<p>❌ Some tests failed. Please review the masking implementation before proceeding.</p>";
}
echo "</div>";

echo "</body></html>";
