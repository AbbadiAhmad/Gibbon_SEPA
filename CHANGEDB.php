<?php
// USE ;end TO SEPARATE SQL STATEMENTS. DON'T USE ;end IN ANY OTHER PLACES!

$sql = [];
$count = 0;

// v0.0.00
$sql[$count][0] = "0.0.00";
$sql[$count][1] = "-- First version, nothing to update";


// v0.0.01
$count++;
$sql[$count][0] = "0.0.01";
$sql[$count][1] = "CREATE TABLE IF NOT EXISTS `gibbonSEPAPaymentEntry` (
  `gibbonSEPAPaymentRecordID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `booking_date` DATE not NULL,
  `SEPA_ownerName` varchar(100) NOT NULL,
  `SEPA_IBAN` varchar(34) NULL,
  `SEPA_transaction` varchar(255) NULL,
  `payment_message` varchar(255) NULL,
  `amount` decimal(10,2) not NULL,
  `note` text NULL,
  `academicYear` INT UNSIGNED DEFAULT NULL,
  `gibbonSEPAID` INT UNSIGNED DEFAULT NULL COMMENT 'Link the payment to the SEPA record if one SEPA is matched',
  `gibbonUser` varchar(255) not NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`gibbonSEPAPaymentRecordID`),
  UNIQUE KEY `unique_booking_sepa_owner_payment_message` (`booking_date`, `SEPA_ownerName`, `payment_message`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;";

// v0.0.02
$count++;
$sql[$count][0] = "0.0.02";
$sql[$count][1] = "ALTER TABLE `gibbonSEPAPaymentEntry` RENAME COLUMN `SEPA_ownerName` TO `payer`;end
ALTER TABLE `gibbonSEPAPaymentEntry` RENAME COLUMN `SEPA_IBAN` TO `IBAN`;end
ALTER TABLE `gibbonSEPAPaymentEntry` RENAME COLUMN `SEPA_transaction` TO `transaction_reference`;end
ALTER TABLE `gibbonSEPAPaymentEntry` RENAME COLUMN `payment_message` TO `transaction_message`;end
ALTER TABLE `gibbonSEPAPaymentEntry` ADD COLUMN `payment_method` VARCHAR(50) NULL;end
ALTER TABLE `gibbonSEPAPaymentEntry` DROP INDEX `unique_booking_sepa_owner_payment_message`;end
ALTER TABLE `gibbonSEPAPaymentEntry` ADD UNIQUE KEY `unique_booking_payee_transaction_message` (`booking_date`, `payer`, `transaction_message`);";

// v2.0.0 - Add Balance Snapshot Feature
$count++;
$sql[$count][0] = "2.0.0";
$sql[$count][1] = "CREATE TABLE IF NOT EXISTS `gibbonSEPABalanceSnapshot` (
    `gibbonSEPABalanceSnapshotID` int(12) unsigned NOT NULL AUTO_INCREMENT,
    `gibbonFamilyID` int(7) unsigned zerofill NOT NULL,
    `gibbonSEPAID` int(8) unsigned zerofill DEFAULT NULL,
    `academicYear` INT UNSIGNED NOT NULL,
    `snapshotDate` datetime NOT NULL,
    `balance` decimal(10,2) NOT NULL COMMENT 'Total balance at time of snapshot',
    `snapshotData` LONGTEXT NOT NULL COMMENT 'JSON object containing detailed snapshot data',
    `gibbonPersonID` varchar(255) NOT NULL COMMENT 'User who created the snapshot',
    `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`gibbonSEPABalanceSnapshotID`),
    UNIQUE KEY `unique_family_snapshot_date` (`gibbonFamilyID`, `snapshotDate`, `academicYear`),
    KEY `gibbonFamilyID` (`gibbonFamilyID`),
    KEY `gibbonSEPAID` (`gibbonSEPAID`),
    KEY `academicYear` (`academicYear`),
    KEY `snapshotDate` (`snapshotDate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";

// v2.0.1 - Add unique constraint to existing snapshot tables
$count++;
$sql[$count][0] = "2.0.1";
$sql[$count][1] = "
-- Remove duplicate snapshots, keeping only the most recent one per family/date/year
DELETE t1 FROM gibbonSEPABalanceSnapshot t1
INNER JOIN gibbonSEPABalanceSnapshot t2
WHERE t1.gibbonSEPABalanceSnapshotID < t2.gibbonSEPABalanceSnapshotID
  AND t1.gibbonFamilyID = t2.gibbonFamilyID
  AND t1.snapshotDate = t2.snapshotDate
  AND t1.academicYear = t2.academicYear;end
-- Add unique constraint to prevent future duplicates
ALTER TABLE gibbonSEPABalanceSnapshot
ADD UNIQUE KEY unique_family_snapshot_date (gibbonFamilyID, snapshotDate, academicYear);";

// v2.0.2 - Add totalFees and totalAdjustments columns for efficient snapshot comparison
$count++;
$sql[$count][0] = "2.0.2";
$sql[$count][1] = "
-- Add totalFees and totalAdjustments columns to store snapshot comparison values directly
ALTER TABLE gibbonSEPABalanceSnapshot
ADD COLUMN totalFees DECIMAL(12,2) DEFAULT 0.00 COMMENT 'Total owed fees at time of snapshot' AFTER balance;end
ALTER TABLE gibbonSEPABalanceSnapshot
ADD COLUMN totalAdjustments DECIMAL(12,2) DEFAULT 0.00 COMMENT 'Total adjustments at time of snapshot' AFTER totalFees;";

// v2.1.0 - Add SEPA Update Request table with encryption for parent self-service updates
$count++;
$sql[$count][0] = "2.1.0";
$sql[$count][1] = "CREATE TABLE IF NOT EXISTS `gibbonSEPAUpdateRequest` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
