<?php

// This file describes the module, including database tables

// Basic variables
$name = 'Sepa';            // The name of the module as it appears to users. Needs to be unique to installation. Also the name of the folder that holds the unit.
$description = 'Manage SEPA information and transaction';            // Short text description
$entryURL = "sepa_family_view.php";   // The landing page for the unit, used in the main menu
$type = "Additional";  // Do not change.
$category = 'Other';            // The main menu area to place the module in
$version = '1.1.1';            // Version number
$author = 'Ahmad';            // Your name
$url = '';            // Your URL

// Module tables & gibbonSettings entries
// One array entry for every database table you need to create. Might be nice to preface the table name with the module name, to keep the db neat. 
// Also can be used to put data into gibbonSettings. Other sql can be run, but resulting data will not be cleaned up on uninstall.

$moduleTables[] = "
    CREATE TABLE `gibbonSEPA` (
        `gibbonSEPAID` INT(8) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
        `gibbonFamilyID` INT(7) UNSIGNED ZEROFILL NOT NULL,
        `payer` VARCHAR(255) NOT NULL  COMMENT 'The name of the account holder',
        `IBAN` VARCHAR(22) DEFAULT 'NULL',
        `BIC` VARCHAR(11) DEFAULT NULL,
        `SEPA_signedDate` date DEFAULT NULL,
        `note` TEXT DEFAULT NULL,
        `customData` text COMMENT 'JSON object of custom field values',
         PRIMARY KEY (`gibbonSEPAID`),
        KEY `gibbonFamilyID` (`gibbonFamilyID`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
     ";

$moduleTables[] = "
    CREATE TABLE `gibbonSEPACustomField` (
        `gibbonSEPACustomFieldID` int(4) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
        `title` varchar(50) NOT NULL,
        `active` enum('Y','N') NOT NULL DEFAULT 'Y',
        `description` varchar(255) NOT NULL,
        `type` enum('varchar','text','date','select','checkboxes','radio','yesno','number','file') NOT NULL,
        `options` text NOT NULL COMMENT 'Field length for varchar, rows for text, comma-separate list for select/checkbox.',
        `required` enum('N','Y') NOT NULL DEFAULT 'N',
        `heading` varchar(90) NOT NULL,
        `sequenceNumber` int(4) NOT NULL,
        PRIMARY KEY (`gibbonSEPACustomFieldID`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
    
    INSERT INTO gibbonSEPACustomField
        (`title`, `active`, `description`, `type`, `options`, `required`, `heading`, `sequenceNumber`)
    VALUES
        ('SEPA Archive ID', 'Y', 'SEPA ID in the paper form', 'varchar', '15', 'N', 'SEPA', 1);
        ('SEPA Owner Address', 'Y', 'Der Strasse des SEPA-Kontoinhabers', 'varchar', '100', 'N', 'SEPA', 2),
        ('SEPA Owner Zip/City/Country', 'Y', 'Der Postzal Stadt, Land des SEPA-Kontoinhabers', 'varchar', '150', 'N', 'SEPA', 3),
        ('SEPA Owner Phone', 'Y', 'Der Telefone des SEPA-Kontoinhabers', 'varchar', '15', 'N', 'SEPA', 4);
    ";

$moduleTables[] = "
CREATE TABLE `gibbonSEPAPaymentEntry` (
        `gibbonSEPAPaymentRecordID` int(10) unsigned NOT NULL AUTO_INCREMENT,
        `booking_date` DATE not NULL,
        `payer` varchar(100) NOT NULL,
        `IBAN` varchar(34) NULL,
        `transaction_reference` varchar(255) NULL,
        `transaction_message` varchar(255) NULL,
        `amount` decimal(10,2) not NULL,
        `note` text NULL,
        `payment_method` VARCHAR(50) NULL,
        `academicYear` INT UNSIGNED DEFAULT NULL,
        `gibbonSEPAID` INT UNSIGNED DEFAULT NULL COMMENT 'Link the payment to the SEPA record if one SEPA is matched',
        `gibbonUser` varchar(255) not NULL,
        `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`gibbonSEPAPaymentRecordID`),
        UNIQUE KEY `unique_booking_sepa_owner_transaction_message` (`booking_date`, `payer`, `transaction_message`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
    ";

$moduleTables[] = "
    CREATE TABLE `gibbonSepaCoursesFees` (
    `gibbonSepaCoursesCostID` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `gibbonCourseID` int(8) unsigned NOT NULL,
    `fees` decimal(12,2) NOT NULL DEFAULT '0.00',
    `gibbonPersonIDCreator` int(10) unsigned NOT NULL,
    `gibbonPersonIDUpdate` int(10) unsigned DEFAULT NULL,
    `timestampCreated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `timestampUpdate` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`gibbonSepaCoursesCostID`),
    KEY `gibbonCourseID` (`gibbonCourseID`),
    KEY `gibbonPersonIDCreator` (`gibbonPersonIDCreator`),
    KEY `gibbonPersonIDUpdate` (`gibbonPersonIDUpdate`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
 ";

// Add gibbonSettings entries
//$gibbonSetting[] = "";


// Action rows 
// One array per action

$actionRows[] = [
    'name' => 'View family Payment', // The name of the action (appears to user in the right hand side module menu)
    'precedence' => '1',// If it is a grouped action, the precedence controls which is highest action in group
    'category' => 'Payments', // Optional: subgroups for the right hand side module menu
    'description' => 'Show family Payment records', // Text description
    'URLList' => 'sepa_payment_view.php, sepa_unlinked_payment_view.php', // List of pages included in this action
    'entryURL' => 'sepa_payment_view.php', // The landing action for the page.
    'entrySidebar' => 'Y', // Whether or not there's a sidebar on entry to the action
    'menuShow' => 'Y', // Whether or not this action shows up in menus or if it's hidden
    'defaultPermissionAdmin' => 'Y', // Default permission for built in role Admin
    'defaultPermissionTeacher' => 'N', // Default permission for built in role Teacher
    'defaultPermissionStudent' => 'N', // Default permission for built in role Student
    'defaultPermissionParent' => 'N', // Default permission for built in role Parent
    'defaultPermissionSupport' => 'N', // Default permission for built in role Support
    'categoryPermissionStaff' => 'Y', // Should this action be available to user roles in the Staff category?
    'categoryPermissionStudent' => 'N', // Should this action be available to user roles in the Student category?
    'categoryPermissionParent' => 'N', // Should this action be available to user roles in the Parent category?
    'categoryPermissionOther' => 'N', // Should this action be available to user roles in the Other category?
];

$actionRows[] = [
    'name' => 'View Financial Summary', // The name of the action (appears to user in the right hand side module menu)
    'precedence' => '2',// If it is a grouped action, the precedence controls which is highest action in group
    'category' => 'Payments', // Optional: subgroups for the right hand side module menu
    'description' => 'Show Family Payments and dept', // Text description
    'URLList' => 'sepa_payment_summary.php', // List of pages included in this action
    'entryURL' => 'sepa_payment_summary.php', // The landing action for the page.
    'entrySidebar' => 'Y', // Whether or not there's a sidebar on entry to the action
    'menuShow' => 'Y', // Whether or not this action shows up in menus or if it's hidden
    'defaultPermissionAdmin' => 'Y', // Default permission for built in role Admin
    'defaultPermissionTeacher' => 'N', // Default permission for built in role Teacher
    'defaultPermissionStudent' => 'N', // Default permission for built in role Student
    'defaultPermissionParent' => 'N', // Default permission for built in role Parent
    'defaultPermissionSupport' => 'N', // Default permission for built in role Support
    'categoryPermissionStaff' => 'Y', // Should this action be available to user roles in the Staff category?
    'categoryPermissionStudent' => 'N', // Should this action be available to user roles in the Student category?
    'categoryPermissionParent' => 'N', // Should this action be available to user roles in the Parent category?
    'categoryPermissionOther' => 'N', // Should this action be available to user roles in the Other category?
];


$actionRows[] = [
    'name' => 'View Unlinked Payment', // The name of the action (appears to user in the right hand side module menu)
    'precedence' => '3',// If it is a grouped action, the precedence controls which is highest action in group
    'category' => 'Payments', // Optional: subgroups for the right hand side module menu
    'description' => 'Show SEPA Payment records', // Text description
    'URLList' => 'sepa_unlinked_payment_view.php', // List of pages included in this action
    'entryURL' => 'sepa_unlinked_payment_view.php', // The landing action for the page.
    'entrySidebar' => 'Y', // Whether or not there's a sidebar on entry to the action
    'menuShow' => 'Y', // Whether or not this action shows up in menus or if it's hidden
    'defaultPermissionAdmin' => 'Y', // Default permission for built in role Admin
    'defaultPermissionTeacher' => 'N', // Default permission for built in role Teacher
    'defaultPermissionStudent' => 'N', // Default permission for built in role Student
    'defaultPermissionParent' => 'N', // Default permission for built in role Parent
    'defaultPermissionSupport' => 'N', // Default permission for built in role Support
    'categoryPermissionStaff' => 'Y', // Should this action be available to user roles in the Staff category?
    'categoryPermissionStudent' => 'N', // Should this action be available to user roles in the Student category?
    'categoryPermissionParent' => 'N', // Should this action be available to user roles in the Parent category?
    'categoryPermissionOther' => 'N', // Should this action be available to user roles in the Other category?
];

$actionRows[] = [
    'name' => 'View Payments - my family', // The name of the action (appears to user in the right hand side module menu)
    'precedence' => '4',// If it is a grouped action, the precedence controls which is highest action in group
    'category' => 'Payments', // Optional: subgroups for the right hand side module menu
    'description' => 'Show SEPA Payment records', // Text description
    'URLList' => 'sepa_payment_view_per_family.php', // List of pages included in this action
    'entryURL' => 'sepa_payment_view_per_family.php', // The landing action for the page.
    'entrySidebar' => 'Y', // Whether or not there's a sidebar on entry to the action
    'menuShow' => 'Y', // Whether or not this action shows up in menus or if it's hidden
    'defaultPermissionAdmin' => 'Y', // Default permission for built in role Admin
    'defaultPermissionTeacher' => 'Y', // Default permission for built in role Teacher
    'defaultPermissionStudent' => 'Y', // Default permission for built in role Student
    'defaultPermissionParent' => 'Y', // Default permission for built in role Parent
    'defaultPermissionSupport' => 'Y', // Default permission for built in role Support
    'categoryPermissionStaff' => 'Y', // Should this action be available to user roles in the Staff category?
    'categoryPermissionStudent' => 'Y', // Should this action be available to user roles in the Student category?
    'categoryPermissionParent' => 'Y', // Should this action be available to user roles in the Parent category?
    'categoryPermissionOther' => 'Y', // Should this action be available to user roles in the Other category?
];

$actionRows[] = [
    'name' => 'Import SEPA Payment',
    'precedence' => '1',
    'category' => 'Entry',
    'description' => 'Import SEPA data from Excel files',
    'URLList' => 'import_sepa_payment.php',
    'entryURL' => 'import_sepa_payment.php',
    'entrySidebar' => 'Y', // Whether or not there's a sidebar on entry to the action
    'menuShow' => 'Y', // Whether or not this action shows up in menus or if it's hidden
    'defaultPermissionAdmin' => 'Y', // Default permission for built in role Admin
    'defaultPermissionTeacher' => 'N', // Default permission for built in role Teacher
    'defaultPermissionStudent' => 'N', // Default permission for built in role Student
    'defaultPermissionParent' => 'N', // Default permission for built in role Parent
    'defaultPermissionSupport' => 'N', // Default permission for built in role Support
    'categoryPermissionStaff' => 'Y', // Should this action be available to user roles in the Staff category?
    'categoryPermissionStudent' => 'N', // Should this action be available to user roles in the Student category?
    'categoryPermissionParent' => 'N', // Should this action be available to user roles in the Parent category?
    'categoryPermissionOther' => 'N', // Should this action be available to user roles in the Other category?
];

$actionRows[] = [
    'name' => 'Manage SEPA Payment Entries',
    'precedence' => '6',
    'category' => 'Payments',
    'description' => 'Manage SEPA Payment Entries (Add, Edit, Delete)',
    'URLList' => 'sepa_payment_manage.php, sepa_payment_add.php, sepa_payment_edit.php, sepa_payment_delete.php',
    'entryURL' => 'sepa_payment_manage.php',
    'entrySidebar' => 'Y',
    'menuShow' => 'Y',
    'defaultPermissionAdmin' => 'Y',
    'defaultPermissionTeacher' => 'N',
    'defaultPermissionStudent' => 'N',
    'defaultPermissionParent' => 'N',
    'defaultPermissionSupport' => 'N',
    'categoryPermissionStaff' => 'Y',
    'categoryPermissionStudent' => 'N',
    'categoryPermissionParent' => 'N',
    'categoryPermissionOther' => 'N',
];

$actionRows[] = [
    'name' => 'View Family SEPA', // The name of the action (appears to user in the right hand side module menu)
    'precedence' => '7',// If it is a grouped action, the precedence controls which is highest action in group
    'category' => 'SEPA', // Optional: subgroups for the right hand side module menu
    'description' => 'Show SEPA of each family', // Text description
    'URLList' => 'sepa_family_view.php', // List of pages included in this action
    'entryURL' => 'sepa_family_view.php', // The landing action for the page.
    'entrySidebar' => 'Y', // Whether or not there's a sidebar on entry to the action
    'menuShow' => 'Y', // Whether or not this action shows up in menus or if it's hidden
    'defaultPermissionAdmin' => 'Y', // Default permission for built in role Admin
    'defaultPermissionTeacher' => 'N', // Default permission for built in role Teacher
    'defaultPermissionStudent' => 'N', // Default permission for built in role Student
    'defaultPermissionParent' => 'N', // Default permission for built in role Parent
    'defaultPermissionSupport' => 'N', // Default permission for built in role Support
    'categoryPermissionStaff' => 'Y', // Should this action be available to user roles in the Staff category?
    'categoryPermissionStudent' => 'N', // Should this action be available to user roles in the Student category?
    'categoryPermissionParent' => 'N', // Should this action be available to user roles in the Parent category?
    'categoryPermissionOther' => 'N', // Should this action be available to user roles in the Other category?
];

$actionRows[] = [
    'name' => 'Add Family SEPA', // The name of the action (appears to user in the right hand side module menu)
    'precedence' => '8',// If it is a grouped action, the precedence controls which is highest action in group
    'category' => 'Entry', // Optional: subgroups for the right hand side module menu
    'description' => 'Add SEPA to a family', // Text description
    'URLList' => 'sepa_family_add.php,sepa_family_edit.php,sepa_family_delete.php', // List of pages included in this action
    'entryURL' => 'sepa_family_add.php', // The landing action for the page.
    'entrySidebar' => 'Y', // Whether or not there's a sidebar on entry to the action
    'menuShow' => 'Y', // Whether or not this action shows up in menus or if it's hidden
    'defaultPermissionAdmin' => 'Y', // Default permission for built in role Admin
    'defaultPermissionTeacher' => 'N', // Default permission for built in role Teacher
    'defaultPermissionStudent' => 'N', // Default permission for built in role Student
    'defaultPermissionParent' => 'N', // Default permission for built in role Parent
    'defaultPermissionSupport' => 'N', // Default permission for built in role Support
    'categoryPermissionStaff' => 'Y', // Should this action be available to user roles in the Staff category?
    'categoryPermissionStudent' => 'N', // Should this action be available to user roles in the Student category?
    'categoryPermissionParent' => 'N', // Should this action be available to user roles in the Parent category?
    'categoryPermissionOther' => 'N', // Should this action be available to user roles in the Other category?
];


$actionRows[] = [
    'name' => 'Edit/delete SEPA', // The name of the action (appears to user in the right hand side module menu)
    'precedence' => '9',// If it is a grouped action, the precedence controls which is highest action in group
    'category' => 'hidden', // Optional: subgroups for the right hand side module menu
    'description' => 'Add SEPA to a family', // Text description
    'URLList' => 'sepa_family_edit.php,sepa_family_delete.php', // List of pages included in this action
    'entryURL' => 'sepa_family_edit.php', // The landing action for the page.
    'entrySidebar' => 'N', // Whether or not there's a sidebar on entry to the action
    'menuShow' => 'N', // Whether or not this action shows up in menus or if it's hidden
    'defaultPermissionAdmin' => 'Y', // Default permission for built in role Admin
    'defaultPermissionTeacher' => 'N', // Default permission for built in role Teacher
    'defaultPermissionStudent' => 'N', // Default permission for built in role Student
    'defaultPermissionParent' => 'N', // Default permission for built in role Parent
    'defaultPermissionSupport' => 'N', // Default permission for built in role Support
    'categoryPermissionStaff' => 'Y', // Should this action be available to user roles in the Staff category?
    'categoryPermissionStudent' => 'N', // Should this action be available to user roles in the Student category?
    'categoryPermissionParent' => 'N', // Should this action be available to user roles in the Parent category?
    'categoryPermissionOther' => 'N', // Should this action be available to user roles in the Other category?
];

// Action rows
$actionRows[] = [
    'name' => 'Import Family SEPA',
    'precedence' => '10',
    'category' => 'Entry',
    'description' => 'Import SEPA data from Excel files',
    'URLList' => 'import_sepa_data.php',
    'entryURL' => 'import_sepa_data.php',
    'entrySidebar' => 'Y', // Whether or not there's a sidebar on entry to the action
    'menuShow' => 'Y', // Whether or not this action shows up in menus or if it's hidden
    'defaultPermissionAdmin' => 'Y', // Default permission for built in role Admin
    'defaultPermissionTeacher' => 'N', // Default permission for built in role Teacher
    'defaultPermissionStudent' => 'N', // Default permission for built in role Student
    'defaultPermissionParent' => 'N', // Default permission for built in role Parent
    'defaultPermissionSupport' => 'N', // Default permission for built in role Support
    'categoryPermissionStaff' => 'Y', // Should this action be available to user roles in the Staff category?
    'categoryPermissionStudent' => 'N', // Should this action be available to user roles in the Student category?
    'categoryPermissionParent' => 'N', // Should this action be available to user roles in the Parent category?
    'categoryPermissionOther' => 'N', // Should this action be available to user roles in the Other category?
];


// Hooks
//$hooks[] = ''; // Serialised array to create hook and set options. See Hooks documentation online.
