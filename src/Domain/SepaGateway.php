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
    private static $searchableColumns = ['payer', 'IBAN', 'customData']; // Optional: Array of Columns to be searched when using the search filter

    public function getSEPAList(QueryCriteria $criteria, $gibbonSEPAIDs)
    {
        $gibbonSEPAIDList = is_array($gibbonSEPAIDs) ? implode(',', $gibbonSEPAIDs) : $gibbonSEPAIDs;

        $query = $this
            ->newQuery()
            ->from($this->getTableName())
            ->cols(['*'])
            ->orderBy(['payer']);

        if ($gibbonSEPAIDList) {
            $query->where("FIND_IN_SET(gibbonSEPAID, :gibbonSEPAIDList)")
                ->bindValue("gibbonSEPAIDList", $gibbonSEPAIDList);
        }

        return $this->runQuery($query, $criteria);
    }

    public function getSEPAListSummary(QueryCriteria $criteria, $gibbonSEPAIDs)
    {
        $gibbonSEPAIDList = is_array($gibbonSEPAIDs) ? implode(',', $gibbonSEPAIDs) : $gibbonSEPAIDs;

        $query = $this
            ->newQuery()
            ->from($this->getTableName())
            ->cols(['*'])
            ->orderBy(['payer']);

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

    public function getSEPAData1($criteria, $gibbonSEPAIDs = NULL)
    {

        $gibbonSEPAIDList = is_array($gibbonSEPAIDs) ? implode(',', $gibbonSEPAIDs) : $gibbonSEPAIDs;

        $query = $this
        ->newQuery()
            ->cols(['gibbonFamily.name as Family','gibbonSEPA.gibbonSEPAID','gibbonSEPA.payer as Owner','COALESCE(SUM(gibbonSEPAPaymentEntry.amount), 0) AS total_amount'])
            ->from('gibbonFamily')
            ->leftJoin('gibbonSEPA', 'gibbonFamily.gibbonFamilyID=gibbonSEPA.gibbonFamilyID')
            ->leftJoin('gibbonSEPAPaymentEntry', 'gibbonSEPAPaymentEntry.gibbonSEPAID=gibbonSEPA.gibbonSEPAID AND gibbonSEPAPaymentEntry.academicYear = 28')
            ->groupBy(['gibbonFamily.gibbonFamilyID', 'Family'])
            ->orderBy(['total_amount']);

        $criteria->addFilterRules([
            'search' => function ($query, $search) {
                return $query->where('gibbonFamily.name LIKE :search OR gibbonSEPA.payer LIKE :search')
                    ->bindValue('search', '%' . $search . '%');
            },
        ]);

        return $this->runQuery($query, $criteria);

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
            ->where("LOWER(REPLACE(gibbonSEPAPaymentEntry.payer, ' ', '')) = LOWER(REPLACE( :payer , ' ', '')) ")
            ->orderBy(['gibbonSEPAPaymentEntry.payer ASC'])
            ->bindValue('payer', $SEPA_details['payer']);

        return $this->runSelect($query);
    }

    public function getSEPAForPaymentEntry($payment_details)
    {
        //
        $query = $this->newSelect()
            ->cols(['gibbonSEPA.*'])
            ->from('gibbonSEPA')
            ->where("LOWER(REPLACE(gibbonSEPA.payer, ' ', '')) = LOWER(REPLACE( :payer , ' ', '')) ")
            ->orderBy(['gibbonSEPA.payer ASC'])
            ->bindValue('payer', $payment_details['payer']);

        return $this->runSelect($query)->fetchAll();
    }

    public function getFamiliesWithoutBankDetails()
    {
        // "SELECT gibbonFamily.gibbonFamilyID as value, name FROM gibbonFamily LEFT JOIN gibbonSEPA ON gibbonFamily.gibbonFamilyID = gibbonSEPA.gibbonFamilyID WHERE gibbonSEPA.gibbonFamilyID is NULL order by name";
        $query = $this->newSelect()
            ->cols(['gibbonFamily.gibbonFamilyID as value', 'gibbonFamily.name'])
            ->from('gibbonFamily')
            ->leftJoin('gibbonSEPA', 'gibbonFamily.gibbonFamilyID = gibbonSEPA.gibbonFamilyID')
            ->where("gibbonSEPA.gibbonFamilyID is NULL")
            ->orderBy(['gibbonFamily.name']);

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

    public function getUserID($personFullName)
    {
        // muliple ID can be when similar names
        $userIDs = [];
        $whereclause = "LOWER(REPLACE(CONCAT(preferredName, surname), ' ', '')) = LOWER(REPLACE('" . $personFullName . "', ' ', ''))";
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
                    'payer' => $sepaData['payer'],
                    'IBAN' => $sepaData['IBAN'],
                    'BIC' => $sepaData['BIC'],
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
            ->where("gibbonSEPAID is null")
            ->orderBy(['gibbonSEPAPaymentEntry.payer ASC']);

        $unlinkedPayments = $this->runQuery($query, $criteria);
        return $unlinkedPayments;
    }

    public function getPaymentsError($criteria)
    {
        $query = $this
            ->newSelect()
            ->cols(['gibbonSEPAPaymentEntry.*'])
            ->from('gibbonSEPAPaymentEntry')
            ->where("LOWER(REPLACE(gibbonSEPAPaymentEntry.payer, ' ', '')) NOT IN (SELECT LOWER(REPLACE(payer, ' ', '')) FROM gibbonSEPA)")
            ->orderBy(['gibbonSEPAPaymentEntry.payer ASC']);

        $unlinkedPayments = $this->runQuery($query, $criteria);
        return $unlinkedPayments;
    }

    public function paymentRecordExist($record)
    {
        $query = $this
            ->newSelect()
            ->cols(['gibbonSEPAPaymentEntry.gibbonSEPAPaymentRecordID'])
            ->from('gibbonSEPAPaymentEntry')
            ->where("booking_date = :booking_date")
            ->where("amount = :amount")
            ->where("LOWER(REPLACE(payer, ' ', '')) = LOWER(REPLACE( :payer , ' ', ''))")
            ->bindValue('booking_date', $record['booking_date'])
            ->bindValue('amount', $record['amount'])
            ->bindValue('payer', $record['payer']);

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
                'payer' => $paymentData['payer'],
                'IBAN' => $paymentData['IBAN'],
                'transaction_reference' => $paymentData['transaction_reference'],
                'transaction_message' => $paymentData['transaction_message'],
                'amount' => $paymentData['amount'],
                'note' => $paymentData['note'],
                'academicYear' => $paymentData['academicYear'],
                'gibbonSEPAID' => $paymentData['gibbonSEPAID'],
                'payment_method' => $paymentData['payment_method'],
                'gibbonUser' => $user
            ]);

        return $this->runInsert($query);

    }

    public function updatePayment($gibbonSEPAPaymentRecordID, $paymentData)
    {
        $query = $this
            ->newUpdate()
            ->table('gibbonSEPAPaymentEntry')
            ->cols([
                'booking_date' => $paymentData['booking_date'],
                'payer' => $paymentData['payer'],
                'IBAN' => $paymentData['IBAN'],
                'transaction_reference' => $paymentData['transaction_reference'],
                'transaction_message' => $paymentData['transaction_message'],
                'amount' => $paymentData['amount'],
                'note' => $paymentData['note'],
                'academicYear' => $paymentData['academicYear'],
                'gibbonSEPAID' => $paymentData['gibbonSEPAID'],
                'payment_method' => $paymentData['payment_method']
            ])
            ->where('gibbonSEPAPaymentRecordID = :gibbonSEPAPaymentRecordID')
            ->bindValue('gibbonSEPAPaymentRecordID', $gibbonSEPAPaymentRecordID);

        return $this->runUpdate($query);
    }

    public function deletePayment($gibbonSEPAPaymentRecordID)
    {
        $query = $this
            ->newDelete()
            ->from('gibbonSEPAPaymentEntry')
            ->where('gibbonSEPAPaymentRecordID = :gibbonSEPAPaymentRecordID')
            ->bindValue('gibbonSEPAPaymentRecordID', $gibbonSEPAPaymentRecordID);

        return $this->runDelete($query);
    }

    public function getPaymentByID($gibbonSEPAPaymentRecordID)
    {
        $query = $this
            ->newSelect()
            ->cols(['*'])
            ->from('gibbonSEPAPaymentEntry')
            ->where('gibbonSEPAPaymentRecordID = :gibbonSEPAPaymentRecordID')
            ->bindValue('gibbonSEPAPaymentRecordID', $gibbonSEPAPaymentRecordID);

        return $this->runSelect($query)->fetch();
    }

    public function getAllPayments(QueryCriteria $criteria)
    {
        $query = $this
            ->newSelect()
            ->cols(['*'])
            ->from('gibbonSEPAPaymentEntry')
            ->orderBy(['timestamp DESC']);

        $criteria->addFilterRules([
            'academicYear' => function ($query, $academicYear) {
                return $query->where('academicYear = :academicYear')
                    ->bindValue('academicYear', $academicYear);
            },
            'payment_method' => function ($query, $payment_method) {
                return $query->where('payment_method = :payment_method')
                    ->bindValue('payment_method', $payment_method);
            },
        ]);

        return $this->runQuery($query, $criteria);
    }

    public function getAcademicYears()
    {
        $query = $this
            ->newSelect()
            ->cols(['DISTINCT academicYear', 'academicYear'])
            ->from('gibbonSEPAPaymentEntry')
            ->orderBy(['academicYear DESC']);

        return $this->runSelect($query)->fetchAll(\PDO::FETCH_KEY_PAIR);
    }

    public function getChildEnrollmentDetails($schoolYearID , $criteria)
    {
        $query = $this
            ->newSelect()
            ->cols([
                'gibbonPerson.gibbonPersonID as childID',
                'gibbonPerson.preferredName',
                'gibbonFamilyChild.gibbonFamilyID',
                'gibbonCourseClass.gibbonCourseClassID',
                'gibbonCourseClass.nameShort as shortName',
                'gibbonCourseClassPerson.dateEnrolled',
                'gibbonCourseClassPerson.dateUnenrolled',
                'GREATEST(gibbonCourseClassPerson.dateEnrolled, gibbonSchoolYear.firstDay) as startDate',
                'LAST_DAY(COALESCE(gibbonCourseClassPerson.dateUnenrolled, gibbonSchoolYear.lastDay)) as lastDate',
                'TIMESTAMPDIFF(MONTH, GREATEST(gibbonCourseClassPerson.dateEnrolled, gibbonSchoolYear.firstDay), LAST_DAY(COALESCE(gibbonCourseClassPerson.dateUnenrolled, gibbonSchoolYear.lastDay))) as monthsEnrolled',
                'COALESCE(gibbonSepaCoursesFees.fees, 0) as courseFee',
                'COALESCE(gibbonSepaCoursesFees.fees, 0) * TIMESTAMPDIFF(MONTH, GREATEST(gibbonCourseClassPerson.dateEnrolled, gibbonSchoolYear.firstDay), LAST_DAY(COALESCE(gibbonCourseClassPerson.dateUnenrolled, gibbonSchoolYear.lastDay))) as total'
            ])
            ->from('gibbonPerson')
            ->innerJoin('gibbonFamilyChild', 'gibbonFamilyChild.gibbonPersonID = gibbonPerson.gibbonPersonID')
            ->innerJoin('gibbonCourseClassPerson', 'gibbonCourseClassPerson.gibbonPersonID = gibbonPerson.gibbonPersonID')
            ->innerJoin('gibbonCourseClass', 'gibbonCourseClass.gibbonCourseClassID = gibbonCourseClassPerson.gibbonCourseClassID')
            ->innerJoin('gibbonCourse', 'gibbonCourse.gibbonCourseID = gibbonCourseClass.gibbonCourseID')
            ->innerJoin('gibbonSchoolYear', 'gibbonSchoolYear.gibbonSchoolYearID = gibbonCourse.gibbonSchoolYearID')
            ->leftJoin('gibbonSepaCoursesFees', 'gibbonSepaCoursesFees.gibbonCourseID = gibbonCourse.gibbonCourseID')
            ->where('gibbonCourse.gibbonSchoolYearID = :schoolYearID')
            ->bindValue('schoolYearID', $schoolYearID)
            ->orderBy(['gibbonPerson.gibbonPersonID']);

        $res = $this->runQuery($query, $criteria);
        return $res;

    }

    public function getFamilyTotals($schoolYearID, $criteria)
    {
        $query = $this
            ->newSelect()
            ->cols([
                'gibbonFamilyChild.gibbonFamilyID',
                'gibbonFamily.name as familyName',
                'gibbonSEPA.payer as sepaName',
                'SUM(COALESCE(gibbonSepaCoursesFees.fees, 0) * TIMESTAMPDIFF(MONTH, GREATEST(gibbonCourseClassPerson.dateEnrolled, gibbonSchoolYear.firstDay), LAST_DAY(COALESCE(gibbonCourseClassPerson.dateUnenrolled, gibbonSchoolYear.lastDay)))) as totalDept',
                'SUM(COALESCE(gibbonSEPAPaymentEntry.amount, 0)) as payments'
            ])
            ->from('gibbonPerson')
            ->innerJoin('gibbonFamilyChild', 'gibbonFamilyChild.gibbonPersonID = gibbonPerson.gibbonPersonID')
            ->innerJoin('gibbonFamily', 'gibbonFamilyChild.gibbonFamilyID = gibbonFamily.gibbonFamilyID')
            ->innerJoin('gibbonCourseClassPerson', 'gibbonCourseClassPerson.gibbonPersonID = gibbonPerson.gibbonPersonID')
            ->innerJoin('gibbonCourseClass', 'gibbonCourseClass.gibbonCourseClassID = gibbonCourseClassPerson.gibbonCourseClassID')
            ->innerJoin('gibbonCourse', 'gibbonCourse.gibbonCourseID = gibbonCourseClass.gibbonCourseID')
            ->innerJoin('gibbonSchoolYear', 'gibbonSchoolYear.gibbonSchoolYearID = gibbonCourse.gibbonSchoolYearID')
            ->leftJoin('gibbonSepaCoursesFees', 'gibbonSepaCoursesFees.gibbonCourseID = gibbonCourse.gibbonCourseID')
            ->leftJoin('gibbonSEPA', 'gibbonFamilyChild.gibbonFamilyID = gibbonSEPA.gibbonFamilyID')
            ->leftJoin('gibbonSEPAPaymentEntry', 'gibbonSEPA.gibbonSEPAID = gibbonSEPAPaymentEntry.gibbonSEPAID AND gibbonSEPAPaymentEntry.academicYear = gibbonSchoolYear.name')
            ->where('gibbonCourse.gibbonSchoolYearID = :schoolYearID')
            ->bindValue('schoolYearID', $schoolYearID)
            ->groupBy(['gibbonFamilyChild.gibbonFamilyID', 'gibbonFamily.name', 'gibbonSEPA.payer'])
            ->orderBy(['gibbonFamilyChild.gibbonFamilyID']);

        return $this->runQuery($query, $criteria);
    }
}
