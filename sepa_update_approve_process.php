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
use Gibbon\Module\Sepa\Domain\UserMetadataCollector;
use Gibbon\Data\Validator;

require_once '../../gibbon.php';

$_POST = $container->get(Validator::class)->sanitize($_POST);

$URL = $session->get('absoluteURL') . '/index.php?q=/modules/' . $session->get('module') . '/sepa_update_approve.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_update_approve.php')) {
    $URL .= '&return=error0';
    header("Location: {$URL}");
    exit;
} else {
    // Proceed
    $gibbonSEPAUpdateRequestID = $_POST['gibbonSEPAUpdateRequestID'] ?? '';
    $decision = $_POST['decision'] ?? '';
    $approvalNote = $_POST['approvalNote'] ?? null;
    $gibbonPersonID = $_SESSION[$guid]['gibbonPersonID'] ?? '';

    // Validate required fields
    if (empty($gibbonSEPAUpdateRequestID) || empty($decision)) {
        $URL .= '&return=error1';
        header("Location: {$URL}");
        exit;
    }

    $UpdateRequestGateway = $container->get(SepaUpdateRequestGateway::class);
    $SepaGateway = $container->get(SepaGateway::class);

    // Get the update request with decrypted data
    $request = $UpdateRequestGateway->getRequestByID($gibbonSEPAUpdateRequestID);

    if (!$request) {
        $URL .= '&return=error1';
        header("Location: {$URL}");
        exit;
    }

    // Check if request is still pending
    if ($request['status'] !== 'pending') {
        $URL .= '&return=warning1';
        header("Location: {$URL}");
        exit;
    }

    $gibbonFamilyID = $request['gibbonFamilyID'];
    $gibbonSEPAID = $request['gibbonSEPAID'];

    // Collect approver metadata for audit trail
    $approverMetadata = UserMetadataCollector::collectAll($gibbonPersonID);

    try {
        // Begin transaction
        $pdo->beginTransaction();

        if ($decision === 'approve') {
            // Get decrypted new values
            $newValues = $UpdateRequestGateway->getDecryptedNewValues($gibbonSEPAUpdateRequestID);

            if (!$newValues) {
                throw new Exception('Failed to retrieve new values');
            }

            // Mask IBAN before storing in gibbonSEPA
            $maskedIBAN = $SepaGateway->maskIBAN($newValues['IBAN']);
            $maskedBIC = $SepaGateway->maskBIC($newValues['BIC']); // Always returns null

            // Prepare SEPA data
            $sepaData = [
                'payer' => $newValues['payer'],
                'IBAN' => $maskedIBAN,
                'BIC' => $maskedBIC,
                'SEPA_signedDate' => $newValues['SEPA_signedDate'],
                'note' => $newValues['note'],
                'customData' => $newValues['customData']
            ];

            // Update or insert SEPA record
            if (!empty($gibbonSEPAID)) {
                // Update existing SEPA record
                $updated = $SepaGateway->update($gibbonSEPAID, $sepaData);
                if (!$updated) {
                    throw new Exception('Failed to update SEPA record');
                }
            } else {
                // Insert new SEPA record
                $sepaData['gibbonFamilyID'] = $gibbonFamilyID;
                $gibbonSEPAID = $SepaGateway->insert($sepaData);

                if (!$gibbonSEPAID) {
                    throw new Exception('Failed to create SEPA record');
                }

                // Update the request with the new gibbonSEPAID
                $UpdateRequestGateway->update($gibbonSEPAUpdateRequestID, [
                    'gibbonSEPAID' => $gibbonSEPAID
                ]);
            }

            // Update request status to approved with approver metadata
            $updateData = [
                'status' => 'approved',
                'gibbonPersonIDApproved' => $gibbonPersonID,
                'approvedDate' => date('Y-m-d H:i:s'),
                'approvalNote' => $approvalNote,
                // Add approver metadata for proof of approval
                'approver_ip' => $approverMetadata['ip'],
                'approver_user_agent' => $approverMetadata['user_agent'],
                'approver_metadata' => $approverMetadata['metadata_json']
            ];

            $updated = $UpdateRequestGateway->updateRequestStatus($gibbonSEPAUpdateRequestID, $updateData);

            if (!$updated) {
                throw new Exception('Failed to update request status');
            }

            // Commit transaction
            $pdo->commit();

            $URLSuccess = $session->get('absoluteURL') . '/index.php?q=/modules/' . $session->get('module') . '/sepa_update_approve.php';
            $URLSuccess .= '&return=success0';
            header("Location: {$URLSuccess}");

        } elseif ($decision === 'reject') {
            // Update request status to rejected with approver metadata
            $updateData = [
                'status' => 'rejected',
                'gibbonPersonIDApproved' => $gibbonPersonID,
                'approvedDate' => date('Y-m-d H:i:s'),
                'approvalNote' => $approvalNote,
                // Add approver metadata for proof of rejection
                'approver_ip' => $approverMetadata['ip'],
                'approver_user_agent' => $approverMetadata['user_agent'],
                'approver_metadata' => $approverMetadata['metadata_json']
            ];

            $updated = $UpdateRequestGateway->updateRequestStatus($gibbonSEPAUpdateRequestID, $updateData);

            if (!$updated) {
                throw new Exception('Failed to update request status');
            }

            // Commit transaction
            $pdo->commit();

            $URLSuccess = $session->get('absoluteURL') . '/index.php?q=/modules/' . $session->get('module') . '/sepa_update_approve.php';
            $URLSuccess .= '&return=success1';
            header("Location: {$URLSuccess}");

        } else {
            $pdo->rollBack();
            $URL .= '&return=error1';
            header("Location: {$URL}");
            exit;
        }

    } catch (Exception $e) {
        // Rollback on error
        $pdo->rollBack();

        error_log('SEPA Update Approval Error: ' . $e->getMessage());

        $URL .= '&return=error2';
        header("Location: {$URL}");
        exit;
    }
}
