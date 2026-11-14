<?php

namespace Gibbon\Module\Sepa\Domain;

use Gibbon\Domain\Traits\TableAware;
use Gibbon\Domain\QueryableGateway;
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

        $sepa = $this->getSepaRow($gibbonSEPAID);
        $changes = $sepa ? self::describeChanges($sepa, $values) : [];

        $pending = $this->getPendingBySEPAID($gibbonSEPAID);
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
    public function discardPending($gibbonSEPAID, $gibbonPersonID = null)
    {
        $pending = $this->getPendingBySEPAID($gibbonSEPAID);
        $sepa = $pending ? $this->getSepaRow($gibbonSEPAID) : null;
        if ($pending && $sepa && $gibbonPersonID) {
            // what was thrown away
            $this->log($gibbonSEPAID, $pending['gibbonFamilyID'], $pending['gibbonSEPAChangeRequestID'], 'Discarded', $gibbonPersonID, self::describeChanges($sepa, $pending));
        }

        $query = $this
            ->newDelete()
            ->from($this->getTableName())
            ->where("gibbonSEPAID = :gibbonSEPAID AND status = 'Pending'")
            ->bindValue('gibbonSEPAID', $gibbonSEPAID);

        return $this->runDelete($query);
    }

    /**
     * Approve: the entered values replace the SEPA data (the IBAN is copied as stored, encrypted).
     * Only a request that is still pending is handled.
     */
    public function approve($gibbonSEPAChangeRequestID, $gibbonPersonID, $note = '')
    {
        $request = $this->getByID($gibbonSEPAChangeRequestID);
        if (!$request || $request['status'] !== 'Pending') {
            return false;
        }

        // logged before the SEPA changes: what the approval changes
        $sepaBefore = $this->getSepaRow($request['gibbonSEPAID']);
        $this->log($request['gibbonSEPAID'], $request['gibbonFamilyID'], $gibbonSEPAChangeRequestID, 'Approved', $gibbonPersonID, $sepaBefore ? self::describeChanges($sepaBefore, $request) : [], $note);

        $query = $this
            ->newUpdate()
            ->table('gibbonSEPA')
            ->cols([
                'payer' => $request['payer'],
                'IBAN' => $request['IBAN'],
                'BIC' => $request['BIC'],
                'withdrawCadence' => $request['withdrawCadence'],
                'withdrawDay' => $request['withdrawDay'],
                'SEPA_signedDate' => $request['SEPA_signedDate'],
            ])
            ->where('gibbonSEPAID = :gibbonSEPAID')
            ->bindValue('gibbonSEPAID', $request['gibbonSEPAID']);
        $this->runUpdate($query);

        return $this->markReviewed($gibbonSEPAChangeRequestID, 'Approved', $gibbonPersonID, $note);
    }

    public function reject($gibbonSEPAChangeRequestID, $gibbonPersonID, $note = '')
    {
        $request = $this->getByID($gibbonSEPAChangeRequestID);
        if (!$request || $request['status'] !== 'Pending') {
            return false;
        }
        $sepa = $this->getSepaRow($request['gibbonSEPAID']);
        $this->log($request['gibbonSEPAID'], $request['gibbonFamilyID'], $gibbonSEPAChangeRequestID, 'Rejected', $gibbonPersonID, $sepa ? self::describeChanges($sepa, $request) : [], $note);

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
        $new = self::clearValues($entered);
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
     * @return array [changes, note]
     */
    public static function readLogChanges($json)
    {
        $data = json_decode((string) $json, true) ?: [];
        $note = (string) ($data['_note'] ?? '');
        unset($data['_note']);
        if (isset($data['IBAN'])) {
            foreach ($data['IBAN'] as $k => $stored) {
                $clear = IbanCipher::decrypt($stored);
                $data['IBAN'][$k] = $clear === null ? '[' . __('cannot decrypt - check the encryption key') . ']' : IbanCipher::format($clear);
            }
        }
        return [$data, $note];
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
     * The log, newest first.
     *
     * @param int|null $gibbonSEPAID only the log of this SEPA
     */
    public function selectLog($gibbonSEPAID = null, $limit = 200)
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
                $names[] = $row['familyName'] ?? __('(family deleted)');
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
        $entered = self::clearValues($request);
        $sepa = $this->getSepaRow($request['gibbonSEPAID']);
        $current = $sepa ? self::clearValues($sepa) : null;
        $others = $entered['IBAN'] ? $this->getOtherFamiliesWithIban($entered['IBAN'], $request['gibbonSEPAID']) : [];

        return self::validate($entered, $current, $this->getAdultSurnames($request['gibbonFamilyID']), $others);
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
            'SEPA_signedDate' => (string) ($row['SEPA_signedDate'] ?? ''),
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
            $flag('Error', 'decrypt', __('The IBAN can not be decrypted (wrong key or damaged data)'));
        } elseif ($iban === '') {
            $flag('Error', 'ibanMissing', __('IBAN is missing'));
        } elseif (strpos($iban, '*') !== false) {
            $flag('Error', 'ibanMasked', __('Only a masked IBAN is entered, the full IBAN is needed'));
        } else {
            $clean = IbanCipher::normalize($iban);
            if (strlen($clean) <= 4) {
                $flag('Error', 'ibanIncomplete', __('IBAN is incomplete (only the country code or a few characters)'));
            } elseif (!IbanCipher::isValid($clean)) {
                $flag('Error', 'ibanInvalid', __('IBAN is not valid (format or checksum)'));
            } elseif (!empty($otherFamilies)) {
                $flag('Error', 'sharedIban', sprintf(__('The same IBAN is used by: %s'), implode(', ', $otherFamilies)));
            }
        }

        // Account holder
        $payer = trim($new['payer']);
        if ($payer === '') {
            $flag('Error', 'payerMissing', __('Account holder is missing'));
        } else {
            if (preg_match('/\d/', $payer) || mb_strlen($payer) < 4 || strpos($payer, ' ') === false) {
                $flag('Warning', 'payerIncomplete', __('The account holder looks incomplete or wrong (needs first and last name, no digits)'));
            }
            $matches = empty($surnames);
            foreach ($surnames as $surname) {
                if ($surname !== '' && mb_strpos(mb_strtolower($payer), mb_strtolower($surname)) !== false) {
                    $matches = true;
                    break;
                }
            }
            if (!$matches) {
                $flag('Warning', 'payerSurname', __('The account holder does not match the surname of any parent'));
            }
        }

        // BIC is optional within the SEPA area
        $bic = strtoupper(preg_replace('/\s+/', '', $new['BIC']));
        if ($bic !== '' && !preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic)) {
            $flag('Warning', 'bicInvalid', __('The BIC does not look valid (8 or 11 characters)'));
        }

        // Withdrawal data
        if (!in_array($new['withdrawCadence'], SepaGateway::WITHDRAW_CADENCES, true)) {
            $flag('Error', 'cadenceInvalid', __('Withdrawal cadence is not valid'));
        }
        $day = $new['withdrawDay'];
        if ($day === '' || (int) $day < 1 || (int) $day > 31) {
            $flag('Warning', 'dayMissing', __('Preferred withdrawal day is missing'));
        }

        // Date of the SEPA signature: not earlier than the existing date
        $signed = trim((string) ($new['SEPA_signedDate'] ?? ''));
        $signedBefore = trim((string) ($current['SEPA_signedDate'] ?? ''));
        if ($signed !== '') {
            if ($signedBefore !== '' && $signed < $signedBefore) {
                $flag('Error', 'signedEarlier', sprintf(__('The date of the SEPA signature is earlier than the existing date (%s)'), $signedBefore));
            } elseif ($signed > date('Y-m-d')) {
                $flag('Warning', 'signedFuture', __('The date of the SEPA signature is in the future'));
            }
            if ($signedBefore !== '' && $signed > $signedBefore) {
                $flag('Warning', 'signedChanged', __('The date of the SEPA signature is changed'));
            }
        }

        // What differs from the current SEPA deserves attention
        if ($current !== null) {
            if ($iban !== null && $current['IBAN'] !== null && IbanCipher::normalize($iban) !== IbanCipher::normalize($current['IBAN'])) {
                $flag('Warning', 'ibanChanged', __('The IBAN is changed'));
            }
            if (mb_strtolower(trim($new['payer'])) !== mb_strtolower(trim($current['payer']))) {
                $flag('Warning', 'payerChanged', __('The account holder is changed'));
            }
        }

        return $flags;
    }
}
