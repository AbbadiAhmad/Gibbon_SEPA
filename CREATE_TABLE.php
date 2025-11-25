<?php
/**
 * Quick Database Setup Script
 * Run this once to create the gibbonSEPAUpdateRequest table
 *
 * Usage: php CREATE_TABLE.php
 */

// Bootstrap Gibbon
$gibbon = true;
require_once __DIR__ . '/../../gibbon.php';

echo "Creating gibbonSEPAUpdateRequest table...\n\n";

$sql = "CREATE TABLE IF NOT EXISTS `gibbonSEPAUpdateRequest` (
    `gibbonSEPAUpdateRequestID` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `gibbonFamilyID` int(7) unsigned zerofill NOT NULL,
    `gibbonSEPAID` int(8) unsigned zerofill DEFAULT NULL COMMENT 'Link to existing SEPA record if updating',

    -- Old values (current data from gibbonSEPA, encrypted for audit trail)
    `old_payer` varchar(500) DEFAULT NULL COMMENT 'Encrypted old payer name',
    `old_IBAN` varchar(500) DEFAULT NULL COMMENT 'Encrypted old IBAN',
    `old_BIC` varchar(500) DEFAULT NULL COMMENT 'Encrypted old BIC',
    `old_SEPA_signedDate` date DEFAULT NULL,
    `old_note` text DEFAULT NULL,
    `old_customData` text DEFAULT NULL COMMENT 'JSON object of old custom field values',

    -- New values (requested changes, encrypted)
    `new_payer` varchar(500) NOT NULL COMMENT 'Encrypted new payer name',
    `new_IBAN` varchar(500) NOT NULL COMMENT 'Encrypted new IBAN',
    `new_BIC` varchar(500) DEFAULT NULL COMMENT 'Encrypted new BIC',
    `new_SEPA_signedDate` date DEFAULT NULL,
    `new_note` text DEFAULT NULL,
    `new_customData` text DEFAULT NULL COMMENT 'JSON object of new custom field values',

    -- Workflow tracking
    `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',

    -- Audit trail
    `gibbonPersonIDSubmitted` int(10) unsigned zerofill NOT NULL COMMENT 'Parent who submitted the request',
    `submittedDate` datetime NOT NULL,
    `submitter_archive` TEXT NULL COMMENT 'JSON with submitter IP, user agent, and metadata',
    `gibbonPersonIDApproved` int(10) unsigned zerofill DEFAULT NULL COMMENT 'Admin who approved/rejected',
    `approvedDate` datetime DEFAULT NULL,
    `approver_archive` TEXT NULL COMMENT 'JSON with approver IP, user agent, and metadata',
    `approvalNote` text DEFAULT NULL COMMENT 'Admin note for approval/rejection reason',

    -- Integrity verification
    `data_hash` VARCHAR(64) NULL COMMENT 'SHA-256 hash of critical fields for integrity verification',

    -- Metadata
    `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `timestampUpdated` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`gibbonSEPAUpdateRequestID`),
    KEY `gibbonFamilyID` (`gibbonFamilyID`),
    KEY `gibbonSEPAID` (`gibbonSEPAID`),
    KEY `status` (`status`),
    KEY `submittedDate` (`submittedDate`),
    KEY `data_hash` (`data_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

try {
    $result = $pdo->query($sql);

    echo "✓ SUCCESS! Table 'gibbonSEPAUpdateRequest' created successfully!\n\n";

    // Verify table exists
    $verify = $pdo->query("SHOW TABLES LIKE 'gibbonSEPAUpdateRequest'");
    if ($verify->rowCount() > 0) {
        echo "✓ Verified: Table exists in database\n\n";

        // Show table structure
        echo "Table structure:\n";
        echo "=================\n";
        $structure = $pdo->query("DESCRIBE gibbonSEPAUpdateRequest");
        while ($row = $structure->fetch(PDO::FETCH_ASSOC)) {
            echo sprintf("  %-30s %s\n", $row['Field'], $row['Type']);
        }
        echo "\n";
        echo "You can now use the SEPA update request feature!\n";
        echo "Navigate to: Sepa > Update SEPA Information (for parents)\n";
        echo "         or: Sepa > Approve SEPA Updates (for admins)\n";
    } else {
        echo "✗ Warning: Table creation reported success but table not found\n";
    }

} catch (Exception $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n";
    echo "\nIf the table already exists, this is normal.\n";
    echo "Try accessing the page again.\n";
}
