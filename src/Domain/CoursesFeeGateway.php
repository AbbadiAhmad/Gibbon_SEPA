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
                'gibbonSepaCoursesFees.gibbonSepaCoursesCostID AS gibbonSepaCoursesCostID',
                'gibbonSepaCoursesFees.fees as Fees',
            ])
            ->from('gibbonCourse')
            ->leftJoin('gibbonSepaCoursesFees', 'gibbonCourse.gibbonCourseID = gibbonSepaCoursesFees.gibbonCourseID')
            ->leftJoin('gibbonSchoolYear', 'gibbonSchoolYear.gibbonSchoolYearID = gibbonCourse.gibbonSchoolYearID')
            ->where('gibbonCourse.gibbonSchoolYearID = :gibbonSchoolYearID')
            ->bindValue('gibbonSchoolYearID', $gibbonSchoolYearID);

        $res = $this->runQuery($query, $criteria);
        return $res;
    }

    /**
     * Get all courses for a specific school year with their fees (if assigned)
     * This is used for the bulk edit page
     *
     * @param int $gibbonSchoolYearID
     * @return DataSet
     */
    public function selectCoursesBySchoolYear($gibbonSchoolYearID)
    {
        $data = ['gibbonSchoolYearID' => $gibbonSchoolYearID];
        $query = $this
            ->newQuery()
            ->cols([
                'gibbonCourse.gibbonCourseID',
                'gibbonCourse.nameShort',
                'gibbonCourse.name as courseName',
                'gibbonCourse.description',
            ])
            ->from('gibbonCourse')
            ->WHERE('gibbonCourse.gibbonSchoolYearID = :gibbonSchoolYearID')
            ->orderBy(['gibbonCourse.nameShort'])
            ->bindValue('gibbonSchoolYearID', $gibbonSchoolYearID);

        return $this->runQuery($query, $criteria);
    }

    /**
     * insert courses fee
     * @param array $data
     * @return bool
     */
    public function insertCoursesFees(array $data)
    {
        return $this->insert($data);
    }

    /**
     * Update an existing courses fee record
     *
     * @param int $gibbonSepaCoursesCostID
     * @param array $data
     * @return bool
     */
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

    /**
     * Delete a courses fee record
     *
     * @param int $gibbonSepaCoursesCostID
     * @return bool
     */
    public function deleteCoursesFees($gibbonSepaCoursesCostID)
    {
        return $this->delete($gibbonSepaCoursesCostID);
    }


}
