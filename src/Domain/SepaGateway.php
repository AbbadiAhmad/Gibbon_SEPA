<?php
namespace Gibbon\Module\Sepa\Domain; //Replace ModuleName with your module's name, ommiting spaces

use Gibbon\Domain\Traits\TableAware;
use Gibbon\Domain\QueryCriteria;
use Gibbon\Domain\QueryableGateway;
use Gibbon\Module\Sepa\Security\IbanCipher;

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
    private static $searchableColumns = ['payer', 'customData']; // Optional: Array of Columns to be searched when using the search filter

    const WITHDRAW_CADENCES = ['Monthly', 'HalfYearly', 'Yearly'];
    const DEFAULT_WITHDRAW_CADENCE = 'Monthly';
    const DEFAULT_WITHDRAW_DAY = 5;

    /**
     * Encrypt an IBAN for storage (AES-256-GCM, see IbanCipher). Returns NULL for an empty IBAN.
     *
     * @throws \RuntimeException when no encryption key is configured
     */
    public function encryptIBAN($iban)
    {
        return IbanCipher::encrypt($iban);
    }

    /**
     * Clear IBAN of a stored value (legacy unencrypted values are returned unchanged).
     */
    public function decryptIBAN($storedIban)
    {
        return IbanCipher::decrypt($storedIban);
    }

    /**
     * Cadence names for forms / display.
     */
    public static function getWithdrawCadenceOptions()
    {
        return [
            'Monthly' => __('Monthly'),
            'HalfYearly' => __('Half-yearly'),
            'Yearly' => __('Yearly'),
        ];
    }

    /**
     * Preferred withdrawal days (1-31).
     */
    public static function getWithdrawDayOptions()
    {
        return array_combine(range(1, 31), range(1, 31));
    }

    /**
     * Map free text (English / German, e.g. "monthly", "Halbjährlich", "jährlich", "6") to a cadence.
     * Returns NULL when the text is not recognised.
     */
    public static function normalizeCadence($text)
    {
        $text = mb_strtolower(trim((string) $text));
        if ($text === '') {
            return null;
        }
        if (in_array($text, self::WITHDRAW_CADENCES, true) || in_array(ucfirst($text), self::WITHDRAW_CADENCES, true)) {
            return ucfirst($text);
        }
        // half-yearly has to be tested before yearly ("halbjährlich" contains "jährlich")
        if (preg_match('/halb|half|semi|6\s*(monat|month)|^6$|2\s*x|2\s*(mal|times)/u', $text)) {
            return 'HalfYearly';
        }
        if (preg_match('/monat|month|^1$/u', $text)) {
            return 'Monthly';
        }
        if (preg_match('/j[aä]hr|year|annual|12\s*(monat|month)|^12$/u', $text)) {
            return 'Yearly';
        }
        return null;
    }

    /**
     * First number 1-31 found in the text ("15", "15.", "am 15."), or NULL.
     */
    public static function normalizeDay($text)
    {
        if (preg_match('/\d{1,2}/', (string) $text, $m) && $m[0] >= 1 && $m[0] <= 31) {
            return (int) $m[0];
        }
        return null;
    }

    /**
     * Writable gibbonSEPA columns for the given form / import data.
     * The IBAN is encrypted here, so every insert / update goes through the same path.
     */
    private function getSEPAColumns($sepaData)
    {
        $columns = [
            'payer' => trim($sepaData['payer']),
            'IBAN' => $this->encryptIBAN($sepaData['IBAN'] ?? null),
            'BIC' => !empty($sepaData['BIC']) ? strtoupper(preg_replace('/\s+/', '', $sepaData['BIC'])) : null,
            'SEPA_signedDate' => !empty($sepaData['SEPA_signedDate']) ? $sepaData['SEPA_signedDate'] : null,
            'note' => trim($sepaData['note'] ?? ''),
        ];

        // Cadence and day are only written when a valid value is given, so an import (or update)
        // that does not carry them leaves the stored values untouched.
        if (in_array($sepaData['withdrawCadence'] ?? null, self::WITHDRAW_CADENCES, true)) {
            $columns['withdrawCadence'] = $sepaData['withdrawCadence'];
        }
        $day = $sepaData['withdrawDay'] ?? '';
        if ($day !== '' && (int) $day >= 1 && (int) $day <= 31) {
            $columns['withdrawDay'] = (int) $day;
        }

        return $columns;
    }

    /**
     * Insert a SEPA record for a family (all fields, IBAN encrypted).
     */
    public function insertSEPA($gibbonFamilyID, $sepaData, $customData = null)
    {
        $query = $this
            ->newInsert()
            ->into('gibbonSEPA')
            ->cols(array_merge(
                ['gibbonFamilyID' => $gibbonFamilyID, 'withdrawCadence' => self::DEFAULT_WITHDRAW_CADENCE, 'withdrawDay' => self::DEFAULT_WITHDRAW_DAY],
                $this->getSEPAColumns($sepaData),
                ['customData' => $customData]
            ));

        return $this->runInsert($query);
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
                ->cols(array_merge(['gibbonFamilyID' => trim($familyID)], $this->getSEPAColumns($sepaData)));

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
                'IBAN' => $this->encryptIBAN($paymentData['IBAN'] ?? null),
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
                'IBAN' => $this->encryptIBAN($paymentData['IBAN'] ?? null),
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
            $query->where('(gibbonSEPAPaymentEntry.transaction_reference LIKE :search OR gibbonSEPAPaymentEntry.payer LIKE :search OR gibbonSEPAPaymentEntry.booking_date LIKE :search OR gibbonSEPAPaymentEntry.amount LIKE :search)')
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

    public function getChildEnrollmentDetails($schoolYearID, $criteria)
    {
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
                $this->getEnrollmentFeesSQLstatments('startDate') . ' AS startDate',
                $this->getEnrollmentFeesSQLstatments('lastDate') . ' as lastDate',
                $this->getEnrollmentFeesSQLstatments('enrollmentMonths') . ' as monthsEnrolled',
                'COALESCE(gibbonSepaCoursesFees.fees, 0) as courseFee',
                $this->getEnrollmentFeesSQLstatments('enrollmentFees') . ' as total'
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
        return $res;

    }

    /**
     * Family totals. When $periodStart / $periodEnd (any day inside the first / last month) are given,
     * the result also holds `dueInPeriod`: the fees of the months of that period, plus the withdrawal
     * cadence / day of the family's SEPA.
     */
    public function getFamilyTotals($schoolYearID, $criteria, $search = '', $periodStart = null, $periodEnd = null)
    {
        $query = $this
            ->newQuery()
            ->cols([
                'gibbonFamily.gibbonFamilyID',
                'gibbonFamily.name as familyName',
                'gibbonSEPA.payer as payer',
                'gibbonSEPA.gibbonSEPAID as gibbonSEPAID',
                $this->getEnrollmentFeesSQLstatments('totalFees') . ' as totalDept',
                '(SELECT COALESCE(SUM(amount), 0) FROM gibbonSEPAPaymentEntry WHERE gibbonSEPAPaymentEntry.gibbonSEPAID = gibbonSEPA.gibbonSEPAID AND gibbonSEPAPaymentEntry.academicYear = :schoolYearID) as payments',
                '(SELECT COALESCE(SUM(amount), 0) FROM gibbonSEPAPaymentAdjustment WHERE gibbonSEPAPaymentAdjustment.gibbonSEPAID = gibbonSEPA.gibbonSEPAID AND gibbonSEPAPaymentAdjustment.academicYear = :schoolYearID) as paymentsAdjustment',
                '((SELECT COALESCE(SUM(amount), 0) FROM gibbonSEPAPaymentEntry WHERE gibbonSEPAPaymentEntry.gibbonSEPAID = gibbonSEPA.gibbonSEPAID AND gibbonSEPAPaymentEntry.academicYear = :schoolYearID) + (SELECT COALESCE(SUM(amount), 0) FROM gibbonSEPAPaymentAdjustment WHERE gibbonSEPAPaymentAdjustment.gibbonSEPAID = gibbonSEPA.gibbonSEPAID AND gibbonSEPAPaymentAdjustment.academicYear = :schoolYearID) - ' . $this->getEnrollmentFeesSQLstatments('totalFees') . ') as balance'
            ])
            ->from('gibbonFamily')
            ->innerJoin('gibbonFamilyChild', 'gibbonFamilyChild.gibbonFamilyID = gibbonFamily.gibbonFamilyID')
            ->innerJoin('gibbonPerson', 'gibbonFamilyChild.gibbonPersonID = gibbonPerson.gibbonPersonID')
            ->innerJoin('gibbonCourseClassPerson', 'gibbonCourseClassPerson.gibbonPersonID = gibbonPerson.gibbonPersonID')
            ->innerJoin('gibbonCourseClass', 'gibbonCourseClass.gibbonCourseClassID = gibbonCourseClassPerson.gibbonCourseClassID')
            ->innerJoin('gibbonCourse', 'gibbonCourse.gibbonCourseID = gibbonCourseClass.gibbonCourseID')
            ->innerJoin('gibbonSchoolYear', 'gibbonSchoolYear.gibbonSchoolYearID = gibbonCourse.gibbonSchoolYearID')
            ->leftJoin('gibbonSepaCoursesFees', 'gibbonSepaCoursesFees.gibbonCourseID = gibbonCourse.gibbonCourseID')
            ->leftJoin('gibbonSEPA', 'gibbonFamily.gibbonFamilyID = gibbonSEPA.gibbonFamilyID')
            ->where('gibbonSchoolYear.gibbonSchoolYearID = :schoolYearID')
            ->where('gibbonCourseClassPerson.role = :role')
            ->bindValue('schoolYearID', $schoolYearID)
            ->bindValue('role', 'Student')
            ->groupBy(['gibbonFamily.gibbonFamilyID', 'gibbonFamily.name', 'gibbonSEPA.payer', 'gibbonSEPA.gibbonSEPAID', 'gibbonSEPA.withdrawCadence', 'gibbonSEPA.withdrawDay']);

        if ($periodStart !== null && $periodEnd !== null) {
            $query->cols([
                $this->getEnrollmentFeesSQLstatments('periodFees') . ' as dueInPeriod',
                'gibbonSEPA.withdrawCadence',
                'gibbonSEPA.withdrawDay',
            ])
                ->bindValue('periodStart', date('Y-m-01', strtotime($periodStart)))
                ->bindValue('periodEnd', date('Y-m-01', strtotime($periodEnd)));
        }

        // Apply search for family name and payer if search term is provided
        if (!empty($search)) {
            $query->where('(gibbonFamily.name LIKE :search OR gibbonSEPA.payer LIKE :search)')
                ->bindValue('search', '%' . $search . '%');
        }

        return $this->runQuery($query, $criteria);
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
     * Single source of truth for the SEPA billing-period SQL fragments.
     *
     * Billing rules (per stakeholder agreement):
     *  - Billing never starts before October 1st of the school year: enrollment
     *    that happens in September (the intake/orientation month) or earlier is
     *    not billed at all, regardless of the day it happened on.
     *  - From October onward, a month is billed in full only if the student
     *    enrolled with at least 7 days left in that month; otherwise billing
     *    starts the following month.
     *  - The unenrollment month is always billed in full, whatever day within
     *    it the student left, cancellation or the person's dateEnd occurred on.
     */
    private function getEnrollmentFeesSQLstatments($statement)
    {
        // Enrollment date clamped to not precede the school year's start.
        $enrolled = 'GREATEST(gibbonCourseClassPerson.dateEnrolled, gibbonSchoolYear.firstDay)';
        // October 1st of the school year's starting calendar year - billing never starts earlier.
        $octoberFirst = 'DATE(CONCAT(YEAR(gibbonSchoolYear.firstDay), \'-10-01\'))';
        // Last billable day, clamped to the school year end, unenrollment and the person's leave date.
        $effectiveEnd = 'LEAST(COALESCE(gibbonCourseClassPerson.dateUnenrolled, gibbonSchoolYear.lastDay), gibbonSchoolYear.lastDay, COALESCE(gibbonPerson.dateEnd, gibbonSchoolYear.lastDay))';

        switch ($statement) {
            case 'startDate':
                return 'CASE '
                    . 'WHEN ' . $enrolled . ' < ' . $octoberFirst . ' THEN ' . $octoberFirst . ' '
                    . 'WHEN DAY(' . $enrolled . ') <= (DAY(LAST_DAY(' . $enrolled . ')) - 7) THEN DATE_FORMAT(' . $enrolled . ', \'%Y-%m-01\') '
                    . 'ELSE DATE_FORMAT(DATE_ADD(' . $enrolled . ', INTERVAL 1 MONTH), \'%Y-%m-01\') '
                    . 'END';
            case 'lastDate':
                return 'LAST_DAY(' . $effectiveEnd . ')';
            case 'enrollmentMonths':
                // TIMESTAMPDIFF is used (rather than YEAR()/MONTH() subtraction) because MySQL flags
                // YEAR()/MONTH() results as UNSIGNED; subtracting them when the result is negative
                // underflows to a huge unsigned number and overflows BIGINT UNSIGNED once multiplied.
                $start = $this->getEnrollmentFeesSQLstatments('startDate');
                $endMonthStart = 'DATE_FORMAT(' . $effectiveEnd . ', \'%Y-%m-01\')';
                return 'GREATEST(0, TIMESTAMPDIFF(MONTH, ' . $start . ', ' . $endMonthStart . ') + 1)';
            case 'enrollmentFees':
                return 'COALESCE(gibbonSepaCoursesFees.fees, 0) * ' . $this->getEnrollmentFeesSQLstatments('enrollmentMonths');
            case 'totalFees':
                return 'SUM(' . $this->getEnrollmentFeesSQLstatments('enrollmentFees') . ')';
            case 'periodMonths':
                // Billable months that fall inside [:periodStart, :periodEnd] (both are first-of-month dates).
                $start = $this->getEnrollmentFeesSQLstatments('startDate');
                $endMonthStart = 'DATE_FORMAT(' . $effectiveEnd . ', \'%Y-%m-01\')';
                return 'GREATEST(0, TIMESTAMPDIFF(MONTH, GREATEST(' . $start . ', CAST(:periodStart AS DATE)), LEAST(' . $endMonthStart . ', CAST(:periodEnd AS DATE))) + 1)';
            case 'periodFees':
                return 'SUM(COALESCE(gibbonSepaCoursesFees.fees, 0) * ' . $this->getEnrollmentFeesSQLstatments('periodMonths') . ')';
        }
    }

    private function getEnrollmentFeesBaseQuery($schoolYearID, $gibbonFamilyID = null)
    {
        $query = $this
            ->newQuery()
            ->from('gibbonPerson')
            ->innerJoin('gibbonFamilyChild', 'gibbonFamilyChild.gibbonPersonID = gibbonPerson.gibbonPersonID')
            ->innerJoin('gibbonCourseClassPerson', 'gibbonCourseClassPerson.gibbonPersonID = gibbonPerson.gibbonPersonID')
            ->innerJoin('gibbonCourseClass', 'gibbonCourseClass.gibbonCourseClassID = gibbonCourseClassPerson.gibbonCourseClassID')
            ->innerJoin('gibbonCourse', 'gibbonCourse.gibbonCourseID = gibbonCourseClass.gibbonCourseID')
            ->innerJoin('gibbonSchoolYear', 'gibbonSchoolYear.gibbonSchoolYearID = gibbonCourse.gibbonSchoolYearID')
            ->leftJoin('gibbonSepaCoursesFees', 'gibbonSepaCoursesFees.gibbonCourseID = gibbonCourse.gibbonCourseID')
            ->where('gibbonCourse.gibbonSchoolYearID = :schoolYearID')
            ->where('gibbonCourseClassPerson.role = :role')
            ->bindValue('schoolYearID', $schoolYearID)
            ->bindValue('role', 'Student');

        if ($gibbonFamilyID) {
            $query->where('gibbonFamilyChild.gibbonFamilyID = :gibbonFamilyID')
                ->bindValue('gibbonFamilyID', $gibbonFamilyID);
        }

        return $query;
    }

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
    public function getChildrenEnrollmentFees($gibbonFamilyID, $schoolYearID)
    {
        $query = $this->getEnrollmentFeesBaseQuery($schoolYearID, $gibbonFamilyID)
            ->cols([
                'gibbonFamilyChild.gibbonFamilyID',
                'gibbonPerson.gibbonPersonID',
                'gibbonPerson.dateEnd as personDateEnd',
                'gibbonCourse.gibbonCourseID',
                'gibbonCourseClass.gibbonCourseClassID',
                'gibbonPerson.preferredName as childName',
                'gibbonCourse.name as courseName',
                'COALESCE(gibbonSepaCoursesFees.fees, 0) as courseFee',
                $this->getEnrollmentFeesSQLstatments('startDate') . ' AS startDate',
                $this->getEnrollmentFeesSQLstatments('lastDate') . ' as lastDate',
                'gibbonCourseClassPerson.dateEnrolled as rawDateEnrolled',
                'gibbonCourseClassPerson.dateUnenrolled as rawDateUnenrolled',
                $this->getEnrollmentFeesSQLstatments('enrollmentMonths') . ' as monthsEnrolled',
                $this->getEnrollmentFeesSQLstatments('enrollmentFees') . ' as totalCost',
            ]);

        $criteria = $this->newQueryCriteria(false);
        return $this->runQuery($query, $criteria);
    }

    public function getFamilyEnrollmentFees($gibbonFamilyID, $schoolYearID)
    {
        if (empty($gibbonFamilyID) || is_array($gibbonFamilyID)) {
            return [];
        }
        return $this->getChildrenEnrollmentFees($gibbonFamilyID, $schoolYearID);
    }

    public function getFamiliesFeesSummary($gibbonFamilyID, $schoolYearID)
    {
        $query = $this->getEnrollmentFeesBaseQuery($schoolYearID, $gibbonFamilyID)
            ->cols([
                'gibbonFamilyChild.gibbonFamilyID',
                $this->getEnrollmentFeesSQLstatments('totalFees') . ' as totalFees'
            ])
            ->groupBy(['gibbonFamilyChild.gibbonFamilyID']);

        return $this->runSelect($query)->fetchAll();
    }

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
            ->cols($this->getSEPAColumns($sepaData))
            ->where('gibbonFamilyID = :gibbonFamilyID')
            ->bindValue('gibbonFamilyID', $gibbonFamilyID);

        return $this->runUpdate($query);
    }

public function updateSEPABySEPAID($SEPAID, $sepaData)
    {
        $query = $this
            ->newUpdate()
            ->table('gibbonSEPA')
            ->cols($this->getSEPAColumns($sepaData))
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

}
