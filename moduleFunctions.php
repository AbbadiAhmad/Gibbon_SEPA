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
    $row->addLabel('schoolYearID', __('Academic Year'));
    $row->addSelect('schoolYearID')->fromArray(['all' => __('All')] + $schoolYears)->selected($selected);
}
