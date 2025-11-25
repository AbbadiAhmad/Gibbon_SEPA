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
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

use Gibbon\Module\Sepa\Domain\IssuesGateway;
use Gibbon\Data\Validator;

require_once '../../gibbon.php';

$_POST = $container->get(Validator::class)->sanitize($_POST);

$URL = $_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/Sepa/sepa_issues_settings.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_issues_settings.php')) {
    // Access denied
    header("Location: {$URL}&return=error0");
    exit;
} else {
    // Proceed!
    $issuesGateway = $container->get(IssuesGateway::class);

    // Get form data
    $similarIBANEnabled = $_POST['similar_iban_detection_enabled'] ?? '0';
    $similarPayerEnabled = $_POST['similar_payer_detection_enabled'] ?? '0';
    $oldDateThreshold = $_POST['sepa_old_date_threshold_years'] ?? '3';
    $balanceMethod = $_POST['balance_method_less_than'] ?? 'number';
    $balanceAttribute = $_POST['balance_method_attribute'] ?? '2';
    $balanceMoreThan = $_POST['balance_method_more_than_attribute'] ?? '10';

    // Validate inputs
    if (empty($oldDateThreshold) || !is_numeric($oldDateThreshold) || $oldDateThreshold < 1) {
        header("Location: {$URL}&return=error1");
        exit;
    }

    if (empty($balanceAttribute) || !is_numeric($balanceAttribute) || $balanceAttribute < 0) {
        header("Location: {$URL}&return=error1");
        exit;
    }

    if (empty($balanceMoreThan) || !is_numeric($balanceMoreThan) || $balanceMoreThan < 0) {
        header("Location: {$URL}&return=error1");
        exit;
    }

    if (!in_array($balanceMethod, ['number', 'percentage', 'proportion_to_academic_year'])) {
        header("Location: {$URL}&return=error1");
        exit;
    }

    try {
        // Save settings
        $issuesGateway->setIssueSetting(
            'similar_iban_detection_enabled',
            $similarIBANEnabled,
            'Enable detection of similar IBANs'
        );

        $issuesGateway->setIssueSetting(
            'similar_payer_detection_enabled',
            $similarPayerEnabled,
            'Enable detection of similar payer names'
        );

        $issuesGateway->setIssueSetting(
            'sepa_old_date_threshold_years',
            $oldDateThreshold,
            'Number of years after which SEPA authorization is considered old'
        );

        $issuesGateway->setIssueSetting(
            'balance_method_less_than',
            $balanceMethod,
            'Balance detection method: number, percentage, or proportion_to_academic_year'
        );

        $issuesGateway->setIssueSetting(
            'balance_method_attribute',
            $balanceAttribute,
            'Threshold value for low balance detection'
        );

        $issuesGateway->setIssueSetting(
            'balance_method_more_than_attribute',
            $balanceMoreThan,
            'Threshold value for high balance detection (in euros)'
        );

        // Success
        header("Location: {$URL}&return=success0");
        exit;
    } catch (Exception $e) {
        // Error
        header("Location: {$URL}&return=error2");
        exit;
    }
}
