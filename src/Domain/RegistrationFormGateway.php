<?php
namespace Gibbon\Module\Sepa\Domain;

use Gibbon\Domain\Traits\TableAware;
use Gibbon\Domain\QueryableGateway;

/**
 * Reads the accepted registration (admissions) forms, which carry the SEPA data entered by the parents.
 *
 * The form fields are configurable in Gibbon (custom0006, ...), so the field -> SEPA mapping is chosen
 * by the user; this gateway only returns the raw form data together with the family of the child.
 */
class RegistrationFormGateway extends QueryableGateway
{
    use TableAware;

    private static $tableName = 'gibbonAdmissionsApplication';
    private static $primaryKey = 'gibbonAdmissionsApplicationID';
    private static $searchableColumns = [];

    /** Pseudo form field: the creation date of the application (used as the SEPA signed date). */
    const FIELD_CREATED = '__timestampCreated';

    /**
     * Name of the column that holds the school year in gibbonAdmissionsApplication
     * (differs between Gibbon versions).
     */
    private function getSchoolYearColumn()
    {
        $columns = array_column($this->db()->select('SHOW COLUMNS FROM gibbonAdmissionsApplication')->fetchAll(), 'Field');
        foreach (['gibbonSchoolYearID', 'gibbonSchoolYearIDEntry'] as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }
        throw new \RuntimeException('gibbonAdmissionsApplication has no school year column.');
    }

    public function selectStatuses()
    {
        return $this->db()->select('SELECT DISTINCT status FROM gibbonAdmissionsApplication ORDER BY status')->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Applications of a school year with the family of the registered child.
     * `formData` is the decoded `data` JSON of the form, `familyID` is NULL when the application
     * has not been connected to a child / family (yet).
     */
    public function getApplications($schoolYearID, $status)
    {
        $yearColumn = $this->getSchoolYearColumn();

        $sql = "SELECT app.gibbonAdmissionsApplicationID, app.status, app.timestampCreated, app.data,
                    COALESCE(gibbonFamilyChild.gibbonFamilyID, NULLIF(JSON_UNQUOTE(JSON_EXTRACT(app.result, '$.gibbonFamilyID')), 'null')) AS familyID,
                    gibbonFamily.name AS familyName
                FROM gibbonAdmissionsApplication AS app
                LEFT JOIN gibbonFamilyChild ON gibbonFamilyChild.gibbonFamilyChildID = JSON_UNQUOTE(JSON_EXTRACT(app.result, '$.gibbonFamilyChildID'))
                LEFT JOIN gibbonFamily ON gibbonFamily.gibbonFamilyID = COALESCE(gibbonFamilyChild.gibbonFamilyID, NULLIF(JSON_UNQUOTE(JSON_EXTRACT(app.result, '$.gibbonFamilyID')), 'null'))
                WHERE app.status = :status AND app.{$yearColumn} = :schoolYearID
                ORDER BY app.timestampCreated, app.gibbonAdmissionsApplicationID";

        $applications = $this->db()->select($sql, ['status' => $status, 'schoolYearID' => $schoolYearID])->fetchAll();

        foreach ($applications as &$application) {
            $decoded = json_decode($application['data'] ?? '', true);
            $application['formData'] = is_array($decoded) ? $decoded : [];
            unset($application['data']);
        }

        return $applications;
    }

    /**
     * Labels of the custom form fields: ['custom0006' => 'IBAN', ...]. Empty when not available.
     */
    public function getCustomFieldLabels()
    {
        try {
            $rows = $this->db()->select('SELECT gibbonCustomFieldID, name FROM gibbonCustomField')->fetchAll();
        } catch (\Exception $e) {
            return [];
        }
        $labels = [];
        foreach ($rows as $row) {
            $labels['custom' . str_pad($row['gibbonCustomFieldID'], 4, '0', STR_PAD_LEFT)] = $row['name'];
        }
        return $labels;
    }
}
