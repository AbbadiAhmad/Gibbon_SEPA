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
    `totalFees` decimal(10,2) NOT NULL COMMENT 'Total fees at time of snapshot',
    `totalPayments` decimal(10,2) NOT NULL COMMENT 'Total payments at time of snapshot',
    `totalAdjustments` decimal(10,2) NOT NULL COMMENT 'Total adjustments at time of snapshot',
    `snapshotData` LONGTEXT NOT NULL COMMENT 'JSON object containing detailed snapshot data',
    `gibbonPersonID` varchar(255) NOT NULL COMMENT 'User who created the snapshot',
    `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`gibbonSEPABalanceSnapshotID`),
    KEY `gibbonFamilyID` (`gibbonFamilyID`),
    KEY `gibbonSEPAID` (`gibbonSEPAID`),
    KEY `academicYear` (`academicYear`),
    KEY `snapshotDate` (`snapshotDate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
