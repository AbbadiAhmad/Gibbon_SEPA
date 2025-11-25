<?php
namespace Gibbon\Module\Sepa\Domain;

use Gibbon\Domain\Traits\TableAware;
use Gibbon\Domain\QueryCriteria;
use Gibbon\Domain\QueryableGateway;

/**
 * SEPA Update Request Gateway
 *
 * Handles data access for parent-submitted SEPA information update requests
 * Includes cryptographic integrity verification and comprehensive audit trail
 *
 * @version v2.1.1
 * @since   v2.1.0
 */
class SepaUpdateRequestGateway extends QueryableGateway
{
    use TableAware;

    private static $tableName = 'gibbonSEPAUpdateRequest';
    private static $primaryKey = 'gibbonSEPAUpdateRequestID';
    private static $searchableColumns = ['gibbonFamilyID', 'status'];

    private $encryption;

    // Critical fields used for hash generation (in order)
    private const HASH_FIELDS = [
        'gibbonFamilyID',
        'gibbonSEPAID',
        'old_payer',
        'old_IBAN',
        'old_BIC',
        'old_SEPA_signedDate',
        'new_payer',
        'new_IBAN',
        'new_BIC',
        'new_SEPA_signedDate',
        'gibbonPersonIDSubmitted',
        'submittedDate',
        'status'
    ];

    /**
     * Get encryption helper instance (lazy initialization)
     *
     * @return SepaEncryption
     */
    private function getEncryption()
    {
        if ($this->encryption === null) {
            $this->encryption = new SepaEncryption();
        }
        return $this->encryption;
    }

    /**
     * Get all pending update requests with family and person details
     *
     * @param QueryCriteria $criteria
     * @return \Gibbon\Domain\DataSet
     */
    public function queryPendingRequests(QueryCriteria $criteria)
    {
        $query = $this
            ->newQuery()
            ->distinct()
            ->from($this->getTableName())
            ->cols([
                'gibbonSEPAUpdateRequest.*',
                'gibbonFamily.name AS familyName',
                'submitter.title AS submitterTitle',
                'submitter.surname AS submitterSurname',
                'submitter.preferredName AS submitterPreferredName',
                'gibbonSEPA.payer AS currentPayer',
                'gibbonSEPA.IBAN AS currentIBAN'
            ])
            ->innerJoin('gibbonFamily', 'gibbonSEPAUpdateRequest.gibbonFamilyID=gibbonFamily.gibbonFamilyID')
            ->innerJoin('gibbonPerson AS submitter', 'gibbonSEPAUpdateRequest.gibbonPersonIDSubmitted=submitter.gibbonPersonID')
            ->leftJoin('gibbonSEPA', 'gibbonSEPAUpdateRequest.gibbonSEPAID=gibbonSEPA.gibbonSEPAID')
            ->where('gibbonSEPAUpdateRequest.status = :status')
            ->bindValue('status', 'pending');

        return $this->runQuery($query, $criteria);
    }

    /**
     * Get all update requests for a specific family
     *
     * @param int $gibbonFamilyID
     * @param QueryCriteria $criteria
     * @return \Gibbon\Domain\DataSet
     */
    public function queryRequestsByFamily($gibbonFamilyID, QueryCriteria $criteria)
    {
        $query = $this
            ->newQuery()
            ->distinct()
            ->from($this->getTableName())
            ->cols([
                'gibbonSEPAUpdateRequest.*',
                'submitter.title AS submitterTitle',
                'submitter.surname AS submitterSurname',
                'submitter.preferredName AS submitterPreferredName',
                'approver.title AS approverTitle',
                'approver.surname AS approverSurname',
                'approver.preferredName AS approverPreferredName'
            ])
            ->innerJoin('gibbonPerson AS submitter', 'gibbonSEPAUpdateRequest.gibbonPersonIDSubmitted=submitter.gibbonPersonID')
            ->leftJoin('gibbonPerson AS approver', 'gibbonSEPAUpdateRequest.gibbonPersonIDApproved=approver.gibbonPersonID')
            ->where('gibbonSEPAUpdateRequest.gibbonFamilyID = :gibbonFamilyID')
            ->bindValue('gibbonFamilyID', $gibbonFamilyID);

        return $this->runQuery($query, $criteria);
    }

    /**
     * Get a single update request by ID with decrypted data
     * Also verifies data integrity and logs warnings if tampering detected
     *
     * @param int $gibbonSEPAUpdateRequestID
     * @param bool $verifyIntegrity Whether to verify data hash (default: true)
     * @return array|null
     */
    public function getRequestByID($gibbonSEPAUpdateRequestID, $verifyIntegrity = true)
    {
        $data = $this->getByID($gibbonSEPAUpdateRequestID);

        if (!$data) {
            return null;
        }

        // Decrypt sensitive fields
        $decryptedData = $this->decryptRequestData($data);

        // Verify integrity if requested
        if ($verifyIntegrity && !empty($data['data_hash'])) {
            $integrity = $this->verifyDataIntegrity($decryptedData);
            $decryptedData['_integrity_check'] = $integrity;

            if (!$integrity['valid']) {
                error_log(sprintf(
                    'SEPA Update Request integrity check FAILED for ID %d. Expected hash: %s, Computed hash: %s',
                    $gibbonSEPAUpdateRequestID,
                    $data['data_hash'],
                    $integrity['computed_hash']
                ));
            }
        }

        return $decryptedData;
    }

    /**
     * Check if family has pending update request
     *
     * @param int $gibbonFamilyID
     * @return bool
     */
    public function hasPendingRequest($gibbonFamilyID): bool
    {
        $data = $this->selectBy(['gibbonFamilyID' => $gibbonFamilyID, 'status' => 'pending'])->fetch();
        return !empty($data);
    }

    /**
     * Insert a new update request with encrypted data and integrity hash
     *
     * @param array $data Request data with plain text sensitive fields
     * @return int|null The inserted ID or null on failure
     */
    public function insertRequest(array $data)
    {
        // Encrypt sensitive fields before insertion
        $encryptedData = $this->encryptRequestData($data);

        // Generate integrity hash AFTER encryption
        $encryptedData['data_hash'] = $this->generateDataHash($encryptedData);

        return $this->insert($encryptedData);
    }

    /**
     * Update an update request status
     *
     * @param int $gibbonSEPAUpdateRequestID
     * @param array $data Update data (status, approval info, etc.)
     * @return bool
     */
    public function updateRequestStatus($gibbonSEPAUpdateRequestID, array $data): bool
    {
        return $this->update($gibbonSEPAUpdateRequestID, $data);
    }

    /**
     * Encrypt sensitive fields in request data
     *
     * @param array $data
     * @return array
     */
    private function encryptRequestData(array $data): array
    {
        $fieldsToEncrypt = [
            'old_payer',
            'old_IBAN',
            'old_BIC',
            'new_payer',
            'new_IBAN',
            'new_BIC'
        ];

        return $this->getEncryption()->encryptFields($data, $fieldsToEncrypt);
    }

    /**
     * Decrypt sensitive fields in request data
     *
     * @param array $data
     * @return array
     */
    private function decryptRequestData(array $data): array
    {
        $fieldsToDecrypt = [
            'old_payer',
            'old_IBAN',
            'old_BIC',
            'new_payer',
            'new_IBAN',
            'new_BIC'
        ];

        return $this->getEncryption()->decryptFields($data, $fieldsToDecrypt);
    }

    /**
     * Get decrypted new values from a request (for approval processing)
     *
     * @param int $gibbonSEPAUpdateRequestID
     * @return array|null Array with decrypted new_ fields or null
     */
    public function getDecryptedNewValues($gibbonSEPAUpdateRequestID): ?array
    {
        $request = $this->getRequestByID($gibbonSEPAUpdateRequestID);

        if (!$request) {
            return null;
        }

        return [
            'payer' => $request['new_payer'] ?? '',
            'IBAN' => $request['new_IBAN'] ?? '',
            'BIC' => $request['new_BIC'] ?? '',
            'SEPA_signedDate' => $request['new_SEPA_signedDate'] ?? null,
            'note' => $request['new_note'] ?? '',
            'customData' => $request['new_customData'] ?? null
        ];
    }

    /**
     * Generate SHA-256 hash of critical fields for integrity verification
     * Hash is computed from encrypted data to detect any tampering
     *
     * @param array $data The request data (with encrypted fields)
     * @return string SHA-256 hash (64 hex characters)
     */
    private function generateDataHash(array $data): string
    {
        // Build string from critical fields in defined order
        $hashComponents = [];

        foreach (self::HASH_FIELDS as $field) {
            $value = $data[$field] ?? '';
            // Convert to string and handle nulls
            $hashComponents[] = $field . ':' . (string)$value;
        }

        // Join all components with a delimiter
        $hashString = implode('|', $hashComponents);

        // Generate SHA-256 hash
        return hash('sha256', $hashString);
    }

    /**
     * Verify data integrity by comparing stored hash with computed hash
     *
     * @param array $data The request data (must include original data_hash and encrypted fields)
     * @return array ['valid' => bool, 'stored_hash' => string, 'computed_hash' => string]
     */
    public function verifyDataIntegrity(array $data): array
    {
        $storedHash = $data['data_hash'] ?? '';

        // Re-encrypt the decrypted fields to match original hash
        $reencryptedData = $this->encryptRequestData($data);

        // Preserve critical fields from original data
        foreach (self::HASH_FIELDS as $field) {
            if (isset($data[$field]) && !isset($reencryptedData[$field])) {
                $reencryptedData[$field] = $data[$field];
            }
        }

        // Compute hash from current data
        $computedHash = $this->generateDataHash($reencryptedData);

        return [
            'valid' => ($storedHash === $computedHash),
            'stored_hash' => $storedHash,
            'computed_hash' => $computedHash
        ];
    }

    /**
     * Batch verify integrity of multiple requests
     * Useful for auditing or detecting mass tampering
     *
     * @param array $requestIDs Array of gibbonSEPAUpdateRequestID values
     * @return array ['total' => int, 'valid' => int, 'invalid' => int, 'failures' => array]
     */
    public function batchVerifyIntegrity(array $requestIDs): array
    {
        $results = [
            'total' => count($requestIDs),
            'valid' => 0,
            'invalid' => 0,
            'failures' => []
        ];

        foreach ($requestIDs as $id) {
            $request = $this->getRequestByID($id, true);

            if (!$request) {
                continue;
            }

            if (isset($request['_integrity_check'])) {
                if ($request['_integrity_check']['valid']) {
                    $results['valid']++;
                } else {
                    $results['invalid']++;
                    $results['failures'][] = [
                        'id' => $id,
                        'stored_hash' => $request['_integrity_check']['stored_hash'],
                        'computed_hash' => $request['_integrity_check']['computed_hash']
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Get audit trail summary for a request including metadata
     *
     * @param int $gibbonSEPAUpdateRequestID
     * @return array|null Formatted audit information
     */
    public function getAuditTrail($gibbonSEPAUpdateRequestID): ?array
    {
        $request = $this->getRequestByID($gibbonSEPAUpdateRequestID, false);

        if (!$request) {
            return null;
        }

        // Parse submitter archive
        $submitterArchive = !empty($request['submitter_archive'])
            ? json_decode($request['submitter_archive'], true)
            : null;

        $audit = [
            'request_id' => $gibbonSEPAUpdateRequestID,
            'submission' => [
                'person_id' => $request['gibbonPersonIDSubmitted'],
                'timestamp' => $request['submittedDate'],
                'ip' => $submitterArchive['ip'] ?? 'Not recorded',
                'user_agent' => $submitterArchive['user_agent'] ?? 'Not recorded',
                'metadata' => $submitterArchive['metadata'] ?? null
            ],
            'status' => $request['status']
        ];

        if ($request['status'] !== 'pending') {
            // Parse approver archive
            $approverArchive = !empty($request['approver_archive'])
                ? json_decode($request['approver_archive'], true)
                : null;

            $audit['approval'] = [
                'person_id' => $request['gibbonPersonIDApproved'] ?? null,
                'timestamp' => $request['approvedDate'] ?? null,
                'ip' => $approverArchive['ip'] ?? 'Not recorded',
                'user_agent' => $approverArchive['user_agent'] ?? 'Not recorded',
                'metadata' => $approverArchive['metadata'] ?? null,
                'note' => $request['approvalNote'] ?? ''
            ];
        }

        // Include integrity check
        if (!empty($request['data_hash'])) {
            $audit['integrity'] = $this->verifyDataIntegrity($request);
        }

        return $audit;
    }
}
