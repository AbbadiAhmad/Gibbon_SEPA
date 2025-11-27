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

    /**
     * Mask IBAN to show only first 2 characters + 4 asterisks + last 3 characters
     * Returns NULL if IBAN is too short (less than 5 characters) or empty
     *
     * @param string|null $iban The IBAN to mask
     * @return string|null Masked IBAN (e.g., "DE****678") or NULL
     */
    public function maskIBAN($iban)
    {
        if (empty($iban)) {
            return null;
        }

        // Remove spaces and convert to uppercase
        $iban = strtoupper(str_replace(' ', '', $iban));

        // If IBAN is too short (less than 5 characters), return NULL
        if (strlen($iban) < 5) {
            return null;
        }

        // Return first 2 + 4 asterisks + last 3
        return substr($iban, 0, 2) . '****' . substr($iban, -3);
    }

    /**
     * Mask BIC - always returns NULL as per security requirements
     * BIC will not be stored in the database
     *
     * @param string|null $bic The BIC code (ignored)
     * @return null Always returns NULL
     */
    public function maskBIC($bic)
    {
        return null;
    }

    public function getSEPAList(QueryCriteria $criteria, $gibbonSEPAIDs)
    {
        $gibbonSEPAIDList = is_array($gibbonSEPAIDs) ? implode(',', $gibbonSEPAIDs) : $gibbonSEPAIDs;

        $query = $this
            ->newQuery()
            ->from($this->getTableName())
            ->cols(['*']);

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

    /*     public function getPaymentEntries($SEPA_details)
        {
            //
            $query = $this->newSelect()
                ->cols(['gibbonSEPAPaymentEntry.*'])
                ->from('gibbonSEPAPaymentEntry')
                ->where("LOWER(REPLACE(gibbonSEPAPaymentEntry.payer, ' ', '')) = LOWER(REPLACE( :payer , ' ', '')) ")
                ->orderBy(['gibbonSEPAPaymentEntry.booking_date ASC'])
                ->bindValue('payer', $SEPA_details['payer']);

            return $this->runSelect($query);
        } */

    public function getPaymentEntriesByFamily($gibbonSEPAID, $schoolYearID)
    {
        $query = $this
            ->newSelect()
            ->cols(['*'])
            ->from('gibbonSEPAPaymentEntry')
            ->where('gibbonSEPAPaymentEntry.gibbonSEPAID = :gibbonSEPAID')
            ->where('gibbonSEPAPaymentEntry.academicYear = :academicYear')
            ->bindValue('gibbonSEPAID', $gibbonSEPAID)
            ->bindValue('academicYear', $schoolYearID)
            ->orderBy(['gibbonSEPAPaymentEntry.booking_date DESC']);

        return $this->runSelect($query)->fetchAll();
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

    /**
     * Get SEPA records by IBAN
     *
     * IMPORTANT: Since IBANs are now stored in masked format (XX****XXX),
     * this method searches for masked IBANs. Multiple different full IBANs
     * may have the same masked format, so this can return multiple results.
     *
     * Recommendation: Use in combination with payer name matching for better accuracy.
     *
     * @param string $iban The IBAN to search for (will be masked before comparison)
     * @return array Array of matching SEPA records (may be multiple)
     */
    public function getSEPAByIBAN($iban)
    {
        $query = $this->newSelect()
            ->cols(['gibbonSEPA.*'])
            ->from('gibbonSEPA')
            ->where("REPLACE(gibbonSEPA.IBAN, ' ', '') = REPLACE(:iban, ' ', '')")
            ->bindValue('iban', $iban);

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
            ->newSelect()
            ->from($this->getTableName())
            ->cols(['*'])
            ->where('FIND_IN_SET(gibbonFamilyID, :familyIDs_Str)')
            ->bindValue('familyIDs_Str', $familyIDs_Str);

        return $this->runSelect($query)->fetchAll();

    }

    public function getSEPAByPayer($payer)
    {
        $query = $this
            ->newSelect()
            ->from('gibbonSEPA')
            ->cols(['*'])
            ->where("LOWER(REPLACE(payer, ' ', '')) = LOWER(REPLACE( :payer , ' ', '')) ")
            ->bindValue('payer', $payer);

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

        public function getSEPAByUserID($userID)
    {
        $familyIDs = $this->getfamilyPerPerson($userID);
        $result = [];

        foreach ($familyIDs as $familyID) {
            $sepaData = $this->getFamilySEPA($familyID);
            if (!empty($sepaData)) {
                $result = array_merge($result, $sepaData);
            }
        }

        return $result;
    }


    public function getUserID($personFullName)
    {
        // muliple ID can be when similar names
        $userIDs = [];
        $whereclause = "status= 'Full' AND LOWER(REPLACE(CONCAT(preferredName, surname), ' ', '')) = LOWER(REPLACE('" . $personFullName . "', ' ', ''))";
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
                    'gibbonFamilyID' => trim($familyID),
                    'payer' => trim($sepaData['payer']),
                    'IBAN' => $this->maskIBAN($sepaData['IBAN'] ?? null),
                    'BIC' => $this->maskBIC($sepaData['BIC'] ?? null),
                    'SEPA_signedDate' => $sepaData['SEPA_signedDate'],
                    'note' => trim($sepaData['note']),
                ]);

            $result[] = $this->runInsert($query);

        }
        return $result;
    }

    public function getUnlinkedPayments($criteria)
    {
        $query = $this
            ->newQuery()
            ->cols(['gibbonSEPAPaymentEntry.*'])
            ->from('gibbonSEPAPaymentEntry')
            ->where("gibbonSEPAID is null")
            ->orderBy(['gibbonSEPAPaymentEntry.payer ASC']);

        $unlinkedPayments = $this->runQuery($query, $criteria);
        return $unlinkedPayments;
    }
    /* 
        public function getPaymentsError($criteria)
        {
            $query = $this
                ->newQuery()
                ->cols(['gibbonSEPAPaymentEntry.*'])
                ->from('gibbonSEPAPaymentEntry')
                ->where("LOWER(REPLACE(gibbonSEPAPaymentEntry.payer, ' ', '')) NOT IN (SELECT LOWER(REPLACE(payer, ' ', '')) FROM gibbonSEPA)")
                ->orderBy(['gibbonSEPAPaymentEntry.payer ASC']);

            $unlinkedPayments = $this->runQuery($query, $criteria);
            return $unlinkedPayments;
        } */

    public function paymentRecordExist($record)
    {
        $query = $this
            ->newSelect()
            ->cols(['gibbonSEPAPaymentEntry.gibbonSEPAPaymentRecordID'])
            ->from('gibbonSEPAPaymentEntry')
            ->where("(transaction_reference is not Null AND transaction_reference !='' AND transaction_reference = :transaction_reference) OR (booking_date = :booking_date AND amount = :amount AND LOWER(REPLACE(payer, ' ', '')) = LOWER(REPLACE( :payer , ' ', '')))")
            ->bindValue('booking_date', $record['booking_date'])
            ->bindValue('amount', $record['amount'])
            ->bindValue('payer', $record['payer'])
            ->bindValue('transaction_reference', $record['transaction_reference']);
            $res = $this->runSelect($query)->fetchAll();
        $result = count($res) > 0;
        return $result;
    }

    public function insertPayment($paymentData, $user)
    {
        $query = $this
            ->newInsert()
            ->into('gibbonSEPAPaymentEntry')
            ->cols([
                'booking_date' => $paymentData['booking_date'],
                'payer' => trim($paymentData['payer']),
                'IBAN' => $this->maskIBAN($paymentData['IBAN'] ?? null),
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
                'payer' => trim($paymentData['payer']),
                'IBAN' => $this->maskIBAN($paymentData['IBAN'] ?? null),
                'transaction_reference' => trim($paymentData['transaction_reference']),
                'transaction_message' => trim($paymentData['transaction_message']),
                'amount' => $paymentData['amount'],
                'note' => trim($paymentData['note']),
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
            ->newQuery()
            ->cols([
                'gibbonSEPAPaymentEntry.*',
                'gibbonFamily.name as familyName',
                'gibbonSchoolYear.name as yearName'
            ])
            ->from('gibbonSEPAPaymentEntry')
            ->leftJoin('gibbonSEPA', 'gibbonSEPAPaymentEntry.gibbonSEPAID = gibbonSEPA.gibbonSEPAID')
            ->leftJoin('gibbonFamily', 'gibbonSEPA.gibbonFamilyID = gibbonFamily.gibbonFamilyID')
            ->leftJoin('gibbonSchoolYear', 'gibbonSEPAPaymentEntry.academicYear = gibbonSchoolYear.gibbonSchoolYearID')
            ->orderBy(['timestamp DESC']);

        $criteria->addFilterRules([
            'academicYear' => function ($query, $academicYear) {
                return $query->where('gibbonSEPAPaymentEntry.academicYear = :academicYear')
                    ->bindValue('academicYear', $academicYear);
            },
            'payment_method' => function ($query, $payment_method) {
                return $query->where('payment_method = :payment_method')
                    ->bindValue('payment_method', $payment_method);
            },
        ]);
        $search = $criteria->getSearchText();
        if (!empty($search)) {
            $query->where('(gibbonSEPAPaymentEntry.transaction_reference LIKE :search OR gibbonSEPAPaymentEntry.IBAN LIKE :search OR gibbonSEPAPaymentEntry.payer LIKE :search OR gibbonSEPAPaymentEntry.booking_date LIKE :search OR gibbonSEPAPaymentEntry.amount LIKE :search)')
                ->bindValue('search', '%' . $search . '%');
        }

        return $this->runQuery($query, $criteria);
    }

    public function getPaymentsByDateRange($fromDate, $toDate, QueryCriteria $criteria, $gibbonSEPAID = null)
    {
        $query = $this
            ->newQuery()
            ->cols([
                'gibbonSEPAPaymentEntry.*',
                'gibbonFamily.name as familyName',
                'gibbonSchoolYear.name as yearName'
            ])
            ->from('gibbonSEPAPaymentEntry')
            ->leftJoin('gibbonSEPA', 'gibbonSEPAPaymentEntry.gibbonSEPAID = gibbonSEPA.gibbonSEPAID')
            ->leftJoin('gibbonFamily', 'gibbonSEPA.gibbonFamilyID = gibbonFamily.gibbonFamilyID')
            ->leftJoin('gibbonSchoolYear', 'gibbonSEPAPaymentEntry.academicYear = gibbonSchoolYear.gibbonSchoolYearID')
            ->where('gibbonSEPAPaymentEntry.booking_date BETWEEN :fromDate AND :toDate')
            ->bindValue('fromDate', $fromDate)
            ->bindValue('toDate', $toDate)
            ->orderBy(['gibbonSEPAPaymentEntry.booking_date DESC']);

        // Filter by SEPA ID if specified
        if (!empty($gibbonSEPAID)) {
            $query->where('gibbonSEPAPaymentEntry.gibbonSEPAID = :gibbonSEPAID')
                ->bindValue('gibbonSEPAID', $gibbonSEPAID);
        }

        $criteria->addFilterRules([
            'academicYear' => function ($query, $academicYear) {
                return $query->where('gibbonSEPAPaymentEntry.academicYear = :academicYear')
                    ->bindValue('academicYear', $academicYear);
            },
            'payment_method' => function ($query, $payment_method) {
                return $query->where('payment_method = :payment_method')
                    ->bindValue('payment_method', $payment_method);
            },
        ]);

        return $this->runQuery($query, $criteria);
    }

    public function getPaymentsSumByDateRange($fromDate, $toDate, $gibbonSEPAID = null)
    {
        $query = $this
            ->newSelect()
            ->cols(['SUM(COALESCE(gibbonSEPAPaymentEntry.amount, 0)) as totalAmount'])
            ->from('gibbonSEPAPaymentEntry')
            ->where('gibbonSEPAPaymentEntry.booking_date BETWEEN :fromDate AND :toDate')
            ->bindValue('fromDate', $fromDate)
            ->bindValue('toDate', $toDate);

        // Filter by SEPA ID if specified
        if (!empty($gibbonSEPAID)) {
            $query->where('gibbonSEPAPaymentEntry.gibbonSEPAID = :gibbonSEPAID')
                ->bindValue('gibbonSEPAID', $gibbonSEPAID);
        }

        $result = $this->runSelect($query)->fetch();
        return $result['totalAmount'] ?? 0;
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

    public function getAllSchoolYears()
    {
        $query = $this
            ->newSelect()
            ->cols(['gibbonSchoolYearID', 'name', 'status'])
            ->from('gibbonSchoolYear')
            ->orderBy(['sequenceNumber DESC']);

        $years = $this->runSelect($query)->fetchAll();

        // Format the array with (current) marker for the current year
        $result = [];
        foreach ($years as $year) {
            $name = $year['name'];
            if ($year['status'] == 'Current') {
                $name .= ' (current)';
            }
            $result[$year['gibbonSchoolYearID']] = $name;
        }

        return $result;
    }

    /**
     * Get child enrollment details for a school year
     * Refactored to use simpler SQL and PHP calculations
     */
    public function getChildEnrollmentDetails($schoolYearID, $criteria)
    {
        // Get school year information for calculations
        $schoolYear = $this->getSchoolYearByID($schoolYearID);
        if (!$schoolYear) {
            return $this->runQuery($this->newQuery()->from('gibbonPerson')->cols(['*'])->where('1=0'), $criteria);
        }

        // Simplified query - just fetch basic data, no complex calculations
        $query = $this
            ->newQuery()
            ->cols([
                'gibbonPerson.gibbonPersonID as childID',
                'CONCAT(gibbonPerson.preferredName," ",  gibbonPerson.surname) as student_name',
                'gibbonPerson.dateEnd as personDateEnd',
                'gibbonFamilyChild.gibbonFamilyID',
                'gibbonFamily.name as familyName',
                'gibbonCourseClass.gibbonCourseClassID',
                'gibbonCourse.nameShort as shortName',
                'gibbonCourseClassPerson.dateEnrolled',
                'gibbonCourseClassPerson.dateUnenrolled',
                'COALESCE(gibbonSepaCoursesFees.fees, 0) as courseFee'
            ])
            ->from('gibbonPerson')
            ->innerJoin('gibbonFamilyChild', 'gibbonFamilyChild.gibbonPersonID = gibbonPerson.gibbonPersonID')
            ->innerJoin('gibbonFamily', 'gibbonFamily.gibbonFamilyID = gibbonFamilyChild.gibbonFamilyID')
            ->innerJoin('gibbonCourseClassPerson', 'gibbonCourseClassPerson.gibbonPersonID = gibbonPerson.gibbonPersonID')
            ->innerJoin('gibbonCourseClass', 'gibbonCourseClass.gibbonCourseClassID = gibbonCourseClassPerson.gibbonCourseClassID')
            ->innerJoin('gibbonCourse', 'gibbonCourse.gibbonCourseID = gibbonCourseClass.gibbonCourseID')
            ->innerJoin('gibbonSchoolYear', 'gibbonSchoolYear.gibbonSchoolYearID = gibbonCourse.gibbonSchoolYearID')
            ->leftJoin('gibbonSepaCoursesFees', 'gibbonSepaCoursesFees.gibbonCourseID = gibbonCourse.gibbonCourseID')
            ->where('gibbonCourse.gibbonSchoolYearID = :schoolYearID')
            ->where('gibbonCourseClassPerson.role = :role')
            ->bindValue('schoolYearID', $schoolYearID)
            ->bindValue('role', 'Student');

        // Apply search for student name if search term is provided
        $search = $criteria->getSearchText();
        if (!empty($search)) {
            $query->where('(gibbonPerson.preferredName LIKE :search OR gibbonPerson.surname LIKE :search OR CONCAT(gibbonPerson.preferredName, " ", gibbonPerson.surname) LIKE :search)')
                ->bindValue('search', '%' . $search . '%');
        }

        $res = $this->runQuery($query, $criteria);

        // Enhance each row with calculated fields using centralized logic
        foreach ($res as &$row) {
            $effectiveDates = $this->calculateEffectiveDates(
                $row['dateEnrolled'],
                $row['dateUnenrolled'],
                $row['personDateEnd'],
                $schoolYear['firstDay'],
                $schoolYear['lastDay']
            );

            $monthsEnrolled = $this->calculateEnrollmentMonths(
                $effectiveDates['startDate'],
                $effectiveDates['endDate']
            );

            $row['startDate'] = $effectiveDates['startDate'];
            $row['lastDate'] = $effectiveDates['endDate'];
            $row['monthsEnrolled'] = $monthsEnrolled;
            $row['total'] = $row['courseFee'] * $monthsEnrolled;
        }

        return $res;
    }

    /**
     * Get family totals (debt, payments, balance) for a school year
     * Refactored to use centralized calculation methods
     */
    public function getFamilyTotals($schoolYearID, $criteria, $search = '')
    {
        // First, get all families with enrollments for this school year (simplified query)
        $query = $this
            ->newQuery()
            ->cols([
                'gibbonFamily.gibbonFamilyID',
                'gibbonFamily.name as familyName',
                'gibbonSEPA.payer as payer',
                'gibbonSEPA.gibbonSEPAID as gibbonSEPAID'
            ])
            ->from('gibbonFamily')
            ->innerJoin('gibbonFamilyChild', 'gibbonFamilyChild.gibbonFamilyID = gibbonFamily.gibbonFamilyID')
            ->innerJoin('gibbonPerson', 'gibbonFamilyChild.gibbonPersonID = gibbonPerson.gibbonPersonID')
            ->innerJoin('gibbonCourseClassPerson', 'gibbonCourseClassPerson.gibbonPersonID = gibbonPerson.gibbonPersonID')
            ->innerJoin('gibbonCourseClass', 'gibbonCourseClass.gibbonCourseClassID = gibbonCourseClassPerson.gibbonCourseClassID')
            ->innerJoin('gibbonCourse', 'gibbonCourse.gibbonCourseID = gibbonCourseClass.gibbonCourseID')
            ->leftJoin('gibbonSEPA', 'gibbonFamily.gibbonFamilyID = gibbonSEPA.gibbonFamilyID')
            ->where('gibbonCourse.gibbonSchoolYearID = :schoolYearID')
            ->where('gibbonCourseClassPerson.role = :role')
            ->bindValue('schoolYearID', $schoolYearID)
            ->bindValue('role', 'Student')
            ->groupBy(['gibbonFamily.gibbonFamilyID', 'gibbonFamily.name', 'gibbonSEPA.payer', 'gibbonSEPA.gibbonSEPAID']);

        // Apply search for family name and payer if search term is provided
        if (!empty($search)) {
            $query->where('(gibbonFamily.name LIKE :search OR gibbonSEPA.payer LIKE :search)')
                ->bindValue('search', '%' . $search . '%');
        }

        $result = $this->runQuery($query, $criteria);

        // Enhance each row with calculated totals using centralized logic
        foreach ($result as &$row) {
            $gibbonFamilyID = $row['gibbonFamilyID'];
            $gibbonSEPAID = $row['gibbonSEPAID'];

            // Calculate total debt using centralized method
            $totalDept = $this->calculateFamilyTotalFees($gibbonFamilyID, $schoolYearID);

            // Get payments
            $payments = 0;
            if ($gibbonSEPAID) {
                $paymentsQuery = $this
                    ->newSelect()
                    ->cols(['COALESCE(SUM(amount), 0) as total'])
                    ->from('gibbonSEPAPaymentEntry')
                    ->where('gibbonSEPAID = :gibbonSEPAID')
                    ->where('academicYear = :schoolYearID')
                    ->bindValue('gibbonSEPAID', $gibbonSEPAID)
                    ->bindValue('schoolYearID', $schoolYearID);
                $paymentsResult = $this->runSelect($paymentsQuery)->fetch();
                $payments = $paymentsResult['total'] ?? 0;
            }

            // Get payment adjustments
            $paymentsAdjustment = 0;
            if ($gibbonSEPAID) {
                $adjustmentsQuery = $this
                    ->newSelect()
                    ->cols(['COALESCE(SUM(amount), 0) as total'])
                    ->from('gibbonSEPAPaymentAdjustment')
                    ->where('gibbonSEPAID = :gibbonSEPAID')
                    ->where('academicYear = :schoolYearID')
                    ->bindValue('gibbonSEPAID', $gibbonSEPAID)
                    ->bindValue('schoolYearID', $schoolYearID);
                $adjustmentsResult = $this->runSelect($adjustmentsQuery)->fetch();
                $paymentsAdjustment = $adjustmentsResult['total'] ?? 0;
            }

            // Calculate balance
            $balance = $payments + $paymentsAdjustment - $totalDept;

            $row['totalDept'] = $totalDept;
            $row['payments'] = $payments;
            $row['paymentsAdjustment'] = $paymentsAdjustment;
            $row['balance'] = $balance;
        }

        return $result;
    }

    public function getFamilyInfo($gibbonFamilyID)
    {
        $query = $this
            ->newSelect()
            ->cols(['name'])
            ->from('gibbonFamily')
            ->where('gibbonFamilyID = :gibbonFamilyID')
            ->bindValue('gibbonFamilyID', $gibbonFamilyID);

        return $this->runSelect($query)->fetchAll();
    }

    /**
     * Build base query for payment data
     * Used by getFamilyTotalPayments
     */
    private function getPaymentBaseQuery($schoolYearID = null, $gibbonFamilyID = null, $gibbonSEPAID = null)
    {
        $query = $this
            ->newQuery()
            ->from('gibbonSEPAPaymentEntry')
            ->innerJoin('gibbonSEPA', 'gibbonSEPAPaymentEntry.gibbonSEPAID = gibbonSEPA.gibbonSEPAID');

        if ($schoolYearID) {
            $query->where('gibbonSEPAPaymentEntry.academicYear = :academicYear')
                ->bindValue('academicYear', $schoolYearID);
        }

        if ($gibbonFamilyID) {
            $query->where('gibbonSEPA.gibbonFamilyID = :gibbonFamilyID')
                ->bindValue('gibbonFamilyID', $gibbonFamilyID);
        }

        if ($gibbonSEPAID) {
            $query->where('gibbonSEPA.gibbonSEPAID = :gibbonSEPAID')
                ->bindValue('gibbonSEPAID', $gibbonSEPAID);
        }

        return $query;
    }
    /**
     * Get enrollment fees for all children in a family
     * Now uses centralized calculation methods
     */
    public function getChildrenEnrollmentFees($gibbonFamilyID, $schoolYearID)
    {
        return $this->getRawEnrollmentData($gibbonFamilyID, $schoolYearID);
    }

    /**
     * Get enrollment fees for a family (alias for getChildrenEnrollmentFees)
     */
    public function getFamilyEnrollmentFees($gibbonFamilyID, $schoolYearID)
    {
        if (empty($gibbonFamilyID) || is_array($gibbonFamilyID)) {
            return [];
        }
        return $this->getChildrenEnrollmentFees($gibbonFamilyID, $schoolYearID);
    }

    /**
     * Get fees summary for families
     * Now uses centralized calculation methods
     */
    public function getFamiliesFeesSummary($gibbonFamilyID, $schoolYearID)
    {
        if (empty($gibbonFamilyID)) {
            return [];
        }

        // Handle both single ID and array of IDs
        $familyIDs = is_array($gibbonFamilyID) ? $gibbonFamilyID : [$gibbonFamilyID];
        $results = [];

        foreach ($familyIDs as $famID) {
            $totalFees = $this->calculateFamilyTotalFees($famID, $schoolYearID);
            $results[] = [
                'gibbonFamilyID' => $famID,
                'totalFees' => $totalFees
            ];
        }

        return $results;
    }

    /**
     * Get fees summary for a single family
     */
    public function getFamilyFeesSummary($gibbonFamilyID, $schoolYearID)
    {
        if (empty($gibbonFamilyID)) {
            return null;
        }
        return $this->getFamiliesFeesSummary($gibbonFamilyID, $schoolYearID);
    }


    public function getFamilyTotalPayments($gibbonFamilyID, $schoolYearID)
    {
        $query = $this->getPaymentBaseQuery($schoolYearID, $gibbonFamilyID)
            ->cols(['SUM(COALESCE(gibbonSEPAPaymentEntry.amount, 0)) as totalPayments']);

        $result = $this->runSelect($query)->fetch();
        return $result['totalPayments'] ?? 0;
    }

    public function getPaymentsBySEPAID($SEPAID)
    {
        $query = $this
            ->newSelect()
            ->cols(['*'])
            ->from('gibbonSEPAPaymentEntry')
            ->where('gibbonSEPAID = :gibbonSEPAID')
            ->bindValue('gibbonSEPAID', $SEPAID);

        $result = $this->runSelect($query)->fetchAll();
        return $result;
    }

    public function updateSEPAByFamilyID($gibbonFamilyID, $sepaData)
    {
        $query = $this
            ->newUpdate()
            ->table('gibbonSEPA')
            ->cols([
                'payer' => trim($sepaData['payer']),
                'IBAN' => $this->maskIBAN($sepaData['IBAN'] ?? null),
                'BIC' => $this->maskBIC($sepaData['BIC'] ?? null),
                'SEPA_signedDate' => $sepaData['SEPA_signedDate'],
                'note' => trim($sepaData['note'])
            ])
            ->where('gibbonFamilyID = :gibbonFamilyID')
            ->bindValue('gibbonFamilyID', $gibbonFamilyID);

        return $this->runUpdate($query);
    }

public function updateSEPABySEPAID($SEPAID, $sepaData)
    {
        $query = $this
            ->newUpdate()
            ->table('gibbonSEPA')
            ->cols([
                'payer' => trim($sepaData['payer']),
                'IBAN' => $this->maskIBAN($sepaData['IBAN'] ?? null),
                'BIC' => $this->maskBIC($sepaData['BIC'] ?? null),
                'SEPA_signedDate' => $sepaData['SEPA_signedDate'],
                'note' => trim($sepaData['note'])
            ])
            ->where('gibbonSEPAID = :SEPAID')
            ->bindValue('SEPAID', $SEPAID);

        return $this->runUpdate($query);
    }

    public function updateSEPAByUserID($userID, $sepaData)
    {
        $familyIDs = $this->getfamilyPerPerson($userID);
        $result = [];

        foreach ($familyIDs as $familyID) {
            if ($this->getFamilySEPA($familyID)) {
                $result[] = $this->updateSEPAByFamilyID($familyID, $sepaData);
            }
        }

        return $result;
    }

    /**
     * Get school year information by ID
     */
    public function getSchoolYearByID($schoolYearID)
    {
        $query = $this
            ->newSelect()
            ->cols(['gibbonSchoolYearID', 'name', 'firstDay', 'lastDay'])
            ->from('gibbonSchoolYear')
            ->where('gibbonSchoolYearID = :schoolYearID')
            ->bindValue('schoolYearID', $schoolYearID);

        return $this->runSelect($query)->fetch();
    }

    /**
     * Get enrollment details by person ID and course class ID
     */
    public function getEnrollmentByIDs($gibbonPersonID, $gibbonCourseClassID)
    {
        $query = $this
            ->newSelect()
            ->cols([
                'gibbonCourseClassPerson.*',
                'gibbonPerson.preferredName',
                'gibbonPerson.surname',
                'gibbonCourse.name as courseName',
                'gibbonCourseClass.name as className'
            ])
            ->from('gibbonCourseClassPerson')
            ->innerJoin('gibbonPerson', 'gibbonCourseClassPerson.gibbonPersonID = gibbonPerson.gibbonPersonID')
            ->innerJoin('gibbonCourseClass', 'gibbonCourseClassPerson.gibbonCourseClassID = gibbonCourseClass.gibbonCourseClassID')
            ->innerJoin('gibbonCourse', 'gibbonCourseClass.gibbonCourseID = gibbonCourse.gibbonCourseID')
            ->where('gibbonCourseClassPerson.gibbonPersonID = :gibbonPersonID')
            ->where('gibbonCourseClassPerson.gibbonCourseClassID = :gibbonCourseClassID')
            ->where('gibbonCourseClassPerson.role = :role')
            ->bindValue('gibbonPersonID', $gibbonPersonID)
            ->bindValue('gibbonCourseClassID', $gibbonCourseClassID)
            ->bindValue('role', 'Student');

        return $this->runSelect($query)->fetch();
    }

    /**
     * Update enrollment dates
     */
    public function updateEnrollmentDates($gibbonPersonID, $gibbonCourseClassID, $dateEnrolled, $dateUnenrolled)
    {
        $query = $this
            ->newUpdate()
            ->table('gibbonCourseClassPerson')
            ->cols([
                'dateEnrolled' => $dateEnrolled,
                'dateUnenrolled' => $dateUnenrolled
            ])
            ->where('gibbonPersonID = :gibbonPersonID')
            ->where('gibbonCourseClassID = :gibbonCourseClassID')
            ->where('role = :role')
            ->bindValue('gibbonPersonID', $gibbonPersonID)
            ->bindValue('gibbonCourseClassID', $gibbonCourseClassID)
            ->bindValue('role', 'Student');

        return $this->runUpdate($query);
    }

    // ========================================================================
    // CENTRALIZED FEE CALCULATION METHODS (Single Point of Truth)
    // ========================================================================

    /**
     * Calculate the number of billable months based on enrollment period
     *
     * NEW LOGIC: If the enrollment period contains at least one weekend day
     * of a month, then the full month will be paid.
     *
     * @param string $startDate Start date of enrollment (YYYY-MM-DD)
     * @param string $endDate End date of enrollment (YYYY-MM-DD)
     * @return int Number of months to bill
     */
    private function calculateEnrollmentMonths($startDate, $endDate)
    {
        if (empty($startDate) || empty($endDate)) {
            return 0;
        }

        $start = new \DateTime($startDate);
        $end = new \DateTime($endDate);

        // If end date is before start date, return 0
        if ($end < $start) {
            return 0;
        }

        $billableMonths = 0;
        $currentMonth = clone $start;
        $currentMonth->modify('first day of this month');

        // Iterate through each month in the period
        while ($currentMonth <= $end) {
            $monthStart = clone $currentMonth;
            $monthEnd = clone $currentMonth;
            $monthEnd->modify('last day of this month');

            // Check if this month has any overlap with the enrollment period
            $periodStart = max($start, $monthStart);
            $periodEnd = min($end, $monthEnd);

            if ($periodStart <= $periodEnd) {
                // Check if any weekend day falls within this month's enrollment period
                if ($this->hasWeekendInPeriod($periodStart, $periodEnd)) {
                    $billableMonths++;
                }
            }

            // Move to next month
            $currentMonth->modify('first day of next month');
        }

        return $billableMonths;
    }

    /**
     * Check if a period contains at least one weekend day (Saturday or Sunday)
     *
     * @param \DateTime $start Start date
     * @param \DateTime $end End date
     * @return bool True if period contains at least one weekend day
     */
    private function hasWeekendInPeriod($start, $end)
    {
        $current = clone $start;

        while ($current <= $end) {
            $dayOfWeek = (int)$current->format('N'); // 1 (Monday) to 7 (Sunday)
            if ($dayOfWeek >= 6) { // 6 = Saturday, 7 = Sunday
                return true;
            }
            $current->modify('+1 day');
        }

        return false;
    }

    /**
     * Calculate effective enrollment dates based on school year and person dates
     *
     * @param string $dateEnrolled Raw enrollment date
     * @param string|null $dateUnenrolled Raw unenrollment date (null if still enrolled)
     * @param string|null $personDateEnd Person's end date (e.g., student leaving)
     * @param string $schoolYearFirstDay School year's first day
     * @param string $schoolYearLastDay School year's last day
     * @return array ['startDate' => string, 'endDate' => string]
     */
    private function calculateEffectiveDates($dateEnrolled, $dateUnenrolled, $personDateEnd, $schoolYearFirstDay, $schoolYearLastDay)
    {
        // Start date: later of enrollment date or school year start
        $startDate = max($dateEnrolled, $schoolYearFirstDay);

        // End date: earliest of unenrollment date, person end date, or school year end
        $possibleEndDates = array_filter([
            $dateUnenrolled,
            $personDateEnd,
            $schoolYearLastDay
        ]);
        $endDate = min($possibleEndDates);

        return [
            'startDate' => $startDate,
            'endDate' => $endDate
        ];
    }

    /**
     * Get raw enrollment data for a family (without complex calculations)
     * Uses simple queries and joinColumn pattern per getSEPAData() example
     *
     * @param int $gibbonFamilyID Family ID
     * @param int $schoolYearID School year ID
     * @return array Array of enrollment records with basic data
     */
    private function getRawEnrollmentData($gibbonFamilyID, $schoolYearID)
    {
        // Get school year information
        $schoolYear = $this->getSchoolYearByID($schoolYearID);
        if (!$schoolYear) {
            return [];
        }

        // Get students in the family
        $studentsQuery = $this
            ->newSelect()
            ->cols([
                'gibbonPerson.gibbonPersonID',
                'gibbonPerson.preferredName',
                'gibbonPerson.surname',
                'gibbonPerson.dateEnd',
                'gibbonFamilyChild.gibbonFamilyID'
            ])
            ->from('gibbonFamilyChild')
            ->innerJoin('gibbonPerson', 'gibbonFamilyChild.gibbonPersonID = gibbonPerson.gibbonPersonID')
            ->where('gibbonFamilyChild.gibbonFamilyID = :gibbonFamilyID')
            ->bindValue('gibbonFamilyID', $gibbonFamilyID);

        $students = $this->runSelect($studentsQuery)->fetchAll();

        if (empty($students)) {
            return [];
        }

        $personIDs = array_column($students, 'gibbonPersonID');
        $personIDList = implode(',', $personIDs);

        // Get enrollments for these students
        $enrollmentsQuery = $this
            ->newSelect()
            ->cols([
                'gibbonCourseClassPerson.gibbonPersonID',
                'gibbonCourseClassPerson.gibbonCourseClassID',
                'gibbonCourseClassPerson.dateEnrolled',
                'gibbonCourseClassPerson.dateUnenrolled',
                'gibbonCourse.gibbonCourseID',
                'gibbonCourse.name as courseName',
                'gibbonCourse.nameShort as courseShortName'
            ])
            ->from('gibbonCourseClassPerson')
            ->innerJoin('gibbonCourseClass', 'gibbonCourseClassPerson.gibbonCourseClassID = gibbonCourseClass.gibbonCourseClassID')
            ->innerJoin('gibbonCourse', 'gibbonCourseClass.gibbonCourseID = gibbonCourse.gibbonCourseID')
            ->where('FIND_IN_SET(gibbonCourseClassPerson.gibbonPersonID, :personIDList)')
            ->where('gibbonCourse.gibbonSchoolYearID = :schoolYearID')
            ->where('gibbonCourseClassPerson.role = :role')
            ->bindValue('personIDList', $personIDList)
            ->bindValue('schoolYearID', $schoolYearID)
            ->bindValue('role', 'Student');

        $enrollments = $this->runSelect($enrollmentsQuery)->fetchAll();

        if (empty($enrollments)) {
            return [];
        }

        // Get course fees
        $courseIDs = array_unique(array_column($enrollments, 'gibbonCourseID'));
        $courseIDList = implode(',', $courseIDs);

        $feesQuery = $this
            ->newSelect()
            ->cols([
                'gibbonSepaCoursesFees.gibbonCourseID',
                'gibbonSepaCoursesFees.fees'
            ])
            ->from('gibbonSepaCoursesFees')
            ->where('FIND_IN_SET(gibbonSepaCoursesFees.gibbonCourseID, :courseIDList)')
            ->bindValue('courseIDList', $courseIDList);

        $fees = $this->runSelect($feesQuery)->fetchKeyPair();

        // Combine all data in PHP
        $results = [];
        foreach ($enrollments as $enrollment) {
            $personID = $enrollment['gibbonPersonID'];

            // Find the student
            $student = null;
            foreach ($students as $s) {
                if ($s['gibbonPersonID'] == $personID) {
                    $student = $s;
                    break;
                }
            }

            if (!$student) {
                continue;
            }

            // Calculate effective dates
            $effectiveDates = $this->calculateEffectiveDates(
                $enrollment['dateEnrolled'],
                $enrollment['dateUnenrolled'],
                $student['dateEnd'],
                $schoolYear['firstDay'],
                $schoolYear['lastDay']
            );

            // Calculate months using new weekend logic
            $monthsEnrolled = $this->calculateEnrollmentMonths(
                $effectiveDates['startDate'],
                $effectiveDates['endDate']
            );

            // Get course fee
            $courseFee = $fees[$enrollment['gibbonCourseID']] ?? 0;

            $results[] = [
                'gibbonFamilyID' => $student['gibbonFamilyID'],
                'gibbonPersonID' => $personID,
                'childID' => $personID,
                'student_name' => $student['preferredName'] . ' ' . $student['surname'],
                'childName' => $student['preferredName'],
                'gibbonCourseID' => $enrollment['gibbonCourseID'],
                'gibbonCourseClassID' => $enrollment['gibbonCourseClassID'],
                'courseName' => $enrollment['courseName'],
                'shortName' => $enrollment['courseShortName'],
                'rawDateEnrolled' => $enrollment['dateEnrolled'],
                'rawDateUnenrolled' => $enrollment['dateUnenrolled'],
                'personDateEnd' => $student['dateEnd'],
                'dateEnrolled' => $enrollment['dateEnrolled'],
                'dateUnenrolled' => $enrollment['dateUnenrolled'],
                'startDate' => $effectiveDates['startDate'],
                'lastDate' => $effectiveDates['endDate'],
                'courseFee' => $courseFee,
                'monthsEnrolled' => $monthsEnrolled,
                'totalCost' => $courseFee * $monthsEnrolled,
                'total' => $courseFee * $monthsEnrolled,
                'familyName' => null // Will be filled by caller if needed
            ];
        }

        return $results;
    }

    /**
     * Calculate total fees for a family
     * Single point of truth for fee calculations
     *
     * @param int $gibbonFamilyID Family ID
     * @param int $schoolYearID School year ID
     * @return float Total fees for the family
     */
    private function calculateFamilyTotalFees($gibbonFamilyID, $schoolYearID)
    {
        $enrollments = $this->getRawEnrollmentData($gibbonFamilyID, $schoolYearID);
        $total = 0;

        foreach ($enrollments as $enrollment) {
            $total += $enrollment['totalCost'];
        }

        return $total;
    }

}
