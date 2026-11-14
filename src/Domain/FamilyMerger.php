<?php

namespace Gibbon\Module\Sepa\Domain;

use PDO;

/**
 * Merges two families: the members (adults and children), the family data and the SEPA of the
 * "drop" family move into the "keep" family. Persons that were registered twice (e.g. once by the
 * mother and once by the father) can be merged into one user.
 *
 * The same class validates the request for the preview page and executes it in the process page,
 * so what is shown is exactly what is checked and done.
 */
class FamilyMerger
{
    /** gibbonFamily columns the user can choose per field. */
    const FAMILY_FIELDS = ['name', 'status', 'languageHomePrimary', 'languageHomeSecondary', 'nameAddress', 'homeAddress', 'homeAddressDistrict', 'homeAddressCountry'];

    /** gibbonSEPA columns the user can choose per field (customData = the custom fields as a whole). */
    const SEPA_FIELDS = ['payer', 'IBAN', 'BIC', 'withdrawCadence', 'withdrawDay', 'SEPA_signedDate', 'note', 'customData'];

    /** gibbonPerson columns the user can choose per field when two users are merged. */
    const PERSON_FIELDS = [
        'title', 'surname', 'firstName', 'preferredName', 'officialName', 'nameInCharacters', 'gender', 'dob',
        'email', 'emailAlternate', 'website',
        'address1', 'address1District', 'address1Country', 'address2', 'address2District', 'address2Country',
        'phone1Type', 'phone1CountryCode', 'phone1', 'phone2Type', 'phone2CountryCode', 'phone2',
        'phone3Type', 'phone3CountryCode', 'phone3', 'phone4Type', 'phone4CountryCode', 'phone4',
        'languageFirst', 'languageSecond', 'languageThird', 'countryOfBirth', 'ethnicity', 'religion',
        'citizenship1', 'citizenship1Passport', 'citizenship2', 'citizenship2Passport',
        'nationalIDCardNumber', 'residencyStatus', 'visaExpiryDate', 'profession', 'employer', 'jobTitle',
        'dateStart', 'dateEnd',
    ];

    /** Tables that are handled by the merge itself, they are not re-pointed generically. */
    const HANDLED_PERSON_TABLES = ['gibbonPerson', 'gibbonFamilyAdult', 'gibbonFamilyChild', 'gibbonFamilyRelationship'];

    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // ------------------------------------------------------------------ loading

    private function select($sql, array $params = [])
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** IDs are zero padded in the database; compare them as numbers. */
    public static function id($value)
    {
        return (int) $value;
    }

    public function getFamilyOptions()
    {
        $rows = $this->select("SELECT gibbonFamilyID, name FROM gibbonFamily ORDER BY name, gibbonFamilyID")->fetchAll(PDO::FETCH_ASSOC);
        $options = [];
        foreach ($rows as $row) {
            $options[self::id($row['gibbonFamilyID'])] = $row['name'] . ' (' . self::id($row['gibbonFamilyID']) . ')';
        }
        return $options;
    }

    public function getFamily($gibbonFamilyID)
    {
        return $this->select("SELECT * FROM gibbonFamily WHERE gibbonFamilyID=:id", ['id' => self::id($gibbonFamilyID)])->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array[] the SEPA records of the family (normally zero or one) */
    public function getSepaRows($gibbonFamilyID)
    {
        return $this->select("SELECT * FROM gibbonSEPA WHERE gibbonFamilyID=:id ORDER BY gibbonSEPAID", ['id' => self::id($gibbonFamilyID)])->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Number of payments / adjustments / snapshots linked to a SEPA. */
    public function getSepaLinkCounts($gibbonSEPAID)
    {
        $id = ['id' => self::id($gibbonSEPAID)];
        return [
            'payments' => (int) $this->select("SELECT COUNT(*) FROM gibbonSEPAPaymentEntry WHERE gibbonSEPAID=:id", $id)->fetchColumn(),
            'adjustments' => (int) $this->select("SELECT COUNT(*) FROM gibbonSEPAPaymentAdjustment WHERE gibbonSEPAID=:id", $id)->fetchColumn(),
            'snapshots' => (int) $this->select("SELECT COUNT(*) FROM gibbonSEPABalanceSnapshot WHERE gibbonSEPAID=:id", $id)->fetchColumn(),
        ];
    }

    /** @param string $role 'Adult' or 'Child' */
    public function getMembers($gibbonFamilyID, $role)
    {
        $table = $role === 'Adult' ? 'gibbonFamilyAdult' : 'gibbonFamilyChild';
        $order = $role === 'Adult' ? 'f.contactPriority, p.surname, p.preferredName' : 'p.surname, p.preferredName';
        $sql = "SELECT p.gibbonPersonID, p.title, p.preferredName, p.surname, p.email, p.status, p.dob
                FROM $table AS f INNER JOIN gibbonPerson AS p ON p.gibbonPersonID=f.gibbonPersonID
                WHERE f.gibbonFamilyID=:id ORDER BY $order";
        return $this->select($sql, ['id' => self::id($gibbonFamilyID)])->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPerson($gibbonPersonID)
    {
        return $this->select("SELECT * FROM gibbonPerson WHERE gibbonPersonID=:id", ['id' => self::id($gibbonPersonID)])->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** The fields of two persons that can be chosen and differ: [field => [targetValue, sourceValue]] */
    public function getPersonDifferences(array $target, array $source)
    {
        $differences = [];
        foreach (self::PERSON_FIELDS as $field) {
            if (!array_key_exists($field, $target) || !array_key_exists($field, $source)) {
                continue;
            }
            if ((string) $target[$field] !== (string) $source[$field]) {
                $differences[$field] = [$target[$field], $source[$field]];
            }
        }
        return $differences;
    }

    // ------------------------------------------------------------------ plan

    /**
     * Build and check the merge plan from the posted values.
     *
     * @return array [plan, errors]
     */
    public function buildPlan(array $post)
    {
        $errors = [];
        $keepID = self::id($post['keepFamilyID'] ?? 0);
        $dropID = self::id($post['dropFamilyID'] ?? 0);

        $plan = [
            'keepFamilyID' => $keepID,
            'dropFamilyID' => $dropID,
            'targetAction' => [],
            'sourceAction' => [],
            'mergeInto' => [],
            'familyFields' => [],
            'sepaFields' => [],
            'personFields' => [],
            'moveRelated' => !empty($post['moveRelated']),
        ];

        if ($keepID <= 0 || $dropID <= 0) {
            $errors[] = __('Choose the two families.');
            return [$plan, $errors];
        }
        if ($keepID === $dropID) {
            $errors[] = __('A family can not be merged into itself.');
            return [$plan, $errors];
        }
        if (!$this->getFamily($keepID) || !$this->getFamily($dropID)) {
            $errors[] = __('The specified record cannot be found.');
            return [$plan, $errors];
        }

        // A family can hold only one SEPA in this module; stop when the data is not like that.
        foreach ([$keepID, $dropID] as $familyID) {
            if (count($this->getSepaRows($familyID)) > 1) {
                $errors[] = sprintf(__('Family %s has more than one SEPA. Fix this first.'), $familyID);
            }
        }

        // Members of both families, by role
        $targetMembers = [];
        $sourceMembers = [];
        foreach (['Adult', 'Child'] as $role) {
            foreach ($this->getMembers($keepID, $role) as $person) {
                $targetMembers[self::id($person['gibbonPersonID'])] = $role;
            }
            foreach ($this->getMembers($dropID, $role) as $person) {
                $sourceMembers[self::id($person['gibbonPersonID'])] = $role;
            }
        }

        // Target family members: keep (default) or remove from the family
        foreach ($targetMembers as $personID => $role) {
            $action = $post['targetAction'][$personID] ?? 'keep';
            $plan['targetAction'][$personID] = $action === 'remove' ? 'remove' : 'keep';
        }

        // Source family members: move (default), remove from the family or merge into a target member
        $mergedTargets = [];
        foreach ($sourceMembers as $personID => $role) {
            $action = (string) ($post['sourceAction'][$personID] ?? 'move');
            if ($action === 'remove') {
                $plan['sourceAction'][$personID] = 'remove';
            } elseif (strpos($action, 'merge:') === 0) {
                $intoID = self::id(substr($action, 6));
                if (!isset($targetMembers[$intoID]) || $targetMembers[$intoID] !== $role) {
                    $errors[] = sprintf(__('User %s can only be merged with a user of the same type (adult / child) of the family to keep.'), $personID);
                    continue;
                }
                if (isset($mergedTargets[$intoID])) {
                    $errors[] = sprintf(__('User %s is merged with more than one user.'), $intoID);
                    continue;
                }
                if (($plan['targetAction'][$intoID] ?? 'keep') === 'remove') {
                    $errors[] = sprintf(__('User %s is removed from the family and can not be merged.'), $intoID);
                    continue;
                }
                $mergedTargets[$intoID] = $personID;
                $plan['sourceAction'][$personID] = 'merge';
                $plan['mergeInto'][$personID] = $intoID;
            } else {
                $plan['sourceAction'][$personID] = 'move';
            }
        }

        // Field choices: 'target' (family to keep) or 'source' (family merged in)
        $choose = function ($value) {
            return $value === 'source' ? 'source' : 'target';
        };
        foreach (self::FAMILY_FIELDS as $field) {
            $plan['familyFields'][$field] = $choose($post['familyFields'][$field] ?? 'target');
        }
        foreach (self::SEPA_FIELDS as $field) {
            $plan['sepaFields'][$field] = $choose($post['sepaFields'][$field] ?? 'target');
        }
        foreach ($plan['mergeInto'] as $sourceID => $targetID) {
            foreach (self::PERSON_FIELDS as $field) {
                $plan['personFields'][$sourceID][$field] = $choose($post['personFields'][$sourceID][$field] ?? 'target');
            }
        }

        return [$plan, $errors];
    }

    // ------------------------------------------------------------------ execution

    /**
     * Execute a plan from buildPlan() in one transaction.
     *
     * @return string[] what has been done, for the user
     * @throws \Exception the whole merge is rolled back
     */
    public function merge(array $plan)
    {
        $report = [];
        $this->pdo->beginTransaction();
        try {
            $keepID = $plan['keepFamilyID'];
            $dropID = $plan['dropFamilyID'];

            $report = array_merge($report, $this->mergeFamilyData($plan));
            $report = array_merge($report, $this->mergeSepa($plan));

            // Users registered twice
            foreach ($plan['mergeInto'] as $sourceID => $targetID) {
                $report = array_merge($report, $this->mergePersons($plan, $sourceID, $targetID));
            }

            $report = array_merge($report, $this->moveMembers($plan));
            $report = array_merge($report, $this->moveRelationships($keepID, $dropID));

            $this->pdo->commit();
        } catch (\Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $report;
    }

    private function mergeFamilyData(array $plan)
    {
        $keep = $this->getFamily($plan['keepFamilyID']);
        $drop = $this->getFamily($plan['dropFamilyID']);
        $set = [];
        foreach (self::FAMILY_FIELDS as $field) {
            if (!array_key_exists($field, $keep) || ($plan['familyFields'][$field] ?? 'target') !== 'source') {
                continue;
            }
            if ((string) $keep[$field] !== (string) $drop[$field]) {
                $set[$field] = $drop[$field];
            }
        }
        if (empty($set)) {
            return [];
        }
        $this->update('gibbonFamily', 'gibbonFamilyID', $plan['keepFamilyID'], $set);
        return [sprintf(__('Family data taken over from the other family: %s'), implode(', ', array_keys($set)))];
    }

    private function mergeSepa(array $plan)
    {
        $keepSepa = $this->getSepaRows($plan['keepFamilyID'])[0] ?? null;
        $dropSepa = $this->getSepaRows($plan['dropFamilyID'])[0] ?? null;
        if (!$dropSepa) {
            return [];
        }

        $dropSepaID = self::id($dropSepa['gibbonSEPAID']);
        $dropFamilyID = $plan['dropFamilyID'];
        $keepFamilyID = $plan['keepFamilyID'];

        // Only the dropped family has a SEPA: it just moves over
        if (!$keepSepa) {
            $this->select("UPDATE gibbonSEPA SET gibbonFamilyID=:keep WHERE gibbonSEPAID=:id", ['keep' => $keepFamilyID, 'id' => $dropSepaID]);
            $this->select("UPDATE IGNORE gibbonSEPABalanceSnapshot SET gibbonFamilyID=:keep WHERE gibbonSEPAID=:id", ['keep' => $keepFamilyID, 'id' => $dropSepaID]);
            return [__('The SEPA of the other family now belongs to the family to keep.')];
        }

        $keepSepaID = self::id($keepSepa['gibbonSEPAID']);
        $set = [];
        foreach (self::SEPA_FIELDS as $field) {
            if (($plan['sepaFields'][$field] ?? 'target') !== 'source') {
                continue;
            }
            // The IBAN is copied as it is stored (encrypted), it is never decrypted here
            if ((string) $keepSepa[$field] !== (string) $dropSepa[$field]) {
                $set[$field] = $dropSepa[$field];
            }
        }
        if (!empty($set)) {
            $this->update('gibbonSEPA', 'gibbonSEPAID', $keepSepaID, $set);
        }

        $counts = $this->moveSepaRecords($dropSepaID, $keepSepaID, $keepFamilyID);

        return [sprintf(
            __('SEPA merged: %d payments, %d adjustments and %d snapshots moved to the SEPA of the family to keep, the other SEPA is deleted.'),
            $counts['payments'],
            $counts['adjustments'],
            $counts['snapshots']
        )];
    }

    /**
     * Move the payments, adjustments and snapshots of one SEPA to another and delete the first SEPA.
     *
     * @return int[] the numbers that were linked to the deleted SEPA (payments, adjustments, snapshots)
     */
    private function moveSepaRecords($dropSepaID, $keepSepaID, $keepFamilyID)
    {
        $counts = $this->getSepaLinkCounts($dropSepaID);
        $this->select("UPDATE gibbonSEPAPaymentEntry SET gibbonSEPAID=:keep WHERE gibbonSEPAID=:drop", ['keep' => $keepSepaID, 'drop' => $dropSepaID]);
        $this->select("UPDATE gibbonSEPAPaymentAdjustment SET gibbonSEPAID=:keep WHERE gibbonSEPAID=:drop", ['keep' => $keepSepaID, 'drop' => $dropSepaID]);
        // A snapshot of the same date and year can exist for both families; the other one is kept as history without SEPA
        $this->select("UPDATE IGNORE gibbonSEPABalanceSnapshot SET gibbonSEPAID=:keep, gibbonFamilyID=:family WHERE gibbonSEPAID=:drop", ['keep' => $keepSepaID, 'family' => $keepFamilyID, 'drop' => $dropSepaID]);
        $this->select("UPDATE gibbonSEPABalanceSnapshot SET gibbonSEPAID=NULL WHERE gibbonSEPAID=:drop", ['drop' => $dropSepaID]);
        $this->select("DELETE FROM gibbonSEPA WHERE gibbonSEPAID=:drop", ['drop' => $dropSepaID]);
        return $counts;
    }

    public function getSepa($gibbonSEPAID)
    {
        return $this->select("SELECT gibbonSEPA.*, gibbonFamily.name AS familyName FROM gibbonSEPA LEFT JOIN gibbonFamily ON gibbonFamily.gibbonFamilyID=gibbonSEPA.gibbonFamilyID WHERE gibbonSEPAID=:id", ['id' => self::id($gibbonSEPAID)])->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Merge a SEPA record into another one (stand-alone, e.g. a SEPA whose family was deleted):
     * the chosen data is written to the SEPA to keep, its payments, adjustments and snapshots
     * move over and the other SEPA is deleted.
     *
     * @param array $fields field => 'target' (keep) | 'source' (take from the SEPA that is merged away)
     * @return string[] what has been done
     */
    public function mergeSepaRecords($dropSepaID, $keepSepaID, array $fields)
    {
        $drop = $this->getSepa($dropSepaID);
        $keep = $this->getSepa($keepSepaID);
        if (!$drop || !$keep) {
            throw new \RuntimeException(__('The specified record cannot be found.'));
        }
        if (self::id($dropSepaID) === self::id($keepSepaID)) {
            throw new \RuntimeException(__('A SEPA can not be merged into itself.'));
        }
        if ($keep['familyName'] === null) {
            throw new \RuntimeException(__('The SEPA to keep must belong to an existing family.'));
        }

        $this->pdo->beginTransaction();
        try {
            $set = [];
            foreach (self::SEPA_FIELDS as $field) {
                if (($fields[$field] ?? 'target') === 'source' && (string) $keep[$field] !== (string) $drop[$field]) {
                    $set[$field] = $drop[$field];
                }
            }
            if (!empty($set)) {
                $this->update('gibbonSEPA', 'gibbonSEPAID', $keepSepaID, $set);
            }
            $counts = $this->moveSepaRecords(self::id($dropSepaID), self::id($keepSepaID), self::id($keep['gibbonFamilyID']));
            $this->pdo->commit();
        } catch (\Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return [sprintf(
            __('SEPA %s merged into SEPA %s (%s): %d payments, %d adjustments and %d snapshots moved, the first SEPA is deleted.'),
            self::id($dropSepaID),
            self::id($keepSepaID),
            $keep['familyName'],
            $counts['payments'],
            $counts['adjustments'],
            $counts['snapshots']
        )];
    }

    private function mergePersons(array $plan, $sourceID, $targetID)
    {
        $report = [];
        $target = $this->getPerson($targetID);
        $source = $this->getPerson($sourceID);
        if (!$target || !$source) {
            throw new \RuntimeException(__('The specified record cannot be found.'));
        }
        $name = trim($target['preferredName'] . ' ' . $target['surname']);

        // Chosen data of the user that is merged away
        $set = [];
        foreach ($this->getPersonDifferences($target, $source) as $field => $values) {
            if (($plan['personFields'][$sourceID][$field] ?? 'target') === 'source') {
                $set[$field] = $values[1];
            }
        }
        if (!empty($set)) {
            $this->update('gibbonPerson', 'gibbonPersonID', $targetID, $set);
        }

        // Family relationships of the merged user
        foreach (['gibbonPersonID1', 'gibbonPersonID2'] as $column) {
            $this->select("UPDATE gibbonFamilyRelationship SET $column=:keep WHERE $column=:drop", ['keep' => $targetID, 'drop' => $sourceID]);
        }
        // A user can not be his own relative
        $this->select("DELETE FROM gibbonFamilyRelationship WHERE gibbonPersonID1=:id AND gibbonPersonID2=:id2", ['id' => $targetID, 'id2' => $targetID]);

        $report[] = sprintf(__('Users merged into %s (data taken over: %s).'), $name, empty($set) ? '-' : implode(', ', array_keys($set)));

        if (!empty($plan['moveRelated'])) {
            $leftOver = $this->repointPerson($sourceID, $targetID);
            // The old user is not deleted, but can not be used any more
            $this->select("UPDATE gibbonPerson SET status='Left', canLogin='N' WHERE gibbonPersonID=:id", ['id' => $sourceID]);
            $report[] = sprintf(__('All records of user %s (enrolments, attendance, ...) now belong to %s; the old user is set to Left and can not log in.'), $sourceID, $name);
            foreach ($leftOver as $table => $count) {
                $report[] = sprintf(__('Warning: %d record(s) in %s stay with the old user %s, they would duplicate existing records of %s.'), $count, $table, $sourceID, $name);
            }
        } else {
            $report[] = sprintf(__('The other records of user %s were not moved; the old user is still active.'), $sourceID);
        }

        return $report;
    }

    /**
     * Move every record that belongs to a person to another person.
     * Rows that would break a unique key (the same record exists already) stay and are reported.
     *
     * @return int[] table => number of rows that stayed with the old person
     */
    private function repointPerson($fromID, $toID)
    {
        $tables = $this->select(
            "SELECT c.TABLE_NAME FROM information_schema.COLUMNS AS c
             INNER JOIN information_schema.TABLES AS t ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME
             WHERE c.TABLE_SCHEMA=DATABASE() AND c.COLUMN_NAME='gibbonPersonID' AND t.TABLE_TYPE='BASE TABLE' AND c.TABLE_NAME LIKE 'gibbon%'
             ORDER BY c.TABLE_NAME"
        )->fetchAll(PDO::FETCH_COLUMN);

        $leftOver = [];
        foreach ($tables as $table) {
            if (in_array($table, self::HANDLED_PERSON_TABLES, true) || !preg_match('/^[A-Za-z0-9_]+$/', $table)) {
                continue;
            }
            $this->select("UPDATE IGNORE `$table` SET gibbonPersonID=:to WHERE gibbonPersonID=:from", ['to' => $toID, 'from' => $fromID]);
            $remaining = (int) $this->select("SELECT COUNT(*) FROM `$table` WHERE gibbonPersonID=:from", ['from' => $fromID])->fetchColumn();
            if ($remaining > 0) {
                $leftOver[$table] = $remaining;
            }
        }
        return $leftOver;
    }

    private function moveMembers(array $plan)
    {
        $keepID = $plan['keepFamilyID'];
        $dropID = $plan['dropFamilyID'];
        $moved = 0;
        $removed = 0;

        // Family to keep: remove the members that are dropped
        foreach ($plan['targetAction'] as $personID => $action) {
            if ($action === 'remove') {
                $this->deleteMember('gibbonFamilyAdult', $keepID, $personID);
                $this->deleteMember('gibbonFamilyChild', $keepID, $personID);
                $removed++;
            }
        }

        // Merged family: move, remove or (merged users) just leave the family
        foreach ($plan['sourceAction'] as $personID => $action) {
            if ($action === 'move') {
                foreach (['gibbonFamilyAdult', 'gibbonFamilyChild'] as $table) {
                    $this->select("UPDATE IGNORE $table SET gibbonFamilyID=:keep WHERE gibbonFamilyID=:drop AND gibbonPersonID=:person", ['keep' => $keepID, 'drop' => $dropID, 'person' => $personID]);
                }
                $moved++;
            } elseif ($action === 'remove') {
                $removed++;
            }
            // Whatever is left of the person in the merged family is removed (moved rows are gone already)
            $this->deleteMember('gibbonFamilyAdult', $dropID, $personID);
            $this->deleteMember('gibbonFamilyChild', $dropID, $personID);
        }

        return [sprintf(__('%d user(s) moved to the family to keep, %d removed from a family.'), $moved, $removed)];
    }

    private function deleteMember($table, $familyID, $personID)
    {
        $this->select("DELETE FROM $table WHERE gibbonFamilyID=:family AND gibbonPersonID=:person", ['family' => $familyID, 'person' => $personID]);
    }

    /**
     * Relationships of the merged family move over when both persons are in the family to keep now;
     * relationships that mention someone who left a family are deleted.
     */
    private function moveRelationships($keepID, $dropID)
    {
        $members = [];
        foreach (['gibbonFamilyAdult', 'gibbonFamilyChild'] as $table) {
            foreach ($this->select("SELECT gibbonPersonID FROM $table WHERE gibbonFamilyID=:id", ['id' => $keepID])->fetchAll(PDO::FETCH_COLUMN) as $personID) {
                $members[self::id($personID)] = true;
            }
        }

        // Relationships of the family to keep that mention someone who is not a member any more
        $rows = $this->select("SELECT * FROM gibbonFamilyRelationship WHERE gibbonFamilyID=:id", ['id' => $keepID])->fetchAll(PDO::FETCH_ASSOC);
        $seen = [];
        foreach ($rows as $row) {
            $a = self::id($row['gibbonPersonID1']);
            $b = self::id($row['gibbonPersonID2']);
            if (!isset($members[$a]) || !isset($members[$b])) {
                $this->select("DELETE FROM gibbonFamilyRelationship WHERE gibbonFamilyRelationshipID=:id", ['id' => $row['gibbonFamilyRelationshipID']]);
            } else {
                $seen[$a . '-' . $b] = true;
            }
        }

        // Relationships of the merged family
        $rows = $this->select("SELECT * FROM gibbonFamilyRelationship WHERE gibbonFamilyID=:id", ['id' => $dropID])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $a = self::id($row['gibbonPersonID1']);
            $b = self::id($row['gibbonPersonID2']);
            $key = $a . '-' . $b;
            if (isset($members[$a]) && isset($members[$b]) && !isset($seen[$key])) {
                $this->select("UPDATE gibbonFamilyRelationship SET gibbonFamilyID=:keep WHERE gibbonFamilyRelationshipID=:id", ['keep' => $keepID, 'id' => $row['gibbonFamilyRelationshipID']]);
                $seen[$key] = true;
            } else {
                $this->select("DELETE FROM gibbonFamilyRelationship WHERE gibbonFamilyRelationshipID=:id", ['id' => $row['gibbonFamilyRelationshipID']]);
            }
        }

        return [];
    }

    /** UPDATE table SET col=:col... WHERE key=:id, column names come from the whitelists above. */
    private function update($table, $keyColumn, $id, array $values)
    {
        $parts = [];
        $params = ['id' => self::id($id)];
        $i = 0;
        foreach ($values as $column => $value) {
            $parts[] = "`$column`=:v$i";
            $params["v$i"] = $value;
            $i++;
        }
        $this->select("UPDATE `$table` SET " . implode(', ', $parts) . " WHERE `$keyColumn`=:id", $params);
    }
}
