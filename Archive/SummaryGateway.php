<?php
namespace Gibbon\Module\Sepa\Domain;

use Gibbon\Domain\Traits\TableAware;
use Gibbon\Domain\QueryCriteria;
use Gibbon\Domain\QueryableGateway;

/**
 * Summary Gateway
 *
 * @version v0
 * @since   v0
 */
class SummaryGateway extends QueryableGateway
{
    use TableAware;

    private static $tableName = 'gibbonSEPA'; //The name of the table you will primarily be querying
    private static $primaryKey = 'gibbonSEPAID'; //The primaryKey of said table
    private static $searchableColumns = ['payer', 'IBAN', 'customData']; // Optional: Array of Columns to be searched when using the search filter

    public function getSEPAData1($criteria, $gibbonSEPAIDs = NULL)
    {

        $gibbonSEPAIDList = is_array($gibbonSEPAIDs) ? implode(',', $gibbonSEPAIDs) : $gibbonSEPAIDs;

        $query = $this
            ->newQuery()
            ->cols(['gibbonFamily.name as Family', 'gibbonSEPA.gibbonSEPAID', 'gibbonSEPA.payer as Owner', 'COALESCE(SUM(gibbonSEPAPaymentEntry.amount), 0) AS total_amount'])
            ->from('gibbonFamily')
            ->leftJoin('gibbonSEPA', 'gibbonFamily.gibbonFamilyID=gibbonSEPA.gibbonFamilyID')
            ->leftJoin('gibbonSEPAPaymentEntry', 'gibbonSEPAPaymentEntry.gibbonSEPAID=gibbonSEPA.gibbonSEPAID AND gibbonSEPAPaymentEntry.academicYear = 28')
            ->groupBy(['gibbonFamily.gibbonFamilyID', 'Family'])
        ;

        $criteria->addFilterRules([
            'search' => function ($query, $search) {
                return $query->where('gibbonFamily.name LIKE :search OR gibbonSEPA.payer LIKE :search')
                    ->bindValue('search', '%' . $search . '%');
            },
        ]);

        return $this->runQuery($query, $criteria);

    }
}
