<?php
namespace Gibbon\Module\Sepa\Domain;

use Gibbon\Domain\Traits\TableAware;
use Gibbon\Domain\QueryCriteria;
use Gibbon\Domain\QueryableGateway;

/**
 * Balance Snapshot Gateway
 *
 * @version v2.0.0
 * @since   v2.0.0
 */
class SnapshotGateway extends QueryableGateway
{
    use TableAware;

    private static $tableName = 'gibbonSEPABalanceSnapshot';
    private static $primaryKey = 'gibbonSEPABalanceSnapshotID';
    private static $searchableColumns = ['gibbonFamilyID'];

    /**
     * Get all snapshots for a given academic year
     */
    public function getSnapshotsByYear(QueryCriteria $criteria, $academicYear)
    {
        $query = $this
            ->newQuery()
            ->from($this->getTableName())
            ->cols([
                'gibbonSEPABalanceSnapshotID',
                'snapshotDate',
                'gibbonPersonID',
                'COUNT(DISTINCT gibbonFamilyID) as familyCount'
            ])
            ->where('academicYear = :academicYear')
            ->bindValue('academicYear', $academicYear)
            ->groupBy(['snapshotDate', 'gibbonPersonID', 'gibbonSEPABalanceSnapshotID'])
            ->orderBy(['snapshotDate DESC']);

        return $this->runQuery($query, $criteria);
    }

    /**
     * Get the most recent snapshot for each family in a given academic year
     */
    public function getLatestSnapshotsByYear($academicYear)
    {
        // Get all snapshots for the year, ordered by family and date
        $query = $this
            ->newSelect()
            ->from($this->getTableName())
            ->cols([
                'gibbonSEPABalanceSnapshotID',
                'gibbonFamilyID',
                'gibbonSEPAID',
                'snapshotDate',
                'balance'
            ])
            ->where('academicYear = :academicYear')
            ->bindValue('academicYear', $academicYear)
            ->orderBy(['gibbonFamilyID', 'snapshotDate DESC']);

        $results = $this->runSelect($query);

        // Filter to only keep the most recent snapshot per family
        $latestByFamily = [];
        foreach ($results as $snapshot) {
            $familyID = $snapshot['gibbonFamilyID'];
            if (!isset($latestByFamily[$familyID])) {
                $latestByFamily[$familyID] = $snapshot;
            }
        }

        return new \Gibbon\Domain\DataSet(array_values($latestByFamily));
    }

    /**
     * Get snapshots by snapshot date
     */
    public function getSnapshotsByDate(QueryCriteria $criteria, $snapshotDate, $academicYear)
    {
        $query = $this
            ->newQuery()
            ->from($this->getTableName() . ' as snap')
            ->cols([
                'snap.*',
                'f.name as familyName',
                'sepa.payer',
                'sepa.IBAN'
            ])
            ->leftJoin('gibbonFamily as f', 'snap.gibbonFamilyID = f.gibbonFamilyID')
            ->leftJoin('gibbonSEPA as sepa', 'snap.gibbonSEPAID = sepa.gibbonSEPAID')
            ->where('snap.snapshotDate = :snapshotDate')
            ->where('snap.academicYear = :academicYear')
            ->bindValue('snapshotDate', $snapshotDate)
            ->bindValue('academicYear', $academicYear)
            ->orderBy(['f.name']);

        return $this->runQuery($query, $criteria);
    }

    /**
     * Get a specific snapshot by ID
     */
    public function getSnapshotByID($snapshotID)
    {
        $query = $this
            ->newSelect()
            ->from($this->getTableName())
            ->cols(['*'])
            ->where('gibbonSEPABalanceSnapshotID = :snapshotID')
            ->bindValue('snapshotID', $snapshotID);

        return $this->runSelect($query)->fetch();
    }

    /**
     * Get snapshots for a specific family
     */
    public function getSnapshotsByFamily($gibbonFamilyID, $academicYear)
    {
        $query = $this
            ->newSelect()
            ->from($this->getTableName())
            ->cols(['*'])
            ->where('gibbonFamilyID = :gibbonFamilyID')
            ->where('academicYear = :academicYear')
            ->bindValue('gibbonFamilyID', $gibbonFamilyID)
            ->bindValue('academicYear', $academicYear)
            ->orderBy(['snapshotDate DESC']);

        return $this->runSelect($query);
    }

    /**
     * Get the latest snapshot for a specific family
     */
    public function getLatestSnapshotByFamily($gibbonFamilyID, $academicYear)
    {
        $query = $this
            ->newSelect()
            ->from($this->getTableName())
            ->cols(['*'])
            ->where('gibbonFamilyID = :gibbonFamilyID')
            ->where('academicYear = :academicYear')
            ->bindValue('gibbonFamilyID', $gibbonFamilyID)
            ->bindValue('academicYear', $academicYear)
            ->orderBy(['snapshotDate DESC'])
            ->limit(1);

        return $this->runSelect($query)->fetch();
    }

    /**
     * Get unique snapshot dates for a given academic year
     */
    public function getSnapshotDates($academicYear)
    {
        $query = $this
            ->newSelect()
            ->from($this->getTableName())
            ->cols([
                'snapshotDate',
                'COUNT(*) as snapshotCount'
            ])
            ->where('academicYear = :academicYear')
            ->bindValue('academicYear', $academicYear)
            ->groupBy(['snapshotDate'])
            ->orderBy(['snapshotDate DESC']);

        return $this->runSelect($query);
    }

    /**
     * Insert a new snapshot
     */
    public function insertSnapshot($data)
    {
        return $this->insert($data);
    }

    /**
     * Delete snapshots by date
     */
    public function deleteSnapshotsByDate($snapshotDate, $academicYear)
    {
        $query = $this
            ->newDelete()
            ->from($this->getTableName())
            ->where('snapshotDate = :snapshotDate')
            ->where('academicYear = :academicYear')
            ->bindValue('snapshotDate', $snapshotDate)
            ->bindValue('academicYear', $academicYear);

        return $this->runDelete($query);
    }
}
