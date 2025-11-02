<?php
namespace Gibbon\Module\Sepa\Domain;

use Gibbon\Domain\Traits\TableAware;
use Gibbon\Domain\QueryCriteria;
use Gibbon\Domain\QueryableGateway;

/**
 * SEPA Payment Adjustment Gateway
 *
 * @version v0
 * @since   v0
 */
class SepaPaymentAdjustmentGateway extends QueryableGateway
{
    use TableAware;

    private static $tableName = 'gibbonSEPAPaymentAdjustment';
    private static $primaryKey = 'gibbonSEPAPaymentAdjustmentID';
    private static $searchableColumns = ['description', 'note'];

    /* public function getAdjustmentsBySEPA($gibbonSEPAID, QueryCriteria $criteria = null)
    {
        $query = $this
            ->newSelect()
            ->cols(['*'])
            ->from($this->getTableName())
            ->where('gibbonSEPAID = :gibbonSEPAID')
            ->bindValue('gibbonSEPAID', $gibbonSEPAID)
            ;

        if ($criteria) {
            return $this->runQuery($query, $criteria);
        }

        return $this->runSelect($query);
    }
 */
    public function getAllAdjustment(QueryCriteria $criteria = null)
    {
        $query = $this
            ->newSelect()
            ->cols(['*'])
            ->from($this->getTableName())
        ;

        if ($criteria) {
            return $this->runQuery($query, $criteria);
        }

        return $this->runSelect($query);
    }

    public function insertAdjustment($data)
    {
        $query = $this
            ->newInsert()
            ->into($this->getTableName())
            ->cols([
                'gibbonSEPAID' => $data['gibbonSEPAID'],
                'amount' => $data['amount'],
                'description' => $data['description'],
                'note' => $data['note'],
                'gibbonPersonID' => $data['gibbonPersonID']
            ]);

        return $this->runInsert($query);
    }

    public function updateAdjustment($gibbonSEPAPaymentAdjustmentID, $data)
    {
        $query = $this
            ->newUpdate()
            ->table($this->getTableName())
            ->cols([
                'amount' => $data['amount'],
                'description' => $data['description'],
                'note' => $data['note']
            ])
            ->where('gibbonSEPAPaymentAdjustmentID = :gibbonSEPAPaymentAdjustmentID')
            ->bindValue('gibbonSEPAPaymentAdjustmentID', $gibbonSEPAPaymentAdjustmentID);

        return $this->runUpdate($query);
    }

    public function deleteAdjustment($gibbonSEPAPaymentAdjustmentID)
    {
        $query = $this
            ->newDelete()
            ->from($this->getTableName())
            ->where('gibbonSEPAPaymentAdjustmentID = :gibbonSEPAPaymentAdjustmentID')
            ->bindValue('gibbonSEPAPaymentAdjustmentID', $gibbonSEPAPaymentAdjustmentID);

        return $this->runDelete($query);
    }

    public function getAdjustmentByID($gibbonSEPAPaymentAdjustmentID)
    {
        $query = $this
            ->newSelect()
            ->cols(['*'])
            ->from($this->getTableName())
            ->where('gibbonSEPAPaymentAdjustmentID = :gibbonSEPAPaymentAdjustmentID')
            ->bindValue('gibbonSEPAPaymentAdjustmentID', $gibbonSEPAPaymentAdjustmentID);

        return $this->runSelect($query)->fetch();
    }

    public function getFamilyTotalAdjustments($gibbonFamilyID)
    {
        $query = $this
            ->newSelect()
            ->cols(['SUM(COALESCE(gibbonSEPAPaymentAdjustment.amount, 0)) as totalAdjustments'])
            ->from($this->getTableName())
            ->innerJoin('gibbonSEPA', 'gibbonSEPAPaymentAdjustment.gibbonSEPAID = gibbonSEPA.gibbonSEPAID')
            ->where('gibbonSEPA.gibbonFamilyID = :gibbonFamilyID')
            ->bindValue('gibbonFamilyID', $gibbonFamilyID);

        $result = $this->runSelect($query)->fetch();
        return $result['totalAdjustments'] ?? 0;
    }

    public function getFamilyAdjustments($gibbonSEPAID)
    {
        $query = $this
            ->newSelect()
            ->cols(['*'])
            ->from($this->getTableName())
            ->where('gibbonSEPAID = :gibbonSEPAID')
            ->bindValue('gibbonSEPAID', $gibbonSEPAID);

        return $this->runSelect($query)->fetchAll();
    }
}
