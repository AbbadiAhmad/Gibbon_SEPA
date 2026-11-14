<?php

namespace Gibbon\Module\Sepa\Domain;

require_once __DIR__ . '/../i18n.php';

/**
 * Bulk payment adjustments (discount) for the families that match filters.
 *
 * Pure functions, no database access: the wizard page reads the rows (SepaGateway::getBulkAdjustmentRows())
 * and uses this class to read the settings and filters, to select the families and to calculate the amounts.
 *
 * Balance = payments + adjustments - fees. A family that owes money has a negative balance; an adjustment is
 * a positive amount that reduces the debt.
 */
class BulkAdjustment
{
    const MODES = ['percent', 'fixed'];
    const BASES = ['owed', 'fees'];
    const MAX_AMOUNT = 1000000;

    /**
     * Step 1: what is entered and for which academic year.
     *
     * @return array [settings, errors]
     */
    public static function parseSettings(array $post)
    {
        $errors = [];
        $year = (string) ($post['academicYear'] ?? '');
        $mode = in_array($post['mode'] ?? '', self::MODES, true) ? $post['mode'] : 'percent';
        $base = in_array($post['base'] ?? '', self::BASES, true) ? $post['base'] : 'owed';
        $value = self::number($post['value'] ?? '');
        $description = trim((string) ($post['description'] ?? ''));
        $note = trim((string) ($post['note'] ?? ''));

        if ($year === '' || !ctype_digit($year)) {
            $errors[] = __m('Select the academic year.');
        }
        if ($value === null || $value <= 0) {
            $errors[] = __m('Enter a discount that is more than 0.');
        } elseif ($mode === 'percent' && $value > 100) {
            $errors[] = __m('A percentage can not be more than 100.');
        } elseif ($mode === 'fixed' && $value > self::MAX_AMOUNT) {
            $errors[] = __m('The discount is too high.');
        }
        if ($description === '') {
            $errors[] = __m('Enter a description.');
        }

        return [[
            'academicYear' => $year,
            'mode' => $mode,
            'base' => $base,
            'value' => $value ?? 0.0,
            'description' => mb_substr($description, 0, 2000),
            'note' => mb_substr($note, 0, 2000),
        ], $errors];
    }

    /**
     * Step 2: the filters. An empty filter does not filter.
     */
    public static function parseFilters(array $post)
    {
        $ops = ['any', 'gt', 'lt'];
        $childrenOp = in_array($post['childrenOp'] ?? '', ['any', 'gt', 'lt', 'eq'], true) ? $post['childrenOp'] : 'any';
        $owedOp = in_array($post['owedOp'] ?? '', $ops, true) ? $post['owedOp'] : 'any';
        $adjustments = in_array($post['hasAdjustment'] ?? '', ['any', 'with', 'without'], true) ? $post['hasAdjustment'] : 'any';

        $cadence = array_values(array_intersect((array) ($post['cadence'] ?? []), SepaGateway::WITHDRAW_CADENCES));
        $courses = array_values(array_unique(array_filter(array_map('intval', (array) ($post['courses'] ?? [])))));

        return [
            'cadence' => $cadence,
            'childrenOp' => $childrenOp,
            'childrenN' => max(0, (int) ($post['childrenN'] ?? 0)),
            'owedOp' => $owedOp,
            'owedX' => max(0.0, (float) (self::number($post['owedX'] ?? '') ?? 0)),
            'courses' => $courses,
            'noPayments' => !empty($post['noPayments']),
            'hasAdjustment' => $adjustments,
        ];
    }

    /**
     * Does a family row match the filters?
     *
     * @param array $row gibbonSEPAID, cadence, children, balance, payments, adjustmentCount, courseIDs
     */
    public static function matches(array $row, array $filters)
    {
        if (!empty($filters['cadence']) && !in_array($row['cadence'], $filters['cadence'], true)) {
            return false;
        }

        $children = (int) $row['children'];
        $n = (int) $filters['childrenN'];
        if (($filters['childrenOp'] === 'gt' && !($children > $n))
            || ($filters['childrenOp'] === 'lt' && !($children < $n))
            || ($filters['childrenOp'] === 'eq' && $children !== $n)) {
            return false;
        }

        // the amount owed (positive number) is compared, not the negative balance
        $owed = self::owed($row['balance']);
        if (($filters['owedOp'] === 'gt' && !($owed > $filters['owedX']))
            || ($filters['owedOp'] === 'lt' && !($owed < $filters['owedX']))) {
            return false;
        }

        if (!empty($filters['courses']) && empty(array_intersect($filters['courses'], $row['courseIDs']))) {
            return false;
        }
        if (!empty($filters['noPayments']) && abs((float) $row['payments']) >= 0.005) {
            return false;
        }
        if ($filters['hasAdjustment'] === 'with' && (int) $row['adjustmentCount'] === 0) {
            return false;
        }
        if ($filters['hasAdjustment'] === 'without' && (int) $row['adjustmentCount'] > 0) {
            return false;
        }

        return true;
    }

    /** What the family owes (0 when the balance is zero or positive). */
    public static function owed($balance)
    {
        return round(max(0.0, -1 * (float) $balance), 2);
    }

    /**
     * The adjustment for a family: a percentage of the amount owed or of the total fees, or a fixed value.
     * It is capped at the amount owed, so a bulk entry never makes a credit; 0 when nothing is owed.
     */
    public static function amountFor(array $row, array $settings)
    {
        $owed = self::owed($row['balance']);
        if ($owed <= 0) {
            return 0.0;
        }

        if ($settings['mode'] === 'percent') {
            $basis = $settings['base'] === 'fees' ? (float) $row['fees'] : $owed;
            $amount = $basis * $settings['value'] / 100;
        } else {
            $amount = (float) $settings['value'];
        }

        return round(min($amount, $owed), 2);
    }

    /** A number as typed by a user: 12,5 and 12.5 are the same; null when it is not a number. */
    public static function number($value)
    {
        $value = trim(str_replace(',', '.', (string) $value));
        return is_numeric($value) ? round((float) $value, 2) : null;
    }
}
