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
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

use Gibbon\Module\Sepa\Domain\SepaGateway;
use Gibbon\Module\Sepa\Domain\SepaUpdateRequestGateway;
use Gibbon\Module\Sepa\Domain\CustomFieldsGateway;
use Gibbon\Module\Sepa\Domain\UserMetadataCollector;
use Gibbon\Data\Validator;

require_once '../../gibbon.php';

$_POST = $container->get(Validator::class)->sanitize($_POST);

$URL = $session->get('absoluteURL') . '/index.php?q=/modules/' . $session->get('module') . '/sepa_update_request.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_update_request.php')) {
    $URL .= '&return=error0';
    header("Location: {$URL}");
    exit;
} else {
    // Proceed
    $gibbonFamilyID = $_POST['gibbonFamilyID'] ?? '';
    $gibbonSEPAID = $_POST['gibbonSEPAID'] ?? null;
    $gibbonPersonID = $_SESSION[$guid]['gibbonPersonID'] ?? '';

    $new_payer = $_POST['new_payer'] ?? '';
    $new_IBAN = $_POST['new_IBAN'] ?? '';
    $new_BIC = $_POST['new_BIC'] ?? null;
    $new_SEPA_signedDate = $_POST['new_SEPA_signedDate'] ?? null;
    $new_note = $_POST['new_note'] ?? null;

    // Validate required fields
    if (empty($gibbonFamilyID) || empty($new_payer) || empty($new_IBAN)) {
        $URL .= '&return=error1';
        header("Location: {$URL}");
        exit;
    }

    // Verify user has access to this family
    $dataFamily = array('gibbonPersonID' => $gibbonPersonID, 'gibbonFamilyID' => $gibbonFamilyID);
    $sqlFamily = 'SELECT gibbonFamilyID FROM gibbonFamilyAdult WHERE gibbonPersonID=:gibbonPersonID AND gibbonFamilyID=:gibbonFamilyID AND childDataAccess="Y"';
    $resultFamily = $pdo->executeQuery($dataFamily, $sqlFamily);

    if ($resultFamily->rowCount() == 0) {
        $URL .= '&return=error0';
        header("Location: {$URL}");
        exit;
    }

    $UpdateRequestGateway = $container->get(SepaUpdateRequestGateway::class);

    // Check for existing pending request
    if ($UpdateRequestGateway->hasPendingRequest($gibbonFamilyID)) {
        $URL .= '&return=warning1';
        header("Location: {$URL}");
        exit;
    }

    // Get current SEPA data if updating existing record
    $SepaGateway = $container->get(SepaGateway::class);
    $currentSEPA = null;

    if (!empty($gibbonSEPAID)) {
        $currentSEPA = $SepaGateway->getByID($gibbonSEPAID);
    } else {
        // Check if family has SEPA record
        $familySEPA = $SepaGateway->getFamilySEPA($gibbonFamilyID);
        if (!empty($familySEPA)) {
            $currentSEPA = $familySEPA[0];
            $gibbonSEPAID = $currentSEPA['gibbonSEPAID'];
        }
    }

    // Handle custom fields
    $CustomFieldsGateway = $container->get(CustomFieldsGateway::class);
    $customFields = $CustomFieldsGateway->getCustomFields();
    $new_customData = null;

    if (!empty($customFields)) {
        $customFieldValues = [];
        foreach ($customFields as $field) {
            $fieldName = 'C_' . $field['gibbonSEPACustomFieldID'];
            if (isset($_POST[$fieldName])) {
                $customFieldValues[$fieldName] = $_POST[$fieldName];
            }
        }
        if (!empty($customFieldValues)) {
            $new_customData = json_encode($customFieldValues);
        }
    }

    // Collect user metadata for audit trail
    $userMetadata = UserMetadataCollector::collectAll($gibbonPersonID);

    // Prepare update request data
    $requestData = [
        'gibbonFamilyID' => $gibbonFamilyID,
        'gibbonSEPAID' => $gibbonSEPAID,
        'gibbonPersonIDSubmitted' => $gibbonPersonID,
        'submittedDate' => date('Y-m-d H:i:s'),
        'status' => 'pending',
        // Store all submitter metadata in single JSON field
        'submitter_archive' => json_encode([
            'ip' => $userMetadata['ip'],
            'user_agent' => $userMetadata['user_agent'],
            'metadata' => json_decode($userMetadata['metadata_json'], true)
        ])
    ];

    // Store old values (current SEPA data) - will be encrypted by gateway
    if ($currentSEPA) {
        $requestData['old_payer'] = $currentSEPA['payer'] ?? null;
        $requestData['old_IBAN'] = $currentSEPA['IBAN'] ?? null;
        $requestData['old_BIC'] = null; // BIC is never stored in gibbonSEPA
        $requestData['old_SEPA_signedDate'] = $currentSEPA['SEPA_signedDate'] ?? null;
        $requestData['old_note'] = $currentSEPA['note'] ?? null;
        $requestData['old_customData'] = $currentSEPA['customData'] ?? null;
    }

    // Store new values (requested changes) - will be encrypted by gateway
    $requestData['new_payer'] = $new_payer;
    $requestData['new_IBAN'] = $new_IBAN;
    $requestData['new_BIC'] = $new_BIC;
    $requestData['new_SEPA_signedDate'] = $new_SEPA_signedDate;
    $requestData['new_note'] = $new_note;
    $requestData['new_customData'] = $new_customData;

    // Insert the update request (encryption happens in gateway)
    $insertID = $UpdateRequestGateway->insertRequest($requestData);

    if ($insertID) {
        $URLSuccess = $session->get('absoluteURL') . '/index.php?q=/modules/' . $session->get('module') . '/sepa_update_request.php';
        $URLSuccess .= '&return=success0';
        header("Location: {$URLSuccess}");
    } else {
        $URL .= '&return=error2';
        header("Location: {$URL}");
    }
}
