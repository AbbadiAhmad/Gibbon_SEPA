<?php
/*
Gibbon, Flexible & Open School System
Copyright (C) 2010, Ross Parker

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

require_once __DIR__ . '/src/i18n.php';

function convertToFloat($strNumber, $decimalSep)
{
    if ($decimalSep == ','){
        // replace thousand separator by ''
        $strNumber = str_replace('.', '', $strNumber);
        // replace decimal separator by .
        $strNumber = str_replace(',', '.', $strNumber);
    }else{
        // remove thousand separator
    $strNumber = floatval(str_replace(',', '', $strNumber));
    }
    return $strNumber;
}

/**
 * Render a template file with placeholder substitution
 *
 * @param string $templatePath Path to the template file
 * @param array $data Associative array of placeholder => value pairs
 * @param bool $escapeHtml Whether to escape HTML in values (default: true)
 * @return string Rendered template content
 */
function renderTemplate($templatePath, $data, $escapeHtml = true)
{
    // Check if template file exists
    if (!file_exists($templatePath)) {
        return "Error: Template file not found: " . htmlspecialchars($templatePath);
    }

    // Load template content
    $template = file_get_contents($templatePath);

    if ($template === false) {
        return "Error: Could not read template file";
    }

    // Replace placeholders with data
    foreach ($data as $placeholder => $value) {
        // Escape HTML if requested (for security)
        // Don't escape these placeholders as they contain pre-formatted HTML
        if ($escapeHtml && !in_array($placeholder, ['PAYMENT_TABLE', 'ORGANIZATION_ADDRESS', 'SEPA_ACCOUNT_INFO', 'FAMILY_INFO'])) {
            $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }

        // Replace {{PLACEHOLDER}} with value
        $template = str_replace('{{' . $placeholder . '}}', $value, $template);
    }

    // Remove any remaining unreplaced placeholders
    $template = preg_replace('/\{\{[A-Z_]+\}\}/', '', $template);

    return $template;
}

/**
 * The SEPA check page can open the SEPA add / edit pages; these values bring the user back
 * to the check page (with the same filters) after saving. Only known parameters are accepted.
 *
 * @param array $source $_GET or $_POST
 * @return array parameters to carry along, empty when the user did not come from the check page
 */
function getSepaCheckReturnParams($source)
{
    if (($source['returnTo'] ?? '') !== 'check') {
        return [];
    }
    $params = ['returnTo' => 'check'];
    foreach (['schoolYearID', 'balanceFilter', 'severity', 'problem', 'search'] as $name) {
        if (isset($source[$name]) && is_scalar($source[$name]) && $source[$name] !== '') {
            $params[$name] = (string) $source[$name];
        }
    }
    return $params;
}

/**
 * The academic year filter of the list pages: 'all' or a school year ID.
 * Without (or with an invalid) value the default is used, normally the current school year.
 *
 * @param array $source $_GET
 * @param mixed $default school year ID used when nothing is selected
 * @return string 'all' or a school year ID
 */
function sepaSelectedSchoolYear($source, $default)
{
    $value = $source['schoolYearID'] ?? '';
    if ($value === 'all') {
        return 'all';
    }
    return (is_string($value) || is_int($value)) && ctype_digit((string) $value) ? (string) $value : (string) $default;
}

/**
 * Add the academic year select (All / every school year) to a filter form.
 *
 * @param \Gibbon\Forms\Form $form
 * @param array $schoolYears [schoolYearID => name] as returned by SepaGateway::getAllSchoolYears()
 * @param string $selected 'all' or a school year ID
 */
function sepaAddSchoolYearRow($form, array $schoolYears, $selected)
{
    $row = $form->addRow();
    $row->addLabel('schoolYearID', __m('Academic Year'));
    $row->addSelect('schoolYearID')->fromArray(['all' => __m('All')] + $schoolYears)->selected($selected);
}

/**
 * Warning for pages that show results calculated with the course fees: lists the courses with
 * enrolled students that have no fee set (they are counted with 0). Empty string when all is set.
 *
 * @param \Gibbon\Module\Sepa\Domain\SepaGateway $gateway
 * @param mixed $schoolYearID school year ID, or 'all'
 * @param string $absoluteURL
 * @param mixed $gibbonFamilyID only the courses of this family (optional)
 * @return string HTML
 */
function sepaMissingFeesWarning($gateway, $schoolYearID, $absoluteURL, $gibbonFamilyID = null)
{
    $courses = $gateway->getCoursesWithoutFees($schoolYearID, $gibbonFamilyID);
    if (empty($courses)) {
        return '';
    }

    $names = [];
    $years = [];
    foreach (array_slice($courses, 0, 12) as $course) {
        $names[] = htmlspecialchars($course['nameShort'] . ($schoolYearID === 'all' ? ' (' . $course['yearName'] . ')' : ''), ENT_QUOTES, 'UTF-8');
    }
    foreach ($courses as $course) {
        $years[$course['gibbonSchoolYearID']] = $course['yearName'];
    }
    $more = count($courses) > 12 ? ' ' . sprintf(__m('and %d more'), count($courses) - 12) : '';

    $links = [];
    foreach ($years as $yearID => $yearName) {
        $links[] = '<a href="' . $absoluteURL . '/index.php?q=/modules/Sepa/sepa_courses_fee_view.php&schoolYearID=' . (int) $yearID . '">' . sprintf(__m('Set the course fees of %s'), htmlspecialchars($yearName, ENT_QUOTES, 'UTF-8')) . '</a>';
    }

    return '<div class="warning"><strong>' . sprintf(__m('The fee is not set for %d course(s) with enrolled students; they are counted with 0, so the results below are not complete.'), count($courses)) . '</strong><br>'
        . implode(', ', $names) . $more . '<br>' . implode(' | ', $links) . '</div>';
}

/**
 * The changes of one log line as HTML lines (old -> new). The IBANs of the log are stored encrypted
 * and are decrypted here; for families ($forFamily) the IBAN is shown half hidden.
 */
function sepaFormatLogChanges($json, $forFamily = false)
{
    $e = function ($value) {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    };
    $labels = ['payer' => __m('Account holder'), 'IBAN' => __m('IBAN'), 'BIC' => __m('BIC'), 'withdrawCadence' => __m('Withdrawal cadence'), 'withdrawDay' => __m('Preferred withdrawal day'), 'SEPA_signedDate' => __m('Date of SEPA signature')];

    [$changes, $note, $extra] = \Gibbon\Module\Sepa\Domain\SepaChangeRequestGateway::readLogChanges($json);
    $lines = [];
    foreach ($changes as $field => [$old, $new]) {
        if ($field === 'SEPA_signedDate') {
            // dates in the date format of the installation
            $old = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $old) ? \Gibbon\Services\Format::date($old) : $old;
            $new = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $new) ? \Gibbon\Services\Format::date($new) : $new;
        }
        if ($forFamily && $field === 'IBAN') {
            $old = \Gibbon\Module\Sepa\Security\IbanCipher::maskForDisplay($old);
            $new = \Gibbon\Module\Sepa\Security\IbanCipher::maskForDisplay($new);
        }
        $lines[] = '<strong>' . $e($labels[$field] ?? $field) . ':</strong> ' . $e($old === '' ? '-' : $old) . ' &rarr; ' . $e($new === '' ? '-' : $new);
    }
    $names = function (array $fields) use ($labels, $e) {
        return implode(', ', array_map(function ($field) use ($labels, $e) {
            return $e($labels[$field] ?? $field);
        }, $fields));
    };
    if (!empty($extra['rejected'])) {
        $lines[] = '<em>' . __m('Not accepted') . ': ' . $names($extra['rejected']) . '</em>';
    }
    if (!empty($extra['edited'])) {
        $lines[] = '<em>' . __m('Corrected by the school administration') . ': ' . $names($extra['edited']) . '</em>';
    }
    if ($note !== '') {
        $lines[] = '<em>' . __m('Note') . ': ' . $e($note) . '</em>';
    }
    return $lines ? implode('<br>', $lines) : '-';
}

function sepaLogEventLabel($event)
{
    return ['Submitted' => __m('Entered'), 'Updated' => __m('Entries changed'), 'Discarded' => __m('Discarded'), 'Approved' => __m('Approved'), 'Rejected' => __m('Rejected')][$event] ?? $event;
}

/**
 * HTML table of the SEPA change log (who, when, what). For the administration the IBANs are shown
 * in full; for the family ($forFamily) they are half hidden and the decisions are by the school administration.
 *
 * @param array[] $logRows rows of SepaChangeRequestGateway::selectLog()
 * @param bool $showFamily show the family column
 * @param bool $forFamily the log is shown to the family itself
 */
function sepaRenderChangeLog(array $logRows, $showFamily = true, $forFamily = false)
{
    $e = function ($value) {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    };

    if (empty($logRows)) {
        return '<p><em>' . __m('The log is empty.') . '</em></p>';
    }
    $html = '<table class="fullWidth colorOddEven"><thead><tr><th>' . __m('When') . '</th><th>' . __m('Who') . '</th>'
        . ($showFamily ? '<th>' . __m('Family') . '</th>' : '') . '<th>' . __m('What') . '</th><th>' . __m('Changes (old -> new)') . '</th></tr></thead><tbody>';
    foreach ($logRows as $row) {
        $who = $forFamily && in_array($row['event'], ['Approved', 'Rejected'], true) ? __m('School administration') : $row['personName'];
        $html .= '<tr><td>' . $e(\Gibbon\Services\Format::dateTime($row['timestamp'])) . '</td><td>' . $e($who) . '</td>'
            . ($showFamily ? '<td>' . $e($row['familyName']) . '</td>' : '')
            . '<td>' . $e(sepaLogEventLabel($row['event'])) . '</td><td>' . sepaFormatLogChanges($row['changes'], $forFamily) . '</td></tr>';
    }
    return $html . '</tbody></table>';
}
