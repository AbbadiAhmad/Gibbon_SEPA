<?php
namespace Gibbon\Module\Sepa\Domain;

use Gibbon\Domain\Traits\TableAware;
use Gibbon\Domain\QueryCriteria;
use Gibbon\Domain\QueryableGateway;

/**
 * SEPA Discount Gateway
 *
 * @version v0
 * @since   v0
 */
class SepaDiscountGateway extends QueryableGateway
{
    use TableAware;

    private static $tableName = 'gibbonSEPADiscount';
    private static $primaryKey = 'gibbonSEPADiscountID';
    private static $searchableColumns = ['description', 'note'];

    public function getDiscountsBySEPA($gibbonSEPAID, QueryCriteria $criteria = null)
    {
        $query = $this
            ->newSelect()
            ->cols(['*'])
            ->from($this->getTableName())
            ->where('gibbonSEPAID = :gibbonSEPAID')
            ->bindValue('gibbonSEPAID', $gibbonSEPAID)
            ->orderBy(['timestamp DESC']);

        if ($criteria) {
            return $this->runQuery($query, $criteria);
        }

        return $this->runSelect($query);
    }

    public function getAllDiscounts(QueryCriteria $criteria = null)
    {
        $query = $this
            ->newSelect()
            ->cols(['*'])
            ->from($this->getTableName())
            ->orderBy(['timestamp DESC']);

        if ($criteria) {
            return $this->runQuery($query, $criteria);
        }

        return $this->runSelect($query);
    }

    public function insertDiscount($data)
    {
        $query = $this
            ->newInsert()
            ->into($this->getTableName())
            ->cols([
                'gibbonSEPAID' => $data['gibbonSEPAID'],
                'discountAmount' => $data['discountAmount'],
                'description' => $data['description'],
                'note' => $data['note'],
                'gibbonPersonID' => $data['gibbonPersonID']
            ]);

        return $this->runInsert($query);
    }

    public function updateDiscount($gibbonSEPADiscountID, $data)
    {
        $query = $this
            ->newUpdate()
            ->table($this->getTableName())
            ->cols([
                'discountAmount' => $data['discountAmount'],
                'description' => $data['description'],
                'note' => $data['note']
            ])
            ->where('gibbonSEPADiscountID = :gibbonSEPADiscountID')
            ->bindValue('gibbonSEPADiscountID', $gibbonSEPADiscountID);

        return $this->runUpdate($query);
    }

    public function deleteDiscount($gibbonSEPADiscountID)
    {
        $query = $this
            ->newDelete()
            ->from($this->getTableName())
            ->where('gibbonSEPADiscountID = :gibbonSEPADiscountID')
            ->bindValue('gibbonSEPADiscountID', $gibbonSEPADiscountID);

        return $this->runDelete($query);
    }

    public function getDiscountByID($gibbonSEPADiscountID)
    {
        $query = $this
            ->newSelect()
            ->cols(['*'])
            ->from($this->getTableName())
            ->where('gibbonSEPADiscountID = :gibbonSEPADiscountID')
            ->bindValue('gibbonSEPADiscountID', $gibbonSEPADiscountID);

        return $this->runSelect($query)->fetch();
    }
}
