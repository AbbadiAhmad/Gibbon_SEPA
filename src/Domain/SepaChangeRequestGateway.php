<?php

namespace Gibbon\Module\Sepa\Domain;

require_once __DIR__ . '/../../src/i18n.php';

use Gibbon\Domain\Traits\TableAware;
use Gibbon\Domain\QueryableGateway;
use Gibbon\Domain\QueryCriteria;
use Gibbon\Module\Sepa\Security\IbanCipher;

/**
 * Changes of the SEPA data that families entered themselves. They are only recorded here;
 * the SEPA record changes when the school administration approves the request.
 *
 * The IBAN of a request is stored encrypted, like in gibbonSEPA.
 */
class SepaChangeRequestGateway extends QueryableGateway
{
    use TableAware;

    private static $tableName = 'gibbonSEPAChangeRequest';
    private static $primaryKey = 'gibbonSEPAChangeRequestID';
    private static $searchableColumns = ['payer'];

    /** Log lines and decided requests are kept for this long, only older data can be deleted. */
    const RETENTION = '-2 years';

    /** The SEPA fields a family can change. */
    const FIELDS = ['payer', 'IBAN', 'BIC', 'withdrawCadence', 'withdrawDay', 'SEPA_signedDate'];

    public function getByID($gibbonSEPAChangeRequestID)
    {
        $query = $this
            ->newSelect()
            ->cols(['gibbonSEPAChangeRequest.*', 'gibbonFamily.name AS familyName', "CONCAT(gibbonPerson.preferredName, ' ', gibbonPerson.surname) AS submittedBy"])
            ->from($this->getTableName())
            ->leftJoin('gibbonFamily', 'gibbonFamily.gibbonFamilyID = gibbonSEPAChangeRequest.gibbonFamilyID')
            ->leftJoin('gibbonPerson', 'gibbonPerson.gibbonPersonID = gibbonSEPAChangeRequest.gibbonPersonIDSubmitted')
            ->where('gibbonSEPAChangeRequest.gibbonSEPAChangeRequestID = :id')
            ->bindValue('id', $gibbonSEPAChangeRequestID);

        return $this->runSelect($query)->fetch() ?: null;
    }

    /**
     * The open request of a family: for its SEPA, or (without SEPA) the request for a new SEPA.
     */
    public function getPending($gibbonSEPAID, $gibbonFamilyID)
    {
        if (!empty($gibbonSEPAID) && (int) $gibbonSEPAID > 0) {
            return $this->getPendingBySEPAID($gibbonSEPAID);
        }

        $query = $this
            ->newSelect()
            ->cols(['*'])
            ->from($this->getTableName())
            ->where("gibbonSEPAID IS NULL AND gibbonFamilyID = :gibbonFamilyID AND status = 'Pending'")
            ->bindValue('gibbonFamilyID', $gibbonFamilyID);

        return $this->runSelect($query)->fetch() ?: null;
    }

    /**
     * Defaults of a new SEPA for what the family left empty: withdrawal on day 5, monthly, and the
     * date the family entered the data as date of signature.
     *
     * @param array|null $request the request (its creation date is used), null: today
     */
    public static function newSepaDefaults(?array $request = null)
    {
        $created = !empty($request['timestampSubmitted']) ? substr((string) $request['timestampSubmitted'], 0, 10) : date('Y-m-d');
        return [
            'withdrawCadence' => SepaGateway::DEFAULT_WITHDRAW_CADENCE,
            'withdrawDay' => (string) SepaGateway::DEFAULT_WITHDRAW_DAY,
            'SEPA_signedDate' => $created,
        ];
    }

    /** A SEPA row without data: the "current" data of a family that has no SEPA yet. */
    public static function emptySepa()
    {
        return ['gibbonSEPAID' => null, 'payer' => '', 'IBAN' => null, 'BIC' => '', 'withdrawCadence' => '', 'withdrawDay' => '', 'SEPA_signedDate' => ''];
    }

    /**
     * The SEPA a request is about: its SEPA, or for a request for a new SEPA the SEPA the family
     * got in the meantime. Null when the family (still) has no SEPA.
     */
    public function getTargetSepa(array $request)
    {
        if (!empty($request['gibbonSEPAID']) && (int) $request['gibbonSEPAID'] > 0) {
            return $this->getSepaRow($request['gibbonSEPAID']);
        }

        $query = $this
            ->newSelect()
            ->cols(['*'])
            ->from('gibbonSEPA')
            ->where('gibbonFamilyID = :gibbonFamilyID')
            ->bindValue('gibbonFamilyID', $request['gibbonFamilyID'])
            ->orderBy(['gibbonSEPAID'])
            ->limit(1);

        return $this->runSelect($query)->fetch() ?: null;
    }

    public function getPendingBySEPAID($gibbonSEPAID)
    {
        $query = $this
            ->newSelect()
            ->cols(['*'])
            ->from($this->getTableName())
            ->where("gibbonSEPAID = :gibbonSEPAID AND status = 'Pending'")
            ->bindValue('gibbonSEPAID', $gibbonSEPAID);

        return $this->runSelect($query)->fetch() ?: null;
    }

    /**
     * Requests for the review list, newest first.
     *
     * @param string $status 'Pending', 'Approved', 'Rejected' or '' for all
     */
    public function selectRequests($status = 'Pending')
    {
        $query = $this
            ->newSelect()
            ->cols(['gibbonSEPAChangeRequest.*', 'gibbonFamily.name AS familyName', "CONCAT(gibbonPerson.preferredName, ' ', gibbonPerson.surname) AS submittedBy"])
            ->from($this->getTableName())
            ->leftJoin('gibbonFamily', 'gibbonFamily.gibbonFamilyID = gibbonSEPAChangeRequest.gibbonFamilyID')
            ->leftJoin('gibbonPerson', 'gibbonPerson.gibbonPersonID = gibbonSEPAChangeRequest.gibbonPersonIDSubmitted')
            ->orderBy(['gibbonSEPAChangeRequest.timestampSubmitted DESC']);

        if (in_array($status, ['Pending', 'Approved', 'Rejected'], true)) {
            $query->where('gibbonSEPAChangeRequest.status = :status')->bindValue('status', $status);
        }

        return $this->runSelect($query)->fetchAll();
    }

    /**
     * Record the values a family entered: one open request per SEPA, entering again replaces it.
     *
     * @param array $values payer, IBAN (already encrypted), BIC, withdrawCadence, withdrawDay
     */
    public function savePending($gibbonSEPAID, $gibbonFamilyID, array $values, $gibbonPersonID)
    {
        $now = date('Y-m-d H:i:s');
        $cols = [
            'payer' => $values['payer'],
            'IBAN' => $values['IBAN'],
            'BIC' => $values['BIC'],
            'withdrawCadence' => $values['withdrawCadence'],
            'withdrawDay' => $values['withdrawDay'],
            'SEPA_signedDate' => $values['SEPA_signedDate'],
        ];

        // without SEPA: a request for a new SEPA, everything entered is new
        $gibbonSEPAID = !empty($gibbonSEPAID) && (int) $gibbonSEPAID > 0 ? $gibbonSEPAID : null;
        $sepa = $gibbonSEPAID ? $this->getSepaRow($gibbonSEPAID) : null;
        $changes = self::describeChanges($sepa ?: self::emptySepa(), $values);

        $pending = $this->getPending($gibbonSEPAID, $gibbonFamilyID);
        if ($pending) {
            $query = $this
                ->newUpdate()
                ->table($this->getTableName())
                ->cols($cols + ['gibbonPersonIDSubmitted' => $gibbonPersonID, 'timestampUpdated' => $now])
                ->where('gibbonSEPAChangeRequestID = :id')
                ->bindValue('id', $pending['gibbonSEPAChangeRequestID']);
            $result = $this->runUpdate($query);
            $this->log($gibbonSEPAID, $gibbonFamilyID, $pending['gibbonSEPAChangeRequestID'], 'Updated', $gibbonPersonID, $changes);
            return $result;
        }

        $query = $this
            ->newInsert()
            ->into($this->getTableName())
            ->cols($cols + [
                'gibbonSEPAID' => $gibbonSEPAID,
                'gibbonFamilyID' => $gibbonFamilyID,
                'status' => 'Pending',
                'gibbonPersonIDSubmitted' => $gibbonPersonID,
                'timestampSubmitted' => $now,
            ]);
        $requestID = $this->runInsert($query);
        $this->log($gibbonSEPAID, $gibbonFamilyID, $requestID ?: null, 'Submitted', $gibbonPersonID, $changes);
        return $requestID !== false;
    }

    /** The family discards its open request. */
    public function discardPending($gibbonSEPAID, $gibbonPersonID = null, $gibbonFamilyID = null)
    {
        $pending = $this->getPending($gibbonSEPAID, $gibbonFamilyID);
        if (!$pending) {
            return true;
        }
        if ($gibbonPersonID) {
            // what was thrown away
            $sepa = $this->getTargetSepa($pending) ?: self::emptySepa();
            $this->log($pending['gibbonSEPAID'], $pending['gibbonFamilyID'], $pending['gibbonSEPAChangeRequestID'], 'Discarded', $gibbonPersonID, self::describeChanges($sepa, $pending));
        }

        $query = $this
            ->newDelete()
            ->from($this->getTableName())
            ->where("gibbonSEPAChangeRequestID = :id AND status = 'Pending'")
            ->bindValue('id', $pending['gibbonSEPAChangeRequestID']);

        return $this->runDelete($query);
    }

    /**
     * Approve: the entered values replace the SEPA data (the IBAN is copied as stored, encrypted);
     * for a family without SEPA the SEPA is created. Only a request that is still pending is handled.
     *
     * @return mixed the ID of the SEPA that was changed / created, false when nothing was done
     */
    public function approve($gibbonSEPAChangeRequestID, $gibbonPersonID, $note = '', array $decision = null)
    {
        $request = $this->getByID($gibbonSEPAChangeRequestID);
        if (!$request || $request['status'] !== 'Pending') {
            return false;
        }

        // Without a decision of the reviewer everything the family entered is taken over
        $final = $decision['final'] ?? $request;
        // The SEPA to change; null: the family has no SEPA, it is created now
        $sepaBefore = $this->getTargetSepa($request);
        $sepaID = $sepaBefore['gibbonSEPAID'] ?? null;
        if (empty($final['SEPA_signedDate'])) {
            // an empty date of signature never removes the existing date
            $final['SEPA_signedDate'] = $sepaBefore['SEPA_signedDate'] ?? null;
        }

        // logged before the SEPA changes: what the approval changes, what was not accepted
        // and what the reviewer changed
        $changes = self::describeChanges($sepaBefore ?: self::emptySepa(), $final);
        if (!empty($decision['rejected'])) {
            $changes['_rejected'] = array_values($decision['rejected']);
        }
        if (!empty($decision['edited'])) {
            $changes['_edited'] = array_values($decision['edited']);
        }
        $this->log($sepaID, $request['gibbonFamilyID'], $gibbonSEPAChangeRequestID, 'Approved', $gibbonPersonID, $changes, $note);

        $cols = [
            'payer' => $final['payer'],
            'IBAN' => $final['IBAN'],
            'BIC' => !empty($final['BIC']) ? $final['BIC'] : null,
            'withdrawCadence' => in_array($final['withdrawCadence'], SepaGateway::WITHDRAW_CADENCES, true) ? $final['withdrawCadence'] : 'Monthly',
            'withdrawDay' => !empty($final['withdrawDay']) ? $final['withdrawDay'] : null,
            'SEPA_signedDate' => !empty($final['SEPA_signedDate']) ? $final['SEPA_signedDate'] : null,
        ];

        if ($sepaID) {
            $query = $this
                ->newUpdate()
                ->table('gibbonSEPA')
                ->cols($cols)
                ->where('gibbonSEPAID = :gibbonSEPAID')
                ->bindValue('gibbonSEPAID', $sepaID);
            $this->runUpdate($query);
        } else {
            // New SEPA for the family
            $query = $this
                ->newInsert()
                ->into('gibbonSEPA')
                ->cols($cols + ['gibbonFamilyID' => $request['gibbonFamilyID']]);
            $sepaID = $this->runInsert($query);
            if (!$sepaID) {
                return false;
            }
        }

        // A request for a new SEPA (and its log) now belongs to the SEPA
        if (empty($request['gibbonSEPAID']) || (int) $request['gibbonSEPAID'] === 0) {
            foreach (['gibbonSEPAChangeRequest', 'gibbonSEPAChangeLog'] as $table) {
                $query = $this
                    ->newUpdate()
                    ->table($table)
                    ->cols(['gibbonSEPAID' => $sepaID])
                    ->where('gibbonSEPAChangeRequestID = :id AND gibbonSEPAID IS NULL')
                    ->bindValue('id', $gibbonSEPAChangeRequestID);
                $this->runUpdate($query);
            }
        }

        $this->markReviewed($gibbonSEPAChangeRequestID, 'Approved', $gibbonPersonID, $note);
        return $sepaID;
    }

    public function reject($gibbonSEPAChangeRequestID, $gibbonPersonID, $note = '')
    {
        $request = $this->getByID($gibbonSEPAChangeRequestID);
        if (!$request || $request['status'] !== 'Pending') {
            return false;
        }
        $sepa = $this->getTargetSepa($request);
        $this->log($sepa['gibbonSEPAID'] ?? null, $request['gibbonFamilyID'], $gibbonSEPAChangeRequestID, 'Rejected', $gibbonPersonID, self::describeChanges($sepa ?: self::emptySepa(), $request), $note);

        return $this->markReviewed($gibbonSEPAChangeRequestID, 'Rejected', $gibbonPersonID, $note);
    }

    private function markReviewed($gibbonSEPAChangeRequestID, $status, $gibbonPersonID, $note)
    {
        $query = $this
            ->newUpdate()
            ->table($this->getTableName())
            ->cols([
                'status' => $status,
                'gibbonPersonIDReviewed' => $gibbonPersonID,
                'timestampReviewed' => date('Y-m-d H:i:s'),
                'reviewNote' => $note,
            ])
            ->where("gibbonSEPAChangeRequestID = :id AND status = 'Pending'")
            ->bindValue('id', $gibbonSEPAChangeRequestID);

        return $this->runUpdate($query);
    }

    /**
     * What differs between the current SEPA row and entered values: field => [old, new].
     * IBANs are kept encrypted (as stored), the log never holds a clear IBAN.
     */
    public static function describeChanges(array $sepa, array $entered)
    {
        $old = self::clearValues($sepa);
        $new = self::enteredValues($entered, $sepa);
        $encrypted = function ($stored, $clear) {
            if ($stored === null || $stored === '') {
                return '';
            }
            if (IbanCipher::isEncrypted($stored)) {
                return $stored;
            }
            try {
                return IbanCipher::encrypt($clear) ?? '';
            } catch (\RuntimeException $e) {
                return '?';
            }
        };

        $changes = [];
        foreach (self::FIELDS as $field) {
            $a = $old[$field];
            $b = $new[$field];
            if ($field === 'IBAN') {
                if (IbanCipher::normalize((string) $a) !== IbanCipher::normalize((string) $b)) {
                    $changes[$field] = [$encrypted($sepa['IBAN'] ?? '', $a), $encrypted($entered['IBAN'] ?? '', $b)];
                }
            } elseif ((string) $a !== (string) $b) {
                $changes[$field] = [(string) $a, (string) $b];
            }
        }
        return $changes;
    }

    /**
     * The changes of a log line for display (to the administration only): field => [old, new],
     * the encrypted IBANs are decrypted here.
     *
     * @return array [changes, note, ['rejected' => fields not accepted, 'edited' => fields changed by the reviewer]]
     */
    public static function readLogChanges($json)
    {
        $data = json_decode((string) $json, true) ?: [];
        $note = (string) ($data['_note'] ?? '');
        $extra = ['rejected' => (array) ($data['_rejected'] ?? []), 'edited' => (array) ($data['_edited'] ?? [])];
        unset($data['_note'], $data['_rejected'], $data['_edited']);
        if (isset($data['IBAN'])) {
            foreach ($data['IBAN'] as $k => $stored) {
                $clear = IbanCipher::decrypt($stored);
                $data['IBAN'][$k] = $clear === null ? '[' . __m('cannot decrypt - check the encryption key') . ']' : IbanCipher::format($clear);
            }
        }
        return [$data, $note, $extra];
    }

    /**
     * Write a line to the log: what happened, who did it, when (the database timestamp).
     */
    public function log($gibbonSEPAID, $gibbonFamilyID, $gibbonSEPAChangeRequestID, $event, $gibbonPersonID, array $changes, $note = '')
    {
        if ($note !== '') {
            $changes['_note'] = $note;
        }
        $query = $this
            ->newInsert()
            ->into('gibbonSEPAChangeLog')
            ->cols([
                'gibbonSEPAID' => $gibbonSEPAID,
                'gibbonFamilyID' => $gibbonFamilyID,
                'gibbonSEPAChangeRequestID' => $gibbonSEPAChangeRequestID,
                'event' => $event,
                'gibbonPersonID' => $gibbonPersonID,
                'changes' => json_encode($changes, JSON_UNESCAPED_UNICODE),
            ]);

        return $this->runInsert($query);
    }

    /**
     * The log for the log page (paginated), with filters: event, from / to (Y-m-d), SEPA and a
     * search text for the family name and the person.
     */
    public function queryLog(QueryCriteria $criteria)
    {
        $query = $this
            ->newQuery()
            ->cols(['gibbonSEPAChangeLog.*', 'gibbonFamily.name AS familyName', 'gibbonSEPA.payer', "CONCAT(gibbonPerson.preferredName, ' ', gibbonPerson.surname) AS personName"])
            ->from('gibbonSEPAChangeLog')
            ->leftJoin('gibbonFamily', 'gibbonFamily.gibbonFamilyID = gibbonSEPAChangeLog.gibbonFamilyID')
            ->leftJoin('gibbonSEPA', 'gibbonSEPA.gibbonSEPAID = gibbonSEPAChangeLog.gibbonSEPAID')
            ->leftJoin('gibbonPerson', 'gibbonPerson.gibbonPersonID = gibbonSEPAChangeLog.gibbonPersonID');

        $criteria->addFilterRules([
            'event' => function ($query, $event) {
                return $query->where('gibbonSEPAChangeLog.event = :logEvent')->bindValue('logEvent', $event);
            },
            'from' => function ($query, $from) {
                return $query->where('DATE(gibbonSEPAChangeLog.timestamp) >= :logFrom')->bindValue('logFrom', $from);
            },
            'to' => function ($query, $to) {
                return $query->where('DATE(gibbonSEPAChangeLog.timestamp) <= :logTo')->bindValue('logTo', $to);
            },
            'sepa' => function ($query, $sepa) {
                return $query->where('gibbonSEPAChangeLog.gibbonSEPAID = :logSepa')->bindValue('logSepa', $sepa);
            },
            'search' => function ($query, $search) {
                return $query->where("(gibbonFamily.name LIKE :logSearch OR gibbonSEPA.payer LIKE :logSearch OR CONCAT(gibbonPerson.preferredName, ' ', gibbonPerson.surname) LIKE :logSearch)")
                    ->bindValue('logSearch', '%' . $search . '%');
            },
        ]);

        return $this->runQuery($query, $criteria);
    }

    /** Date before which data may be deleted. */
    public static function retentionCutoff()
    {
        return date('Y-m-d H:i:s', strtotime(self::RETENTION));
    }

    /**
     * Numbers of rows that are old enough to be deleted: log lines, and requests that were decided.
     * Requests that are still waiting for approval are never counted.
     *
     * @return int[] log, requests
     */
    public function countExpired()
    {
        $cutoff = self::retentionCutoff();
        $log = $this->runSelect($this->newSelect()->cols(['COUNT(*) AS total'])->from('gibbonSEPAChangeLog')->where('timestamp < :cutoff')->bindValue('cutoff', $cutoff))->fetch();
        $requests = $this->runSelect($this->newSelect()->cols(['COUNT(*) AS total'])->from($this->getTableName())
            ->where("status <> 'Pending' AND COALESCE(timestampReviewed, timestampUpdated, timestampSubmitted) < :cutoff")->bindValue('cutoff', $cutoff))->fetch();

        return ['log' => (int) ($log['total'] ?? 0), 'requests' => (int) ($requests['total'] ?? 0)];
    }

    /**
     * Delete the log lines and decided requests older than the retention time. The cut-off is set
     * here, nothing newer can be deleted whatever the caller passes.
     *
     * @return int[] deleted: log, requests
     */
    public function purgeExpired()
    {
        $counts = $this->countExpired();
        $cutoff = self::retentionCutoff();

        $this->runDelete($this->newDelete()->from('gibbonSEPAChangeLog')->where('timestamp < :cutoff')->bindValue('cutoff', $cutoff));
        $this->runDelete($this->newDelete()->from($this->getTableName())
            ->where("status <> 'Pending' AND COALESCE(timestampReviewed, timestampUpdated, timestampSubmitted) < :cutoff")->bindValue('cutoff', $cutoff));

        return $counts;
    }

    /**
     * The log, newest first.
     *
     * @param int|null $gibbonSEPAID only the log of this SEPA
     * @param int|null $familyWithoutSepa without SEPA: the log of the requests for a new SEPA of this family
     */
    public function selectLog($gibbonSEPAID = null, $limit = 200, $familyWithoutSepa = null)
    {
        $query = $this
            ->newSelect()
            ->cols(['gibbonSEPAChangeLog.*', 'gibbonFamily.name AS familyName', "CONCAT(gibbonPerson.preferredName, ' ', gibbonPerson.surname) AS personName"])
            ->from('gibbonSEPAChangeLog')
            ->leftJoin('gibbonFamily', 'gibbonFamily.gibbonFamilyID = gibbonSEPAChangeLog.gibbonFamilyID')
            ->leftJoin('gibbonPerson', 'gibbonPerson.gibbonPersonID = gibbonSEPAChangeLog.gibbonPersonID')
            ->orderBy(['gibbonSEPAChangeLog.timestamp DESC', 'gibbonSEPAChangeLog.gibbonSEPAChangeLogID DESC'])
            ->limit((int) $limit);

        if ($gibbonSEPAID) {
            $query->where('gibbonSEPAChangeLog.gibbonSEPAID = :gibbonSEPAID')->bindValue('gibbonSEPAID', $gibbonSEPAID);
        } elseif ($familyWithoutSepa) {
            $query->where('gibbonSEPAChangeLog.gibbonSEPAID IS NULL AND gibbonSEPAChangeLog.gibbonFamilyID = :logFamily')->bindValue('logFamily', $familyWithoutSepa);
        }

        return $this->runSelect($query)->fetchAll();
    }

    /** Only the adults of a family may change its SEPA data. */
    public function isFamilyAdult($gibbonPersonID, $gibbonFamilyID)
    {
        $query = $this
            ->newSelect()
            ->cols(['COUNT(*) AS total'])
            ->from('gibbonFamilyAdult')
            ->where('gibbonPersonID = :gibbonPersonID AND gibbonFamilyID = :gibbonFamilyID')
            ->bindValue('gibbonPersonID', $gibbonPersonID)
            ->bindValue('gibbonFamilyID', $gibbonFamilyID);

        return (int) ($this->runSelect($query)->fetch()['total'] ?? 0) > 0;
    }

    public function getAdultSurnames($gibbonFamilyID)
    {
        $query = $this
            ->newSelect()
            ->cols(['gibbonPerson.surname'])
            ->from('gibbonFamilyAdult')
            ->innerJoin('gibbonPerson', 'gibbonPerson.gibbonPersonID = gibbonFamilyAdult.gibbonPersonID')
            ->where('gibbonFamilyAdult.gibbonFamilyID = :gibbonFamilyID')
            ->bindValue('gibbonFamilyID', $gibbonFamilyID);

        return $this->runSelect($query)->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Names of the other families that use this IBAN (compared decrypted).
     *
     * @return string[]
     */
    public function getOtherFamiliesWithIban($iban, $excludeGibbonSEPAID)
    {
        $clean = IbanCipher::normalize($iban);
        if ($clean === '') {
            return [];
        }

        $query = $this
            ->newSelect()
            ->cols(['gibbonSEPA.gibbonSEPAID', 'gibbonSEPA.IBAN', 'gibbonFamily.name AS familyName'])
            ->from('gibbonSEPA')
            ->leftJoin('gibbonFamily', 'gibbonFamily.gibbonFamilyID = gibbonSEPA.gibbonFamilyID')
            ->where('gibbonSEPA.gibbonSEPAID <> :exclude')
            ->where('gibbonSEPA.IBAN IS NOT NULL')
            ->bindValue('exclude', $excludeGibbonSEPAID);

        $names = [];
        foreach ($this->runSelect($query)->fetchAll() as $row) {
            $other = IbanCipher::decrypt($row['IBAN']);
            if ($other !== null && $other !== '' && IbanCipher::normalize($other) === $clean) {
                $names[] = $row['familyName'] ?? __m('(family deleted)');
            }
        }
        return array_values(array_unique($names));
    }

    public function getSepaRow($gibbonSEPAID)
    {
        $query = $this
            ->newSelect()
            ->cols(['*'])
            ->from('gibbonSEPA')
            ->where('gibbonSEPAID = :gibbonSEPAID')
            ->bindValue('gibbonSEPAID', $gibbonSEPAID);

        return $this->runSelect($query)->fetch() ?: null;
    }

    /**
     * Errors and warnings of a request, compared with the current SEPA data.
     *
     * @return array[] see validate()
     */
    public function getRequestFlags(array $request)
    {
        $sepa = $this->getTargetSepa($request);
        $entered = $sepa ? self::enteredValues($request, $sepa) : self::clearValues($request);
        $current = $sepa ? self::clearValues($sepa) : null;
        $others = $entered['IBAN'] ? $this->getOtherFamiliesWithIban($entered['IBAN'], (int) ($sepa['gibbonSEPAID'] ?? 0)) : [];

        return self::validate($entered, $current, $this->getAdultSurnames($request['gibbonFamilyID']), $others);
    }

    /**
     * True when a stored row (request or SEPA) holds the intended values. Used to verify a save,
     * because a failing query does not always raise an error (e.g. a database that was not updated).
     */
    public static function holdsValues(?array $row, array $intended)
    {
        if (!$row) {
            return false;
        }
        $stored = self::clearValues($row);
        $wanted = self::clearValues($intended);
        foreach (self::FIELDS as $field) {
            $same = $field === 'IBAN'
                ? IbanCipher::normalize((string) $stored[$field]) === IbanCipher::normalize((string) $wanted[$field])
                : (string) $stored[$field] === (string) $wanted[$field];
            if (!$same) {
                return false;
            }
        }
        return true;
    }

    /**
     * Clear values the family entered. A request without a date of signature (the family left it
     * empty, or the request is older than that field) means the date is not changed.
     */
    public static function enteredValues(array $request, array $sepa)
    {
        $entered = self::clearValues($request);
        if ($entered['SEPA_signedDate'] === '') {
            $entered['SEPA_signedDate'] = self::clearValues($sepa)['SEPA_signedDate'];
        }
        return $entered;
    }

    /**
     * User input of a date field as Y-m-d ('' when empty or not a date). The field delivers
     * Y-m-d or the date format of the installation, depending on the Gibbon version.
     */
    public static function inputDate($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $value = (string) \Gibbon\Services\Format::dateConvert($value);
        }
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }

    /**
     * Which fields are accepted by default on the approval page: only the ones the family
     * really changed to a value that is not empty.
     *
     * @return bool[] field => accepted
     */
    public static function defaultAccept(array $entered, array $current)
    {
        $accept = [];
        foreach (self::FIELDS as $field) {
            $new = (string) $entered[$field];
            $old = (string) $current[$field];
            $changed = $field === 'IBAN' ? IbanCipher::normalize($new) !== IbanCipher::normalize($old) : $new !== $old;
            $accept[$field] = $changed && trim($new) !== '';
        }
        return $accept;
    }

    /**
     * The values the reviewer posted, cleaned (clear IBAN). A missing or invalid value falls back
     * to what the family entered.
     *
     * @param array $posted field => value
     * @param array $entered clear values of the request
     */
    public static function cleanReviewValues(array $posted, array $entered)
    {
        $get = function ($field) use ($posted, $entered) {
            return array_key_exists($field, $posted) ? (string) $posted[$field] : (string) $entered[$field];
        };
        $day = $get('withdrawDay');
        $date = trim($get('SEPA_signedDate'));

        return [
            'payer' => trim(preg_replace('/\s+/', ' ', $get('payer'))),
            'IBAN' => IbanCipher::normalize($get('IBAN')),
            'BIC' => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $get('BIC'))),
            'withdrawCadence' => in_array($get('withdrawCadence'), SepaGateway::WITHDRAW_CADENCES, true) ? $get('withdrawCadence') : (string) $entered['withdrawCadence'],
            'withdrawDay' => ((int) $day >= 1 && (int) $day <= 31) ? (string) (int) $day : '',
            'SEPA_signedDate' => self::inputDate($date),
        ];
    }

    /**
     * The decision of the reviewer per field: an accepted field takes the (possibly corrected)
     * value, a field that is not accepted keeps the current SEPA value.
     *
     * @param array $request the request row (stored values)
     * @param array $sepa the current SEPA row (stored values)
     * @param array $accept field => truthy when the field is accepted
     * @param array $values clear values from cleanReviewValues()
     * @return array ['final' => values as stored (IBAN encrypted), 'finalClear' => clear values,
     *                'rejected' => fields of the family that are not accepted, 'edited' => fields the reviewer changed]
     * @throws \RuntimeException when a new IBAN can not be encrypted
     */
    public static function buildDecision(array $request, array $sepa, array $accept, array $values)
    {
        $entered = self::enteredValues($request, $sepa);
        $current = self::clearValues($sepa);
        $same = function ($field, $a, $b) {
            return $field === 'IBAN'
                ? IbanCipher::normalize((string) $a) === IbanCipher::normalize((string) $b)
                : (string) $a === (string) $b;
        };

        $final = [];
        $finalClear = [];
        $rejected = [];
        $edited = [];
        foreach (self::FIELDS as $field) {
            if (empty($accept[$field])) {
                // keep the current value
                $final[$field] = $sepa[$field] ?? null;
                $finalClear[$field] = $current[$field];
                if (!$same($field, $entered[$field], $current[$field])) {
                    $rejected[] = $field;
                }
                continue;
            }

            $value = $values[$field];
            $finalClear[$field] = $value;
            if (!$same($field, $value, $entered[$field])) {
                $edited[] = $field;
            }
            if ($field === 'IBAN') {
                // stays encrypted: reuse the stored value when the IBAN is the entered or the current one
                if ($same($field, $value, $entered[$field])) {
                    $final[$field] = $request['IBAN'];
                } elseif ($same($field, $value, $current[$field])) {
                    $final[$field] = $sepa['IBAN'];
                } else {
                    $final[$field] = $value === '' ? null : IbanCipher::encrypt($value);
                }
            } else {
                $final[$field] = $value;
            }
        }

        return ['final' => $final, 'finalClear' => $finalClear, 'rejected' => $rejected, 'edited' => $edited];
    }

    /**
     * Errors and warnings of clear values for the SEPA of a request (used for the reviewer's values).
     */
    public function getFlagsForValues(array $clearValues, array $request)
    {
        $sepa = $this->getTargetSepa($request);
        $others = !empty($clearValues['IBAN']) ? $this->getOtherFamiliesWithIban($clearValues['IBAN'], (int) ($sepa['gibbonSEPAID'] ?? 0)) : [];

        return self::validate($clearValues, $sepa ? self::clearValues($sepa) : null, $this->getAdultSurnames($request['gibbonFamilyID']), $others);
    }

    /**
     * The values of a request or SEPA row with the clear IBAN (null when it can not be decrypted).
     */
    public static function clearValues(array $row)
    {
        return [
            'payer' => trim((string) ($row['payer'] ?? '')),
            'IBAN' => IbanCipher::decrypt($row['IBAN'] ?? ''),
            'BIC' => (string) ($row['BIC'] ?? ''),
            'withdrawCadence' => (string) ($row['withdrawCadence'] ?? ''),
            'withdrawDay' => (string) ($row['withdrawDay'] ?? ''),
            // MySQL zero dates are "no date"
            'SEPA_signedDate' => strpos((string) ($row['SEPA_signedDate'] ?? ''), '0000') === 0 ? '' : (string) ($row['SEPA_signedDate'] ?? ''),
        ];
    }

    /**
     * Errors and warnings of entered SEPA values. Pure function, no database access.
     *
     * @param array $new clear values (see clearValues())
     * @param array|null $current clear values of the current SEPA, to flag what changed
     * @param string[] $surnames surnames of the adults of the family
     * @param string[] $otherFamilies names of other families that use the same IBAN
     * @return array[] list of ['severity' => 'Error'|'Warning', 'code' => string, 'text' => string]
     */
    public static function validate(array $new, ?array $current, array $surnames, array $otherFamilies)
    {
        $flags = [];
        $flag = function ($severity, $code, $text) use (&$flags) {
            $flags[] = ['severity' => $severity, 'code' => $code, 'text' => $text];
        };

        // IBAN
        $iban = $new['IBAN'];
        if ($iban === null) {
            $flag('Error', 'decrypt', __m('The IBAN can not be decrypted (wrong key or damaged data)'));
        } elseif ($iban === '') {
            $flag('Error', 'ibanMissing', __m('IBAN is missing'));
        } elseif (strpos($iban, '*') !== false) {
            $flag('Error', 'ibanMasked', __m('Only a masked IBAN is entered, the full IBAN is needed'));
        } else {
            $clean = IbanCipher::normalize($iban);
            if (strlen($clean) <= 4) {
                $flag('Error', 'ibanIncomplete', __m('IBAN is incomplete (only the country code or a few characters)'));
            } elseif (!IbanCipher::isValid($clean)) {
                $flag('Error', 'ibanInvalid', __m('IBAN is not valid (format or checksum)'));
            } elseif (!empty($otherFamilies)) {
                $flag('Error', 'sharedIban', sprintf(__m('The same IBAN is used by: %s'), implode(', ', $otherFamilies)));
            }
        }

        // Account holder
        $payer = trim($new['payer']);
        if ($payer === '') {
            $flag('Error', 'payerMissing', __m('Account holder is missing'));
        } else {
            if (preg_match('/\d/', $payer) || mb_strlen($payer) < 4 || strpos($payer, ' ') === false) {
                $flag('Warning', 'payerIncomplete', __m('The account holder looks incomplete or wrong (needs first and last name, no digits)'));
            }
            $matches = empty($surnames);
            foreach ($surnames as $surname) {
                if ($surname !== '' && mb_strpos(mb_strtolower($payer), mb_strtolower($surname)) !== false) {
                    $matches = true;
                    break;
                }
            }
            if (!$matches) {
                $flag('Warning', 'payerSurname', __m('The account holder does not match the surname of any parent'));
            }
        }

        // BIC is optional within the SEPA area
        $bic = strtoupper(preg_replace('/\s+/', '', $new['BIC']));
        if ($bic !== '' && !preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic)) {
            $flag('Warning', 'bicInvalid', __m('The BIC does not look valid (8 or 11 characters)'));
        }

        // Withdrawal data
        if (!in_array($new['withdrawCadence'], SepaGateway::WITHDRAW_CADENCES, true)) {
            $flag('Error', 'cadenceInvalid', __m('Withdrawal cadence is not valid'));
        }
        $day = $new['withdrawDay'];
        if ($day === '' || (int) $day < 1 || (int) $day > 31) {
            $flag('Warning', 'dayMissing', __m('Preferred withdrawal day is missing'));
        }

        // Date of the SEPA signature: not earlier than the existing date
        $signed = trim((string) ($new['SEPA_signedDate'] ?? ''));
        $signedBefore = trim((string) ($current['SEPA_signedDate'] ?? ''));
        if ($signed === '') {
            $flag('Warning', 'signedMissing', __m('Date of SEPA signature is missing'));
        }
        if ($signed !== '') {
            if ($signedBefore !== '' && $signed < $signedBefore) {
                $flag('Error', 'signedEarlier', sprintf(__m('The date of the SEPA signature is earlier than the existing date (%s)'), $signedBefore));
            } elseif ($signed > date('Y-m-d')) {
                $flag('Warning', 'signedFuture', __m('The date of the SEPA signature is in the future'));
            }
            if ($signedBefore !== '' && $signed > $signedBefore) {
                $flag('Warning', 'signedChanged', __m('The date of the SEPA signature is changed'));
            }
        }

        // What differs from the current SEPA deserves attention
        if ($current === null) {
            $flag('Warning', 'newSepa', __m('New SEPA: the family has no SEPA yet, it is created on approval'));
        }
        if ($current !== null) {
            if ($iban !== null && $current['IBAN'] !== null && IbanCipher::normalize($iban) !== IbanCipher::normalize($current['IBAN'])) {
                $flag('Warning', 'ibanChanged', __m('The IBAN is changed'));
            }
            if (mb_strtolower(trim($new['payer'])) !== mb_strtolower(trim($current['payer']))) {
                $flag('Warning', 'payerChanged', __m('The account holder is changed'));
            }
        }

        return $flags;
    }
}
