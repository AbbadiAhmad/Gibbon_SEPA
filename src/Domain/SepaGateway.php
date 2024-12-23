<?php
namespace Gibbon\Module\Sepa\Domain; //Replace ModuleName with your module's name, ommiting spaces

use Gibbon\Domain\Traits\TableAware;
use Gibbon\Domain\QueryCriteria;
use Gibbon\Domain\QueryableGateway;

/**
 * SEPA Gateway
 *
 * @version v0
 * @since   v0
 */
class SepaGateway extends QueryableGateway
{
    use TableAware;

    private static $tableName = 'gibbonSEPA'; //The name of the table you will primarily be querying
    private static $primaryKey = 'gibbonSEPAID'; //The primaryKey of said table
    private static $searchableColumns = ['SEPA_ownerName', 'SEPA_IBAN', 'customData']; // Optional: Array of Columns to be searched when using the search filter

    public function getSEPAList(QueryCriteria $criteria, $gibbonSEPAIDs)
    {
        $gibbonSEPAIDList = is_array($gibbonSEPAIDs) ? implode(',', $gibbonSEPAIDs) : $gibbonSEPAIDs;

        $query = $this
            ->newQuery()
            ->from($this->getTableName())
            ->cols(['*'])
            ->orderBy(['SEPA_ownerName']);

        if ($gibbonSEPAIDList) {
            $query->where("FIND_IN_SET(gibbonSEPAID, :gibbonSEPAIDList)")
                ->bindValue("gibbonSEPAIDList", $gibbonSEPAIDList);
        }

        return $this->runQuery($query, $criteria);
    }


    public function selectAdultsByFamily($gibbonFamilyIDs)
    {
        $gibbonFamilyIDList = is_array($gibbonFamilyIDs) ? implode(',', $gibbonFamilyIDs) : $gibbonFamilyIDs;

        $query = $this
            ->newSelect()
            ->cols(['gibbonFamilyAdult.gibbonFamilyID', 'gibbonPerson.gibbonPersonID', 'gibbonPerson.title', 'gibbonPerson.preferredName', 'gibbonPerson.surname', 'gibbonPerson.status', 'gibbonPerson.email'])
            ->from('gibbonFamilyAdult')
            ->innerJoin('gibbonPerson', 'gibbonFamilyAdult.gibbonPersonID=gibbonPerson.gibbonPersonID')
            ->where('FIND_IN_SET(gibbonFamilyAdult.gibbonFamilyID, :gibbonFamilyIDList)')
            ->bindValue('gibbonFamilyIDList', $gibbonFamilyIDList)
            ->orderBy(['gibbonFamilyAdult.contactPriority', 'gibbonPerson.surname', 'gibbonPerson.preferredName']);

        return $this->runSelect($query);
    }


    public function selectChildrenByFamily($gibbonFamilyIDs)
    {
        $gibbonFamilyIDList = is_array($gibbonFamilyIDs) ? implode(',', $gibbonFamilyIDs) : $gibbonFamilyIDs;

        $query = $this
            ->newSelect()
            ->cols(['gibbonFamilyChild.gibbonFamilyID', 'gibbonPerson.gibbonPersonID', 'gibbonPerson.title', 'gibbonPerson.preferredName', 'gibbonPerson.surname', 'gibbonPerson.status', 'gibbonPerson.email'])
            ->from('gibbonFamilyChild')
            ->innerJoin('gibbonPerson', 'gibbonFamilyChild.gibbonPersonID=gibbonPerson.gibbonPersonID')
            ->where('FIND_IN_SET(gibbonFamilyChild.gibbonFamilyID, :gibbonFamilyIDList)')
            ->bindValue('gibbonFamilyIDList', $gibbonFamilyIDList)
            ->orderBy(['gibbonPerson.surname', 'gibbonPerson.preferredName']);

        return $this->runSelect($query);
    }

    public function getSEPAData($criteria, $gibbonSEPAIDs = NULL)
    {

        $SEPAs = $this->getSEPAList($criteria, $gibbonSEPAIDs);

        $familyIDs = $SEPAs->getColumn('gibbonFamilyID');

        $adults = $this->selectAdultsByFamily($familyIDs)->fetchGrouped();
        $SEPAs->joinColumn('gibbonFamilyID', 'adults', $adults);

        $children = $this->selectChildrenByFamily($familyIDs)->fetchGrouped();
        $SEPAs->joinColumn('gibbonFamilyID', 'children', $children);

        return $SEPAs;

    }
    public function getPaymentEntries($SEPA_details)
    {
        //
        $query = $this->newSelect()
            ->cols(['gibbonSEPAPaymentEntry.*'])
            ->from('gibbonSEPAPaymentEntry')
            ->where("gibbonSEPAPaymentEntry.SEPA_ownerName = '{$SEPA_details['SEPA_ownerName']}' ")
            ->orderBy(['gibbonSEPAPaymentEntry.SEPA_ownerName ASC']);

        return $this->runSelect($query);
    }

    public function getFamilySEPA($familyIDs)
    {
        $familyIDs_Str = is_array($familyIDs) ? implode(',', $familyIDs) : $familyIDs;
        $query = $this
            ->newQuery()
            ->from($this->getTableName())
            ->cols(['*'])
            ->where('FIND_IN_SET(gibbonFamilyID, :familyIDs_Str)')
            ->bindValue('familyIDs_Str', $familyIDs_Str);

        return $this->runSelect($query)->fetchAll();

    }

    public function getfamilyPerPerson($userID)
    {
        $familyIDs = [];
        // find in family's adults 
        $query = $this
            ->newQuery()
            ->from("gibbonFamilyAdult")
            ->cols(['gibbonFamilyID'])
            ->where('gibbonPersonID = :userID ')
            ->bindValue('userID', $userID);


        foreach ($this->runSelect($query) as $item) {
            $familyIDs[] = $item['gibbonFamilyID'];
        }
        // find in family's childs 
        $query = $this
            ->newQuery()
            ->from("gibbonFamilyChild")
            ->cols(['gibbonFamilyID'])
            ->where('gibbonPersonID = :userID ')
            ->bindValue('userID', $userID);

        foreach ($this->runSelect($query) as $item) {
            $familyIDs[] = $item['gibbonFamilyID'];
        }


        return array_unique($familyIDs);

    }

    public function getSEPAPerPerson($userID)
    {
        $familyIDs = $this->getfamilyPerPerson($userID);
        return $this->getFamilySEPA($familyIDs);

    }

    public function getUserID($whereclause)
    {
        // muliple ID can be when similar names
        $userIDs = [];
        $query = $this
            ->newQuery()
            ->from("gibbonPerson")
            ->cols(['gibbonPersonID'])
            ->where($whereclause);

        foreach ($this->runSelect($query) as $item) {
            $userIDs[] = $item['gibbonPersonID'];
        }
        return $userIDs;

    }

    public function insertSEPAByUserName($userID, $sepaData)
    {
        $familyIDs = $this->getfamilyPerPerson($userID);
        // Insert data into database
        $result = [];
        foreach ($familyIDs as $familyID) {
            if ($this->getFamilySEPA($familyID)) {
                continue; // the SEPA information is already inserted
            }
            $query = $this
                ->newInsert()
                ->into('gibbonSEPA')
                ->cols([
                    'gibbonFamilyID' => $familyID,
                    'SEPA_ownerName' => $sepaData['SEPA_ownerName'],
                    'SEPA_IBAN' => $sepaData['SEPA_IBAN'],
                    'SEPA_BIC' => $sepaData['SEPA_BIC'],
                    'SEPA_signedDate' => $sepaData['SEPA_signedDate'],
                    'note' => $sepaData['note'],
                ]);

            $result[] = $this->runInsert($query);

        }
        return $result;
    }

    public function getUnlinkedPayments($criteria)
    {
        $query = $this
            ->newSelect()
            ->cols(['gibbonSEPAPaymentEntry.*'])
            ->from('gibbonSEPAPaymentEntry')
            ->where("gibbonSEPAPaymentEntry.SEPA_ownerName NOT IN (SELECT SEPA_ownerName FROM gibbonSEPA)")
            ->orderBy(['gibbonSEPAPaymentEntry.SEPA_ownerName ASC']);

        $unlinkedPayments = $this->runQuery($query, $criteria);
        return $unlinkedPayments;
    }

    public function paymentRecordExist($whereClause)
    {
        $query = $this
            ->newSelect()
            ->cols(['gibbonSEPAPaymentEntry.gibbonSEPAPaymentRecordID'])
            ->from('gibbonSEPAPaymentEntry')
            ->where($whereClause);
        $result = count($this->runSelect($query)->fetchAll()) > 0;
        return $result;
    }

    public function insertPayment($paymentData, $user)
    {
        $query = $this
            ->newInsert()
            ->into('gibbonSEPAPaymentEntry')
            ->cols([
                'booking_date' => $paymentData['booking_date'],
                'SEPA_ownerName' => $paymentData['SEPA_ownerName'],
                'SEPA_IBAN' => $paymentData['SEPA_IBAN'],
                'SEPA_transaction' => $paymentData['SEPA_transaction'],
                'payment_message' => $paymentData['payment_message'],
                'amount' => $paymentData['amount'],
                'note' => $paymentData['note'],
                'user' => $user
            ]);

        $this->runInsert($query);

    }
}
