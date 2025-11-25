<?php
namespace Gibbon\Module\Sepa\Domain;

use Gibbon\Domain\Traits\TableAware;
use Gibbon\Domain\QueryCriteria;
use Gibbon\Domain\QueryableGateway;

/**
 * Issues Gateway
 *
 * Detects and reports various data quality and payment issues in SEPA records
 *
 * @version v1
 * @since   v1
 */
class IssuesGateway extends QueryableGateway
{
    use TableAware;

    private static $tableName = 'gibbonSEPA';
    private static $primaryKey = 'gibbonSEPAID';
    private static $searchableColumns = ['payer', 'IBAN'];

    /**
     * Get issue detection settings
     * Returns default values if setting doesn't exist
     *
     * @param string $settingName The setting to retrieve
     * @return string|null The setting value or default
     */
    public function getIssueSetting($settingName)
    {
        // Default settings
        $defaults = [
            'sepa_old_date_threshold_years' => '3',
            'similar_iban_detection_enabled' => '1',
            'similar_payer_detection_enabled' => '1',
            'balance_method_less_than' => 'number',
            'balance_method_attribute' => '2',
            'balance_method_more_than_attribute' => '10',
        ];

        $query = $this
            ->newSelect()
            ->cols(['settingValue'])
            ->from('gibbonSEPAIssueSettings')
            ->where('settingName = :settingName')
            ->bindValue('settingName', $settingName);

        $result = $this->runSelect($query)->fetch();

        return $result['settingValue'] ?? ($defaults[$settingName] ?? null);
    }

    /**
     * Update or insert an issue detection setting
     *
     * @param string $settingName
     * @param string $settingValue
     * @param string $description
     * @return bool Success status
     */
    public function setIssueSetting($settingName, $settingValue, $description = '')
    {
        // Check if setting exists
        $existing = $this->getIssueSetting($settingName);

        if ($existing !== null) {
            // Update existing
            $query = $this
                ->newUpdate()
                ->table('gibbonSEPAIssueSettings')
                ->cols([
                    'settingValue' => $settingValue,
                    'description' => $description
                ])
                ->where('settingName = :settingName')
                ->bindValue('settingName', $settingName);

            return $this->runUpdate($query);
        } else {
            // Insert new
            $query = $this
                ->newInsert()
                ->into('gibbonSEPAIssueSettings')
                ->cols([
                    'settingName' => $settingName,
                    'settingValue' => $settingValue,
                    'description' => $description
                ]);

            return $this->runInsert($query);
        }
    }

    /**
     * Issue Type 1: Detect similar/duplicate IBANs
     *
     * Finds SEPA records that share the same masked IBAN
     * Useful for identifying families using the same bank account
     *
     * @param QueryCriteria $criteria
     * @return object Result set with IBAN, count, and family details
     */
    public function getSimilarIBANs(QueryCriteria $criteria)
    {
        $query = $this
            ->newQuery()
            ->cols([
                'gibbonSEPA.IBAN',
                'COUNT(gibbonSEPA.gibbonSEPAID) as familyCount',
                'GROUP_CONCAT(DISTINCT gibbonSEPA.payer SEPARATOR ", ") as payers',
                'GROUP_CONCAT(DISTINCT gibbonFamily.name SEPARATOR ", ") as families',
                'GROUP_CONCAT(DISTINCT gibbonSEPA.gibbonSEPAID) as sepaIDs',
                'GROUP_CONCAT(DISTINCT gibbonFamily.gibbonFamilyID) as familyIDs'
            ])
            ->from('gibbonSEPA')
            ->innerJoin('gibbonFamily', 'gibbonSEPA.gibbonFamilyID = gibbonFamily.gibbonFamilyID')
            ->where('gibbonSEPA.IBAN IS NOT NULL')
            ->groupBy(['gibbonSEPA.IBAN'])
            ->having('COUNT(gibbonSEPA.gibbonSEPAID) > 1')
            ->orderBy(['familyCount DESC', 'gibbonSEPA.IBAN']);

        return $this->runQuery($query, $criteria);
    }

    /**
     * Issue Type 2: Detect similar payer names
     *
     * Finds SEPA records with very similar payer names that might be duplicates
     * Uses SOUNDEX for phonetic matching
     *
     * @param QueryCriteria $criteria
     * @return object Result set with similar payer groups
     */
    public function getSimilarPayers(QueryCriteria $criteria)
    {
        // Using SOUNDEX for phonetic similarity
        $query = $this
            ->newQuery()
            ->cols([
                'SOUNDEX(gibbonSEPA.payer) as payerSoundex',
                'GROUP_CONCAT(DISTINCT gibbonSEPA.payer SEPARATOR " | ") as payerVariations',
                'COUNT(DISTINCT gibbonSEPA.gibbonSEPAID) as recordCount',
                'GROUP_CONCAT(DISTINCT gibbonSEPA.IBAN SEPARATOR ", ") as ibans',
                'GROUP_CONCAT(DISTINCT gibbonFamily.name SEPARATOR ", ") as families',
                'GROUP_CONCAT(DISTINCT gibbonSEPA.gibbonSEPAID) as sepaIDs',
                'GROUP_CONCAT(DISTINCT gibbonFamily.gibbonFamilyID) as familyIDs'
            ])
            ->from('gibbonSEPA')
            ->innerJoin('gibbonFamily', 'gibbonSEPA.gibbonFamilyID = gibbonFamily.gibbonFamilyID')
            ->where('gibbonSEPA.payer IS NOT NULL')
            ->groupBy(['payerSoundex'])
            ->having('COUNT(DISTINCT gibbonSEPA.gibbonSEPAID) > 1')
            ->orderBy(['recordCount DESC']);

        return $this->runQuery($query, $criteria);
    }

    /**
     * Issue Type 3: Detect old SEPA authorization dates
     *
     * Finds SEPA records with signed dates older than threshold
     *
     * @param QueryCriteria $criteria
     * @param int $thresholdYears How many years is considered "old"
     * @return object Result set with old SEPA records
     */
    public function getOldSEPADates(QueryCriteria $criteria, $thresholdYears = 3)
    {
        $query = $this
            ->newQuery()
            ->cols([
                'gibbonSEPA.gibbonSEPAID',
                'gibbonSEPA.gibbonFamilyID',
                'gibbonFamily.name as familyName',
                'gibbonSEPA.payer',
                'gibbonSEPA.SEPA_signedDate',
                'TIMESTAMPDIFF(YEAR, gibbonSEPA.SEPA_signedDate, CURDATE()) as ageYears',
                'TIMESTAMPDIFF(MONTH, gibbonSEPA.SEPA_signedDate, CURDATE()) as ageMonths'
            ])
            ->from('gibbonSEPA')
            ->innerJoin('gibbonFamily', 'gibbonSEPA.gibbonFamilyID = gibbonFamily.gibbonFamilyID')
            ->where('gibbonSEPA.SEPA_signedDate IS NOT NULL')
            ->where('TIMESTAMPDIFF(YEAR, gibbonSEPA.SEPA_signedDate, CURDATE()) >= :thresholdYears')
            ->bindValue('thresholdYears', $thresholdYears)
            ->orderBy(['ageYears DESC', 'familyName']);

        return $this->runQuery($query, $criteria);
    }

    /**
     * Issue Type 4: Detect families without SEPA records
     *
     * Finds families that have enrolled students but no SEPA bank details
     *
     * @param QueryCriteria $criteria
     * @param int $schoolYearID Academic year to check for enrollments
     * @return object Result set with families missing SEPA
     */
    public function getFamiliesWithoutSEPA(QueryCriteria $criteria, $schoolYearID)
    {
        $query = $this
            ->newQuery()
            ->cols([
                'gibbonFamily.gibbonFamilyID',
                'gibbonFamily.name as familyName',
                'gibbonFamily.status',
                'COUNT(DISTINCT gibbonPerson.gibbonPersonID) as studentCount',
                'GROUP_CONCAT(DISTINCT CONCAT(gibbonPerson.preferredName, " ", gibbonPerson.surname) SEPARATOR ", ") as students'
            ])
            ->from('gibbonFamily')
            ->innerJoin('gibbonFamilyChild', 'gibbonFamily.gibbonFamilyID = gibbonFamilyChild.gibbonFamilyID')
            ->innerJoin('gibbonPerson', 'gibbonFamilyChild.gibbonPersonID = gibbonPerson.gibbonPersonID')
            ->innerJoin('gibbonCourseClassPerson', 'gibbonPerson.gibbonPersonID = gibbonCourseClassPerson.gibbonPersonID')
            ->innerJoin('gibbonCourseClass', 'gibbonCourseClassPerson.gibbonCourseClassID = gibbonCourseClass.gibbonCourseClassID')
            ->innerJoin('gibbonCourse', 'gibbonCourseClass.gibbonCourseID = gibbonCourse.gibbonCourseID')
            ->leftJoin('gibbonSEPA', 'gibbonFamily.gibbonFamilyID = gibbonSEPA.gibbonFamilyID')
            ->where('gibbonSEPA.gibbonSEPAID IS NULL')
            ->where('gibbonCourse.gibbonSchoolYearID = :schoolYearID')
            ->where('gibbonPerson.status = "Full"')
            ->bindValue('schoolYearID', $schoolYearID)
            ->groupBy(['gibbonFamily.gibbonFamilyID', 'gibbonFamily.name', 'gibbonFamily.status'])
            ->orderBy(['studentCount DESC', 'familyName']);

        return $this->runQuery($query, $criteria);
    }

    /**
     * Helper: Get academic year information (months)
     *
     * Calculates total months in academic year and current progress
     *
     * @param int $schoolYearID
     * @return array ['totalMonths', 'currentMonth', 'proportion', 'startDate', 'endDate']
     */
    public function getAcademicYearMonths($schoolYearID)
    {
        $query = $this
            ->newSelect()
            ->cols(['firstDay', 'lastDay'])
            ->from('gibbonSchoolYear')
            ->where('gibbonSchoolYearID = :schoolYearID')
            ->bindValue('schoolYearID', $schoolYearID);

        $year = $this->runSelect($query)->fetch();

        if (!$year) {
            return [
                'totalMonths' => 10,
                'currentMonth' => 1,
                'proportion' => 0.1,
                'startDate' => null,
                'endDate' => null
            ];
        }

        $start = new \DateTime($year['firstDay']);
        $end = new \DateTime($year['lastDay']);
        $today = new \DateTime('today');

        // Calculate total months (including partial months)
        $interval = $start->diff($end);
        $totalMonths = ($interval->y * 12) + $interval->m + 1; // +1 to include both start and end month

        // Calculate current month (how many months since start)
        $currentInterval = $start->diff($today);
        $currentMonth = min(($currentInterval->y * 12) + $currentInterval->m + 1, $totalMonths);
        $currentMonth = max($currentMonth, 1); // At least month 1

        $proportion = $totalMonths > 0 ? $currentMonth / $totalMonths : 0;

        return [
            'totalMonths' => $totalMonths,
            'currentMonth' => $currentMonth,
            'proportion' => $proportion,
            'startDate' => $year['firstDay'],
            'endDate' => $year['lastDay']
        ];
    }

    /**
     * Issue Type 5: Detect low balances (underpayment)
     *
     * Finds families that have paid less than expected based on academic year progress
     * Uses proportion method: (payments + positive_adj) < (totalFees + negative_adj) * (current_month / total_months)
     *
     * @param QueryCriteria $criteria
     * @param int $schoolYearID Academic year to analyze
     * @param float $threshold Minimum shortfall to report (in euros or percentage)
     * @param string $method Detection method: 'number', 'percentage', or 'proportion_to_academic_year'
     * @return object Result set with families having low balances
     */
    public function getLowBalanceFamilies(QueryCriteria $criteria, $schoolYearID, $threshold = 2, $method = 'number')
    {
        // Get academic year progress
        $yearInfo = $this->getAcademicYearMonths($schoolYearID);
        $proportion = $yearInfo['proportion'];

        // Build SQL for enrollment fees calculation
        $enrollmentMonthsSQL = 'TIMESTAMPDIFF(MONTH, DATE_FORMAT(GREATEST(gibbonCourseClassPerson.dateEnrolled, gibbonSchoolYear.firstDay), \'%Y-%m-01\'), LAST_DAY(COALESCE(gibbonCourseClassPerson.dateUnenrolled, gibbonSchoolYear.lastDay)))';
        $enrollmentFeesSQL = 'COALESCE(gibbonSepaCoursesFees.fees, 0) * ' . $enrollmentMonthsSQL;

        $query = $this
            ->newQuery()
            ->cols([
                'gibbonFamily.gibbonFamilyID',
                'gibbonFamily.name as familyName',
                'gibbonSEPA.gibbonSEPAID',
                'gibbonSEPA.payer',

                // Total fees for the year
                'SUM(' . $enrollmentFeesSQL . ') as totalFees',

                // Payments (positive amounts)
                '(SELECT COALESCE(SUM(amount), 0) FROM gibbonSEPAPaymentEntry WHERE gibbonSEPAPaymentEntry.gibbonSEPAID = gibbonSEPA.gibbonSEPAID AND gibbonSEPAPaymentEntry.academicYear = :schoolYearID) as totalPayments',

                // Positive adjustments (credits)
                '(SELECT COALESCE(SUM(ABS(amount)), 0) FROM gibbonSEPAPaymentAdjustment WHERE gibbonSEPAPaymentAdjustment.gibbonSEPAID = gibbonSEPA.gibbonSEPAID AND gibbonSEPAPaymentAdjustment.academicYear = :schoolYearID AND amount > 0) as positiveAdjustments',

                // Negative adjustments (additional fees)
                '(SELECT COALESCE(SUM(ABS(amount)), 0) FROM gibbonSEPAPaymentAdjustment WHERE gibbonSEPAPaymentAdjustment.gibbonSEPAID = gibbonSEPA.gibbonSEPAID AND gibbonSEPAPaymentAdjustment.academicYear = :schoolYearID AND amount < 0) as negativeAdjustments',

                // Actual paid = payments + positive adjustments
                '((SELECT COALESCE(SUM(amount), 0) FROM gibbonSEPAPaymentEntry WHERE gibbonSEPAPaymentEntry.gibbonSEPAID = gibbonSEPA.gibbonSEPAID AND gibbonSEPAPaymentEntry.academicYear = :schoolYearID) + (SELECT COALESCE(SUM(ABS(amount)), 0) FROM gibbonSEPAPaymentAdjustment WHERE gibbonSEPAPaymentAdjustment.gibbonSEPAID = gibbonSEPA.gibbonSEPAID AND gibbonSEPAPaymentAdjustment.academicYear = :schoolYearID AND amount > 0)) as actualPaid',

                // Expected now = (totalFees + negative adjustments) * proportion
                '((SUM(' . $enrollmentFeesSQL . ') + (SELECT COALESCE(SUM(ABS(amount)), 0) FROM gibbonSEPAPaymentAdjustment WHERE gibbonSEPAPaymentAdjustment.gibbonSEPAID = gibbonSEPA.gibbonSEPAID AND gibbonSEPAPaymentAdjustment.academicYear = :schoolYearID AND amount < 0)) * :proportion) as expectedNow',

                // Shortfall = expectedNow - actualPaid
                '(((SUM(' . $enrollmentFeesSQL . ') + (SELECT COALESCE(SUM(ABS(amount)), 0) FROM gibbonSEPAPaymentAdjustment WHERE gibbonSEPAPaymentAdjustment.gibbonSEPAID = gibbonSEPA.gibbonSEPAID AND gibbonSEPAPaymentAdjustment.academicYear = :schoolYearID AND amount < 0)) * :proportion) - ((SELECT COALESCE(SUM(amount), 0) FROM gibbonSEPAPaymentEntry WHERE gibbonSEPAPaymentEntry.gibbonSEPAID = gibbonSEPA.gibbonSEPAID AND gibbonSEPAPaymentEntry.academicYear = :schoolYearID) + (SELECT COALESCE(SUM(ABS(amount)), 0) FROM gibbonSEPAPaymentAdjustment WHERE gibbonSEPAPaymentAdjustment.gibbonSEPAID = gibbonSEPA.gibbonSEPAID AND gibbonSEPAPaymentAdjustment.academicYear = :schoolYearID AND amount > 0))) as shortfall'
            ])
            ->from('gibbonFamily')
            ->innerJoin('gibbonSEPA', 'gibbonFamily.gibbonFamilyID = gibbonSEPA.gibbonFamilyID')
            ->innerJoin('gibbonFamilyChild', 'gibbonFamily.gibbonFamilyID = gibbonFamilyChild.gibbonFamilyID')
            ->innerJoin('gibbonPerson', 'gibbonFamilyChild.gibbonPersonID = gibbonPerson.gibbonPersonID')
            ->innerJoin('gibbonCourseClassPerson', 'gibbonPerson.gibbonPersonID = gibbonCourseClassPerson.gibbonPersonID')
            ->innerJoin('gibbonCourseClass', 'gibbonCourseClassPerson.gibbonCourseClassID = gibbonCourseClass.gibbonCourseClassID')
            ->innerJoin('gibbonCourse', 'gibbonCourseClass.gibbonCourseID = gibbonCourse.gibbonCourseID')
            ->innerJoin('gibbonSchoolYear', 'gibbonCourse.gibbonSchoolYearID = gibbonSchoolYear.gibbonSchoolYearID')
            ->leftJoin('gibbonSepaCoursesFees', 'gibbonCourse.gibbonCourseID = gibbonSepaCoursesFees.gibbonCourseID')
            ->where('gibbonSchoolYear.gibbonSchoolYearID = :schoolYearID')
            ->bindValue('schoolYearID', $schoolYearID)
            ->bindValue('proportion', $proportion)
            ->groupBy(['gibbonFamily.gibbonFamilyID', 'gibbonFamily.name', 'gibbonSEPA.gibbonSEPAID', 'gibbonSEPA.payer'])
            ->having('shortfall > :threshold')
            ->bindValue('threshold', $threshold)
            ->orderBy(['shortfall DESC']);

        return $this->runQuery($query, $criteria);
    }

    /**
     * Issue Type 6: Detect high balances (overpayment)
     *
     * Finds families with positive balance above threshold
     * Simple check: balance = payments + adjustments - totalFees > threshold
     *
     * @param QueryCriteria $criteria
     * @param int $schoolYearID Academic year to analyze
     * @param float $threshold Minimum overpayment to report (in euros)
     * @return object Result set with families having high balances
     */
    public function getHighBalanceFamilies(QueryCriteria $criteria, $schoolYearID, $threshold = 10)
    {
        // Build SQL for enrollment fees calculation
        $enrollmentMonthsSQL = 'TIMESTAMPDIFF(MONTH, DATE_FORMAT(GREATEST(gibbonCourseClassPerson.dateEnrolled, gibbonSchoolYear.firstDay), \'%Y-%m-01\'), LAST_DAY(COALESCE(gibbonCourseClassPerson.dateUnenrolled, gibbonSchoolYear.lastDay)))';
        $enrollmentFeesSQL = 'COALESCE(gibbonSepaCoursesFees.fees, 0) * ' . $enrollmentMonthsSQL;

        $query = $this
            ->newQuery()
            ->cols([
                'gibbonFamily.gibbonFamilyID',
                'gibbonFamily.name as familyName',
                'gibbonSEPA.gibbonSEPAID',
                'gibbonSEPA.payer',

                // Total fees for the year
                'SUM(' . $enrollmentFeesSQL . ') as totalFees',

                // Total payments
                '(SELECT COALESCE(SUM(amount), 0) FROM gibbonSEPAPaymentEntry WHERE gibbonSEPAPaymentEntry.gibbonSEPAID = gibbonSEPA.gibbonSEPAID AND gibbonSEPAPaymentEntry.academicYear = :schoolYearID) as totalPayments',

                // Total adjustments (can be positive or negative)
                '(SELECT COALESCE(SUM(amount), 0) FROM gibbonSEPAPaymentAdjustment WHERE gibbonSEPAPaymentAdjustment.gibbonSEPAID = gibbonSEPA.gibbonSEPAID AND gibbonSEPAPaymentAdjustment.academicYear = :schoolYearID) as totalAdjustments',

                // Balance = payments + adjustments - fees
                '((SELECT COALESCE(SUM(amount), 0) FROM gibbonSEPAPaymentEntry WHERE gibbonSEPAPaymentEntry.gibbonSEPAID = gibbonSEPA.gibbonSEPAID AND gibbonSEPAPaymentEntry.academicYear = :schoolYearID) + (SELECT COALESCE(SUM(amount), 0) FROM gibbonSEPAPaymentAdjustment WHERE gibbonSEPAPaymentAdjustment.gibbonSEPAID = gibbonSEPA.gibbonSEPAID AND gibbonSEPAPaymentAdjustment.academicYear = :schoolYearID) - SUM(' . $enrollmentFeesSQL . ')) as balance',

                // Excess = balance (when positive)
                '((SELECT COALESCE(SUM(amount), 0) FROM gibbonSEPAPaymentEntry WHERE gibbonSEPAPaymentEntry.gibbonSEPAID = gibbonSEPA.gibbonSEPAID AND gibbonSEPAPaymentEntry.academicYear = :schoolYearID) + (SELECT COALESCE(SUM(amount), 0) FROM gibbonSEPAPaymentAdjustment WHERE gibbonSEPAPaymentAdjustment.gibbonSEPAID = gibbonSEPA.gibbonSEPAID AND gibbonSEPAPaymentAdjustment.academicYear = :schoolYearID) - SUM(' . $enrollmentFeesSQL . ')) as excess'
            ])
            ->from('gibbonFamily')
            ->innerJoin('gibbonSEPA', 'gibbonFamily.gibbonFamilyID = gibbonSEPA.gibbonFamilyID')
            ->innerJoin('gibbonFamilyChild', 'gibbonFamily.gibbonFamilyID = gibbonFamilyChild.gibbonFamilyID')
            ->innerJoin('gibbonPerson', 'gibbonFamilyChild.gibbonPersonID = gibbonPerson.gibbonPersonID')
            ->innerJoin('gibbonCourseClassPerson', 'gibbonPerson.gibbonPersonID = gibbonCourseClassPerson.gibbonPersonID')
            ->innerJoin('gibbonCourseClass', 'gibbonCourseClassPerson.gibbonCourseClassID = gibbonCourseClass.gibbonCourseClassID')
            ->innerJoin('gibbonCourse', 'gibbonCourseClass.gibbonCourseID = gibbonCourse.gibbonCourseID')
            ->innerJoin('gibbonSchoolYear', 'gibbonCourse.gibbonSchoolYearID = gibbonSchoolYear.gibbonSchoolYearID')
            ->leftJoin('gibbonSepaCoursesFees', 'gibbonCourse.gibbonCourseID = gibbonSepaCoursesFees.gibbonCourseID')
            ->where('gibbonSchoolYear.gibbonSchoolYearID = :schoolYearID')
            ->bindValue('schoolYearID', $schoolYearID)
            ->groupBy(['gibbonFamily.gibbonFamilyID', 'gibbonFamily.name', 'gibbonSEPA.gibbonSEPAID', 'gibbonSEPA.payer'])
            ->having('balance > :threshold')
            ->bindValue('threshold', $threshold)
            ->orderBy(['balance DESC']);

        return $this->runQuery($query, $criteria);
    }

    /**
     * Get summary count of all issues
     *
     * Returns count for each issue type for dashboard display
     *
     * @param int $schoolYearID Academic year to analyze
     * @return array Associative array with counts for each issue type
     */
    public function getIssueSummary($schoolYearID)
    {
        $criteria = $this->newQueryCriteria(false)
            ->pageSize(999999);

        // Get settings
        $oldDateThreshold = (int) $this->getIssueSetting('sepa_old_date_threshold_years');
        $lowBalanceThreshold = (float) $this->getIssueSetting('balance_method_attribute');
        $highBalanceThreshold = (float) $this->getIssueSetting('balance_method_more_than_attribute');

        return [
            'similar_ibans' => $this->getSimilarIBANs($criteria)->count(),
            'similar_payers' => $this->getSimilarPayers($criteria)->count(),
            'old_sepa_dates' => $this->getOldSEPADates($criteria, $oldDateThreshold)->count(),
            'families_without_sepa' => $this->getFamiliesWithoutSEPA($criteria, $schoolYearID)->count(),
            'low_balances' => $this->getLowBalanceFamilies($criteria, $schoolYearID, $lowBalanceThreshold)->count(),
            'high_balances' => $this->getHighBalanceFamilies($criteria, $schoolYearID, $highBalanceThreshold)->count(),
        ];
    }
}
