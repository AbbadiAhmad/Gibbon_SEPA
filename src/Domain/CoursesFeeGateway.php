<?php

namespace Gibbon\Module\Sepa\Domain;

use Gibbon\Domain\Traits\TableAware;
use Gibbon\Domain\QueryCriteria;
use Gibbon\Domain\QueryableGateway;

/**
 * Courses Fees Gateway
 *
 * @version v30
 * @since   v30
 */
class CoursesFeeGateway extends QueryableGateway
{
    use TableAware;

    private static $tableName = 'gibbonSepaCoursesFees';
    private static $primaryKey = 'gibbonSepaCoursesCostID';
    private static $searchableColumns = ['gibbonCourse.name', 'gibbonCourse.nameShort', 'fees'];

    /**
     * Get a list of courses fees with course details using QueryCriteria
     *
     * @param QueryCriteria $criteria
     * @param int $gibbonSchoolYearID
     * @return DataSet
     */
    public function queryCoursesFees(QueryCriteria $criteria, $gibbonSchoolYearID)
    {
        $query = $this
            ->newQuery()
            ->cols([
                'gibbonCourse.gibbonCourseID as gibbonCourseID',
                'gibbonCourse.nameShort',
                'gibbonCourse.name as courseName',
                'gibbonSchoolYear.name as yearName',
                'gibbonSepaCoursesFees.gibbonSepaCoursesCostID AS gibbonSepaCoursesCostID',
                'gibbonSepaCoursesFees.fees as Fees',
            ])
            ->from('gibbonCourse')
            ->leftJoin('gibbonSepaCoursesFees', 'gibbonCourse.gibbonCourseID = gibbonSepaCoursesFees.gibbonCourseID')
            ->leftJoin('gibbonSchoolYear', 'gibbonSchoolYear.gibbonSchoolYearID = gibbonCourse.gibbonSchoolYearID')
            ->orderBy(['gibbonSchoolYear.sequenceNumber DESC', 'gibbonCourse.nameShort']);

        // 'all' / empty: the courses of every academic year
        if (!empty($gibbonSchoolYearID) && $gibbonSchoolYearID !== 'all') {
            $query->where('gibbonCourse.gibbonSchoolYearID = :gibbonSchoolYearID')
                ->bindValue('gibbonSchoolYearID', $gibbonSchoolYearID);
        }

        $res = $this->runQuery($query, $criteria);
        return $res;
    }

    /**
     * The school year before the given one (by sequence number), or null.
     */
    public function getPreviousSchoolYear($gibbonSchoolYearID)
    {
        $query = $this
            ->newSelect()
            ->cols(['py.gibbonSchoolYearID', 'py.name'])
            ->from('gibbonSchoolYear AS cy')
            ->innerJoin('gibbonSchoolYear AS py', 'py.sequenceNumber < cy.sequenceNumber')
            ->where('cy.gibbonSchoolYearID = :gibbonSchoolYearID')
            ->bindValue('gibbonSchoolYearID', $gibbonSchoolYearID)
            ->orderBy(['py.sequenceNumber DESC'])
            ->limit(1);

        return $this->runSelect($query)->fetch() ?: null;
    }

    /**
     * Copy the fees of the previous school year to the courses of the year that have no fee yet.
     * Courses are matched on the short name (then the full name), ignoring case; a course without
     * a match, or whose match has no fee, stays empty. Fees that are set are never changed.
     *
     * @return array|null ['copied' => int, 'unmatched' => string[], 'previousYear' => string], null without previous year
     */
    public function copyFeesFromPreviousYear($gibbonSchoolYearID, $user)
    {
        $previous = $this->getPreviousSchoolYear($gibbonSchoolYearID);
        if (!$previous) {
            return null;
        }

        $load = function ($yearID) {
            $criteria = $this->newQueryCriteria(false);
            return $this->queryCoursesFees($criteria, $yearID)->toArray();
        };

        $normalise = function ($text) {
            return mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $text)));
        };

        // previous year: fee by short name and by name
        $byShort = [];
        $byName = [];
        foreach ($load($previous['gibbonSchoolYearID']) as $course) {
            if ($course['Fees'] === null || $course['gibbonSepaCoursesCostID'] === null) {
                continue;
            }
            $byShort += [$normalise($course['nameShort']) => $course['Fees']];
            $byName += [$normalise($course['courseName']) => $course['Fees']];
        }

        $copied = 0;
        $unmatched = [];
        foreach ($load($gibbonSchoolYearID) as $course) {
            if ($course['gibbonSepaCoursesCostID'] !== null) {
                continue;
            }
            $fee = $byShort[$normalise($course['nameShort'])] ?? $byName[$normalise($course['courseName'])] ?? null;
            if ($fee === null) {
                $unmatched[] = $course['nameShort'];
                continue;
            }
            if ($this->updateCoursesFees(['gibbonCourseID' => $course['gibbonCourseID'], 'fees' => $fee], $user)) {
                $copied++;
            }
        }

        return ['copied' => $copied, 'unmatched' => $unmatched, 'previousYear' => $previous['name']];
    }

    public function deleteCourseFee($gibbonSepaCoursesCostID)
    {
        return $this->delete($gibbonSepaCoursesCostID);
    }

    public function updateCoursesFees($data, $user)
    {
        if (empty($data["gibbonSepaCoursesCostID"])){
            $data['gibbonPersonIDCreator'] =  $user;
            $result = $this->insert( $data);
        }else{
            $data['gibbonPersonIDUpdate'] =  $user;
            $result = $this->Update($data["gibbonSepaCoursesCostID"], $data);
        }
        
        return $result;
    }


}
