<?php
namespace Gibbon\Module\Sepa\Domain;

use Gibbon\Domain\Traits\TableAware;
use Gibbon\Domain\QueryCriteria;
use Gibbon\Domain\QueryableGateway;

/**
 * SEPA Update Request Gateway
 *
 * Handles data access for parent-submitted SEPA information update requests
 *
 * @version v2.1.0
 * @since   v2.1.0
 */
class SepaUpdateRequestGateway extends QueryableGateway
{
    use TableAware;

    private static $tableName = 'gibbonSEPAUpdateRequest';
    private static $primaryKey = 'gibbonSEPAUpdateRequestID';
    private static $searchableColumns = ['gibbonFamilyID', 'status'];

    private SepaEncryption $encryption;

    /**
     * Constructor - initialize encryption helper
     */
    public function __construct(\Gibbon\Domain\DataSet $dataSet = null)
    {
        parent::__construct($dataSet);
        $this->encryption = new SepaEncryption();
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
     *
     * @param int $gibbonSEPAUpdateRequestID
     * @return array|null
     */
    public function getRequestByID($gibbonSEPAUpdateRequestID)
    {
        $data = $this->getByID($gibbonSEPAUpdateRequestID);

        if (!$data) {
            return null;
        }

        // Decrypt sensitive fields
        return $this->decryptRequestData($data);
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
     * Insert a new update request with encrypted data
     *
     * @param array $data Request data with plain text sensitive fields
     * @return int|null The inserted ID or null on failure
     */
    public function insertRequest(array $data)
    {
        // Encrypt sensitive fields before insertion
        $encryptedData = $this->encryptRequestData($data);

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

        return $this->encryption->encryptFields($data, $fieldsToEncrypt);
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

        return $this->encryption->decryptFields($data, $fieldsToDecrypt);
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
}
