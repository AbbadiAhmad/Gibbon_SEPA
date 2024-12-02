<?php
/*
Gibbon, Flexible & Open School System
Copyright (C) 2010, Ross Parker

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http:// www.gnu.org/licenses/>.
*/

// This file describes the module, including database tables

// Basic variables
$name        = 'Sepa';            // The name of the module as it appears to users. Needs to be unique to installation. Also the name of the folder that holds the unit.
$description = 'Manage SEPA information and transaction';            // Short text description
$entryURL    = "sepa_family_view.php";   // The landing page for the unit, used in the main menu
$type        = "Additional";  // Do not change.
$category    = 'Other';            // The main menu area to place the module in
$version     = '0.0.0';            // Version number
$author      = 'Ahmad';            // Your name
$url         = '';            // Your URL

// Module tables & gibbonSettings entries
// One array entry for every database table you need to create. Might be nice to preface the table name with the module name, to keep the db neat. 
// Also can be used to put data into gibbonSettings. Other sql can be run, but resulting data will not be cleaned up on uninstall.

$moduleTables[] = "
    CREATE TABLE `gibbonSEPA` (
        `gibbonSEPAID` INT(8) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
        `gibbonFamilyID` INT(7) UNSIGNED ZEROFILL NOT NULL,
        `SEPA_holderName` VARCHAR(255) NOT NULL  COMMENT 'The name of the account holder',
        `SEPA_IBAN` VARCHAR(22) DEFAULT 'NULL',
        `SEPA_BIC` VARCHAR(11) DEFAULT NULL,
        `SEPA_signedDate` date DEFAULT NULL,
        `comment` TEXT DEFAULT NULL,
        `fields` text DEFAULT '{}' COMMENT 'JSON object of custom field values',
         PRIMARY KEY (`gibbonSEPAID`),
        KEY `gibbonFamilyID` (`gibbonFamilyID`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
     "; 

$moduleTables[] = "
    CREATE TABLE `gibbonSEPACustomField` (
        `gibbonSEPACustomFieldID` int(4) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
        `name` varchar(50) NOT NULL,
        `active` enum('Y','N') NOT NULL DEFAULT 'Y',
        `description` varchar(255) NOT NULL,
        `type` enum('varchar','text','date','select','checkboxes','radio','yesno','editor','number','image','file') NOT NULL,
        `options` text NOT NULL COMMENT 'Field length for varchar, rows for text, comma-separate list for select/checkbox.',
        `required` enum('N','Y') NOT NULL DEFAULT 'N',
        `heading` varchar(90) NOT NULL,
        `sequenceNumber` int(4) NOT NULL,
        PRIMARY KEY (`gibbonSEPACustomFieldID`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
    "; 

$moduleTables[] = "
    INSERT INTO gibbonSEPACustomField
        (`name`, `active`, `description`, `type`, `options`, `required`, `heading`, `sequenceNumber`)
    VALUES
        ('SEPA_holderAddressStreet', 'Y', 'Der Strasse des SEPA-Kontoinhabers', 'varchar', '100', 'N', 'SEPA', 1),
        ('SEPA_holderAddressCityCountry', 'Y', 'Der Postzal Stadt, Land des SEPA-Kontoinhabers', 'varchar', '150', 'N', 'SEPA', 2),
        ('SEPA_holderPhone', 'Y', 'Der Telefone des SEPA-Kontoinhabers', 'varchar', '15', 'N', 'SEPA', 3)
    ;";


// Add gibbonSettings entries
//$gibbonSetting[] = "";

// Action rows 
// One array per action
$actionRows[0] = [
    'name'                      => 'Family SEPA', // The name of the action (appears to user in the right hand side module menu)
    'precedence'                => '0',// If it is a grouped action, the precedence controls which is highest action in group
    'category'                  => 'SEPA info', // Optional: subgroups for the right hand side module menu
    'description'               => 'Show SEPA of each family', // Text description
    'URLList'                   => 'sepa_family_view.php', // List of pages included in this action
    'entryURL'                  => 'sepa_family_view.php', // The landing action for the page.
    'entrySidebar'              => 'Y', // Whether or not there's a sidebar on entry to the action
    'menuShow'                  => 'Y', // Whether or not this action shows up in menus or if it's hidden
    'defaultPermissionAdmin'    => 'Y', // Default permission for built in role Admin
    'defaultPermissionTeacher'  => 'N', // Default permission for built in role Teacher
    'defaultPermissionStudent'  => 'N', // Default permission for built in role Student
    'defaultPermissionParent'   => 'N', // Default permission for built in role Parent
    'defaultPermissionSupport'  => 'N', // Default permission for built in role Support
    'categoryPermissionStaff'   => 'Y', // Should this action be available to user roles in the Staff category?
    'categoryPermissionStudent' => 'N', // Should this action be available to user roles in the Student category?
    'categoryPermissionParent'  => 'N', // Should this action be available to user roles in the Parent category?
    'categoryPermissionOther'   => 'N', // Should this action be available to user roles in the Other category?
];

// Hooks
//$hooks[] = ''; // Serialised array to create hook and set options. See Hooks documentation online.
