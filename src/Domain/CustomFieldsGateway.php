<?php
namespace Gibbon\Module\Sepa\Domain; //Replace ModuleName with your module's name, ommiting spaces

use Gibbon\Domain\Traits\TableAware;
use Gibbon\Domain\QueryableGateway;
use Gibbon\Services\Format;
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

    private static $customFieldPrefix = 'C_';

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

    public function getCustomeFieldPrefix()
    {
        return self::$customFieldPrefix;
    }

    public function getCustomFieldInitialData($activeFieldOnly = false)
    {
        $customFieldsData = [];
        $customFields = $this->getCustomFields($activeFieldOnly);
        foreach ($customFields as $field) {
            $customFieldsData[self::$customFieldPrefix . intval($field['gibbonSEPACustomFieldID'])] = '';
        }
        return $customFieldsData;
    }

    public function addCustomFieldsToForm(&$form, $fields = [])
    {
        $existingFields = !empty($fields) && is_string($fields) ? json_decode($fields, true) : (is_array($fields) ? $fields : []);
        $customFields = $this->getCustomFields(false);
        $table = $form;

        if (empty($customFields)) {
            return;
        }


        $row = $table->addRow()->addHeading('Custome Data');

        foreach ($customFields as $field) {
            $name = self::$customFieldPrefix . intval($field['gibbonSEPACustomFieldID']);
            if (isset($existingFields) && isset($existingFields[$name])) {
                $fieldValue = $existingFields[$name];
                if (!empty($fieldValue) && $field['type'] == 'date') {
                    $fieldValue = Format::date($fieldValue);
                } elseif (!empty($fieldValue) && $field['type'] == 'checkboxes' && $field['type'] == 'select' && $field['type'] == 'radio') {
                    $fieldValue = explode(',', $fieldValue);
                }
            } else
                $fieldValue = null;


            if ($field['active'] == 'Y') {
                $row = $table->addRow();
                $row->addLabel($name . '_label', $field['title'])->description(Format::hyperlinkAll($field['description']));
                if ($fieldValue)
                    $row->addCustomField($name, $field)->setValue($fieldValue);
                else
                    $row->addCustomField($name, $field);
            } elseif ($fieldValue) {
                $table->addHiddenValue($name, $fieldValue);
            }

        }

    }
}


