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


// v2.3.0 - IBAN encryption, withdrawal cadence / day, import from registration form, family due report
$count++;
$sql[$count][0] = "2.3.0";
$sql[$count][1] = "ALTER TABLE gibbonSEPA MODIFY COLUMN `IBAN` VARCHAR(255) DEFAULT NULL COMMENT 'AES-256-GCM encrypted, see IbanCipher';end
ALTER TABLE gibbonSEPA ADD COLUMN `withdrawCadence` ENUM('Monthly','HalfYearly','Yearly') NOT NULL DEFAULT 'Monthly' COMMENT 'How often the money is withdrawn' AFTER `BIC`;end
ALTER TABLE gibbonSEPA ADD COLUMN `withdrawDay` TINYINT UNSIGNED DEFAULT 5 COMMENT 'Preferred day of the month for the withdrawal (1-31)' AFTER `withdrawCadence`;end
UPDATE gibbonSEPA SET withdrawDay=5 WHERE withdrawDay IS NULL;end
ALTER TABLE gibbonSEPAPaymentEntry MODIFY COLUMN `IBAN` VARCHAR(255) NULL COMMENT 'AES-256-GCM encrypted, see IbanCipher';end
UPDATE gibbonAction SET URLList='sepa_family_totals.php, sepa_family_details.php, sepa_family_due.php, sepa_family_balance_export.php' WHERE name='Family Balance' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Sepa');end
UPDATE gibbonAction SET URLList='import_sepa_data.php, import_sepa_from_form.php' WHERE name='Import Family SEPA' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Sepa');";

// v2.3.1 - the registration form import is a separate action in the Entry menu
$count++;
$sql[$count][0] = "2.3.1";
$sql[$count][1] = "UPDATE gibbonAction SET URLList='import_sepa_data.php' WHERE name='Import Family SEPA' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Sepa');end
INSERT INTO gibbonAction (gibbonModuleID, name, precedence, category, description, URLList, entryURL, entrySidebar, menuShow, defaultPermissionAdmin, defaultPermissionTeacher, defaultPermissionStudent, defaultPermissionParent, defaultPermissionSupport, categoryPermissionStaff, categoryPermissionStudent, categoryPermissionParent, categoryPermissionOther) SELECT gibbonModuleID, 'Import SEPA from Registration Form', 10, 'Entry', 'Import SEPA data from the accepted registration forms', 'import_sepa_from_form.php', 'import_sepa_from_form.php', 'Y', 'Y', 'Y', 'N', 'N', 'N', 'N', 'Y', 'N', 'N', 'N' FROM gibbonModule WHERE name='Sepa';end
INSERT INTO gibbonPermission (gibbonRoleID, gibbonActionID) SELECT '001', gibbonActionID FROM gibbonAction WHERE name='Import SEPA from Registration Form' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Sepa');";

// v2.3.2 - SEPA data check page
$count++;
$sql[$count][0] = "2.3.2";
$sql[$count][1] = "INSERT INTO gibbonAction (gibbonModuleID, name, precedence, category, description, URLList, entryURL, entrySidebar, menuShow, defaultPermissionAdmin, defaultPermissionTeacher, defaultPermissionStudent, defaultPermissionParent, defaultPermissionSupport, categoryPermissionStaff, categoryPermissionStudent, categoryPermissionParent, categoryPermissionOther) SELECT gibbonModuleID, 'Check SEPA Data', 17, 'Reports', 'Find missing or wrong SEPA data', 'sepa_check.php', 'sepa_check.php', 'Y', 'Y', 'Y', 'N', 'N', 'N', 'N', 'Y', 'N', 'N', 'N' FROM gibbonModule WHERE name='Sepa';end
INSERT INTO gibbonPermission (gibbonRoleID, gibbonActionID) SELECT '001', gibbonActionID FROM gibbonAction WHERE name='Check SEPA Data' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Sepa');";

// v2.3.3 - SEPA data check page moves to the SEPA menu group
$count++;
$sql[$count][0] = "2.3.3";
$sql[$count][1] = "UPDATE gibbonAction SET category='SEPA' WHERE name='Check SEPA Data' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Sepa');";

// v2.3.4 - SEPA check export
$count++;
$sql[$count][0] = "2.3.4";
$sql[$count][1] = "UPDATE gibbonAction SET URLList='sepa_check.php, sepa_check_export.php' WHERE name='Check SEPA Data' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Sepa');";

// v2.4.0 - families can see their own SEPA data (same permissions as "View Payments - my family")
$count++;
$sql[$count][0] = "2.4.0";
$sql[$count][1] = "INSERT INTO gibbonAction (gibbonModuleID, name, precedence, category, description, URLList, entryURL, entrySidebar, menuShow, defaultPermissionAdmin, defaultPermissionTeacher, defaultPermissionStudent, defaultPermissionParent, defaultPermissionSupport, categoryPermissionStaff, categoryPermissionStudent, categoryPermissionParent, categoryPermissionOther) SELECT gibbonModuleID, 'View SEPA - my family', 5, 'SEPA', 'Show the SEPA data of the own family (read only)', 'sepa_family_my_sepa.php', 'sepa_family_my_sepa.php', entrySidebar, menuShow, defaultPermissionAdmin, defaultPermissionTeacher, defaultPermissionStudent, defaultPermissionParent, defaultPermissionSupport, categoryPermissionStaff, categoryPermissionStudent, categoryPermissionParent, categoryPermissionOther FROM gibbonAction WHERE name='View Payments - my family' AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Sepa');end
INSERT INTO gibbonPermission (gibbonRoleID, gibbonActionID) SELECT p.gibbonRoleID, n.gibbonActionID FROM gibbonPermission AS p JOIN gibbonAction AS o ON o.gibbonActionID=p.gibbonActionID JOIN gibbonAction AS n ON n.name='View SEPA - my family' AND n.gibbonModuleID=o.gibbonModuleID WHERE o.name='View Payments - my family' AND o.gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Sepa');";
