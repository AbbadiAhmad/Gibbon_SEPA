<?php
/**
 * Standalone Database Setup Script
 * Creates the gibbonSEPAUpdateRequest table
 *
 * EDIT THE DATABASE CREDENTIALS BELOW, then run:
 * php create_table_standalone.php
 */

// ========== EDIT THESE ==========
$host = 'localhost';
$dbname = 'test_db';  // Your database name
$username = 'root';    // Your MySQL username
$password = '';        // Your MySQL password
// ================================

echo "\n";
echo "===========================================\n";
echo "  SEPA Update Request Table Creator\n";
echo "===========================================\n\n";

echo "Connecting to database: $dbname\n";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "✓ Connected successfully\n\n";

    echo "Creating table 'gibbonSEPAUpdateRequest'...\n";

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

    $pdo->exec($sql);

    echo "✓ Table created successfully!\n\n";

    // Verify
    $stmt = $pdo->query("SHOW TABLES LIKE 'gibbonSEPAUpdateRequest'");
    if ($stmt->rowCount() > 0) {
        echo "✓ Verification: Table exists\n\n";

        // Show columns
        echo "Table columns:\n";
        echo "--------------\n";
        $columns = $pdo->query("DESCRIBE gibbonSEPAUpdateRequest");
        while ($col = $columns->fetch(PDO::FETCH_ASSOC)) {
            echo sprintf("  %-30s %s\n", $col['Field'], $col['Type']);
        }

        echo "\n";
        echo "===========================================\n";
        echo "✓ SUCCESS! You can now use the feature.\n";
        echo "===========================================\n\n";

    } else {
        echo "✗ Table not found after creation\n";
    }

} catch (PDOException $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n\n";

    if (strpos($e->getMessage(), 'Access denied') !== false) {
        echo "Please check your database credentials at the top of this file.\n";
    } elseif (strpos($e->getMessage(), 'Unknown database') !== false) {
        echo "Database '$dbname' does not exist. Please create it first or check the name.\n";
    } elseif (strpos($e->getMessage(), 'already exists') !== false) {
        echo "The table already exists. This is normal if you've run this before.\n";
    }

    echo "\n";
}
