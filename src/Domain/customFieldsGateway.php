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
class CustomFieldsGateway extends QueryableGateway
{
    use TableAware;
    private static $tableName = 'gibbonSEPACustomField'; //The name of the table you will primarily be querying
    private static $primaryKey = 'gibbonSEPACustomeFieldID'; //The primaryKey of said table
    private static $searchableColumns = ['title', 'SEPA_IBAN', 'customData']; // Optional: Array of Columns to be searched when using the search filter

    public function getCustomFields($activeFieldOnly = true)
    {
        $query = $this
            ->newSelect()
            ->cols(['*'])
            ->from($this->getTableName());
        if ($activeFieldOnly)
            $query->where("active='Y'");


        return $this->runSelect($query);
    }

}
