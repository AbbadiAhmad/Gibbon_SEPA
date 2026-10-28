<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community
Copyright © 2010, Gibbon Foundation
*/

use Gibbon\Forms\Form;
use Gibbon\Module\Sepa\Domain\CustomFieldsGateway;
use Gibbon\Module\Sepa\Domain\RegistrationFormGateway;
use Gibbon\Module\Sepa\Domain\SepaGateway;
use Gibbon\Module\Sepa\Security\IbanCipher;
use Gibbon\Data\Validator;

// Module includes
require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/import_sepa_from_form.php')) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$_GET = $container->get(Validator::class)->sanitize($_GET);
$_POST = $container->get(Validator::class)->sanitize($_POST);

$step = isset($_GET['step']) ? min(max(1, (int) $_GET['step']), 4) : 1;

$page->breadcrumbs
    ->add(__('Import Family SEPA'), 'import_sepa_data.php')
    ->add(__('Import from Registration Forms'), 'import_sepa_from_form.php')
    ->add(__('Step {number}', ['number' => $step]));

$steps = [
    1 => __('Select Forms'),
    2 => __('Map Fields'),
    3 => __('Review and Edit'),
    4 => __('Import'),
];
$StepLink = $session->get('absoluteURL') . '/index.php?q=' . $_SESSION[$guid]['address'] . '&step=';

echo "<ul class='multiPartForm'>";
foreach ($steps as $stepNumber => $stepName) {
    printf("<li class='step %s'>%s</li>", ($step >= $stepNumber) ? 'active' : '', $stepName);
}
echo '</ul>';

echo '<h2>' . __('Step {number}', ['number' => $step]) . ' - ' . $steps[$step] . '</h2>';

if (!IbanCipher::hasKey()) {
    $page->addError(__('The IBAN encryption key is not configured, the IBAN can not be saved. See README.md.'));
    return;
}

$e = function ($value) {
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
};

$FormGateway = $container->get(RegistrationFormGateway::class);
$SepaGateway = $container->get(SepaGateway::class);

// SEPA fields that can be filled from the form. 'required' fields have to be mapped.
$sepaFields = [
    'payer' => ['label' => __('Account holder'), 'required' => true, 'hints' => ['kontoinhaber', 'inhaber', 'holder', 'account owner', 'payer']],
    'IBAN' => ['label' => __('IBAN'), 'required' => true, 'hints' => ['iban']],
    'BIC' => ['label' => __('BIC'), 'required' => false, 'hints' => ['bic', 'swift']],
    'withdrawDay' => ['label' => __('Preferred withdrawal day'), 'required' => false, 'hints' => ['tag', 'day', 'abbuchungstag']],
    'withdrawCadence' => ['label' => __('Withdrawal cadence'), 'required' => false, 'hints' => ['frequenz', 'freq', 'cadence', 'intervall', 'rhythmus', 'zahlweise']],
    'note' => ['label' => __('Note'), 'required' => false, 'hints' => ['note', 'bemerkung', 'notiz']],
];
// Suggested form fields of the first school using this module (only used when the labels give no hint).
$fallbackKeys = ['IBAN' => 'custom0006', 'payer' => 'custom0007', 'withdrawDay' => 'custom0008', 'withdrawCadence' => 'custom0009'];
$dateFormats = ['d.m.Y', 'd/m/Y', 'd-m-Y', 'Y-m-d'];

/** Value of a form field for an application ('' when missing). */
$fieldValue = function ($application, $key) {
    if ($key === '' || $key === null) {
        return '';
    }
    if ($key === RegistrationFormGateway::FIELD_CREATED) {
        return $application['timestampCreated'] ?? '';
    }
    $value = $application['formData'][$key] ?? '';
    return is_scalar($value) ? trim((string) $value) : trim(implode(', ', array_filter((array) $value, 'is_scalar')));
};

/** Parse a date in one of the supported formats (or a timestamp) into Y-m-d, '' when not possible. */
$parseDate = function ($value, $format) use ($dateFormats) {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    foreach (array_unique(array_merge([$format], $dateFormats, ['Y-m-d H:i:s'])) as $candidate) {
        $date = DateTime::createFromFormat('!' . $candidate, $value);
        if ($date && $date->format($candidate) === $value) {
            return $date->format('Y-m-d');
        }
    }
    $time = strtotime($value);
    return $time ? date('Y-m-d', $time) : '';
};

// ---------------------------------------------------------------------------------------------
// STEP 1: choose school year and status of the forms
// ---------------------------------------------------------------------------------------------
if ($step == 1) {
    $statuses = $FormGateway->selectStatuses();

    $form = Form::create('importFormStep1', $StepLink . '2');
    $form->addHiddenValue('address', $_SESSION[$guid]['address']);

    $row = $form->addRow();
    $row->addLabel('schoolYearID', __('Academic Year'))->description(__('Registration forms of this academic year are read.'));
    $row->addSelect('schoolYearID')->fromArray($SepaGateway->getAllSchoolYears())->selected($_SESSION[$guid]['gibbonSchoolYearID'])->required();

    $row = $form->addRow();
    $row->addLabel('status', __('Form Status'));
    $row->addSelect('status')->fromArray(array_combine($statuses, $statuses))->selected('Accepted')->required();

    $row = $form->addRow();
    $row->addFooter();
    $row->addSubmit(__('Next'));

    echo $form->getOutput();
    return;
}

// ---------------------------------------------------------------------------------------------
// STEP 2: map the form fields to the SEPA fields
// ---------------------------------------------------------------------------------------------
if ($step == 2) {
    $schoolYearID = (int) ($_POST['schoolYearID'] ?? 0);
    $status = $_POST['status'] ?? '';
    if (empty($schoolYearID) || $status === '') {
        $page->addError(__('You have not specified one or more required parameters.'));
        return;
    }

    try {
        $applications = $FormGateway->getApplications($schoolYearID, $status);
    } catch (Exception $ex) {
        $page->addError($ex->getMessage());
        return;
    }
    if (empty($applications)) {
        $page->addError(__('No registration forms with this status were found in the academic year.'));
        return;
    }

    // All form fields used by any application, with the first non-empty example
    // Only active custom fields can be used
    $customFieldInfo = $FormGateway->getCustomFieldInfo();
    $labels = $customFieldInfo['labels'];
    $examples = [];
    foreach ($applications as $application) {
        foreach ($application['formData'] as $key => $value) {
            if (in_array($key, $customFieldInfo['inactive'], true) || !is_scalar($value) || $value === '') {
                continue;
            }
            if (!isset($examples[$key])) {
                $examples[$key] = (string) $value;
            }
        }
    }
    ksort($examples);

    $options = [];
    foreach ($examples as $key => $example) {
        $label = isset($labels[$key]) ? $labels[$key] . ' (' . $key . ')' : $key;
        $options[$key] = $label . ' - ' . mb_strimwidth($example, 0, 30, '...');
    }

    $guess = function ($field) use ($examples, $labels, $sepaFields, $fallbackKeys) {
        foreach ($examples as $key => $example) {
            $haystack = mb_strtolower($key . ' ' . ($labels[$key] ?? ''));
            foreach ($sepaFields[$field]['hints'] as $hint) {
                if (strpos($haystack, $hint) !== false) {
                    return $key;
                }
            }
        }
        return isset($fallbackKeys[$field], $examples[$fallbackKeys[$field]]) ? $fallbackKeys[$field] : '';
    };

    echo '<p>' . sprintf(__('%d registration forms were found. Choose which form field holds which SEPA information.'), count($applications)) . '</p>';

    $form = Form::create('importFormStep2', $StepLink . '3');
    $form->addHiddenValue('address', $_SESSION[$guid]['address']);
    $form->addHiddenValue('schoolYearID', $schoolYearID);
    $form->addHiddenValue('status', $status);

    foreach ($sepaFields as $field => $info) {
        $row = $form->addRow();
        $row->addLabel("map[$field]", $info['label']);
        $select = $row->addSelect("map[$field]")->fromArray($options)->placeholder()->selected($guess($field));
        if ($info['required']) {
            $select->required();
        }
    }

    // Signed date: the application creation date by default
    $dateOptions = [RegistrationFormGateway::FIELD_CREATED => __('Date the registration form was created')] + $options;
    $row = $form->addRow();
    $row->addLabel('signedDateSource', __('Date of SEPA signature'))->description(__('The SEPA mandate is signed with the registration form.'));
    $row->addSelect('signedDateSource')->fromArray($dateOptions)->selected(RegistrationFormGateway::FIELD_CREATED)->placeholder(__('No date'));

    $row = $form->addRow();
    $row->addLabel('dateFormat', __('Date format'))->description(__('Only used when the signature date is taken from a form field.'));
    $row->addSelect('dateFormat')->fromArray($dateFormats);

    $row = $form->addRow();
    $row->addFooter();
    $row->addSubmit(__('Next'));

    echo $form->getOutput();
    return;
}

// ---------------------------------------------------------------------------------------------
// STEP 3: review and edit
// ---------------------------------------------------------------------------------------------
if ($step == 3) {
    $schoolYearID = (int) ($_POST['schoolYearID'] ?? 0);
    $status = $_POST['status'] ?? '';
    $mapping = array_intersect_key($_POST['map'] ?? [], $sepaFields);
    $signedDateSource = $_POST['signedDateSource'] ?? '';
    $dateFormat = in_array($_POST['dateFormat'] ?? '', $dateFormats, true) ? $_POST['dateFormat'] : 'd.m.Y';

    if (empty($schoolYearID) || $status === '' || empty($mapping['payer']) || empty($mapping['IBAN'])) {
        $page->addError(__('The account holder and the IBAN have to be mapped.'));
        return;
    }

    try {
        $applications = $FormGateway->getApplications($schoolYearID, $status);
    } catch (Exception $ex) {
        $page->addError($ex->getMessage());
        return;
    }

    $cadenceOptions = SepaGateway::getWithdrawCadenceOptions();
    $seenFamilies = [];
    $rows = [];
    $sessionRows = [];

    foreach ($applications as $application) {
        $familyID = $application['familyID'];
        $record = [
            'payer' => $fieldValue($application, $mapping['payer']),
            'IBAN' => IbanCipher::normalize($fieldValue($application, $mapping['IBAN'])),
            'BIC' => strtoupper(preg_replace('/\s+/', '', $fieldValue($application, $mapping['BIC'] ?? ''))),
            'withdrawDay' => '',
            'withdrawCadence' => '',
            'SEPA_signedDate' => '',
            'note' => '',
        ];
        $messages = [];
        $state = 'new';

        // Withdrawal day / cadence: normalised, unknown texts are reported and left for the user
        $rawDay = $fieldValue($application, $mapping['withdrawDay'] ?? '');
        if ($rawDay !== '') {
            $record['withdrawDay'] = SepaGateway::normalizeDay($rawDay) ?? '';
            if ($record['withdrawDay'] === '') {
                $messages[] = sprintf(__('Unknown withdrawal day: %s - the default is used.'), $rawDay);
            }
        }
        if ($record['withdrawDay'] === '') {
            $record['withdrawDay'] = SepaGateway::DEFAULT_WITHDRAW_DAY;
        }
        $rawCadence = $fieldValue($application, $mapping['withdrawCadence'] ?? '');
        if ($rawCadence !== '') {
            $record['withdrawCadence'] = SepaGateway::normalizeCadence($rawCadence) ?? '';
            if ($record['withdrawCadence'] === '') {
                $messages[] = sprintf(__('Unknown withdrawal cadence: %s - the default is used.'), $rawCadence);
            }
        }
        if ($record['withdrawCadence'] === '') {
            $record['withdrawCadence'] = SepaGateway::DEFAULT_WITHDRAW_CADENCE;
        }

        // Signature date = date of the registration form (or a mapped form field)
        if ($signedDateSource !== '') {
            $record['SEPA_signedDate'] = $parseDate($fieldValue($application, $signedDateSource), $dateFormat);
            if ($record['SEPA_signedDate'] === '') {
                $messages[] = __('The signature date could not be read');
            }
        }

        // Note: where the data comes from (+ an optional mapped note)
        $source = sprintf(__('From registration form ID = %s'), $application['gibbonAdmissionsApplicationID']);
        $extraNote = $fieldValue($application, $mapping['note'] ?? '');
        $record['note'] = $source . ($extraNote !== '' ? "\n" . $extraNote : '');

        // Checks
        $existing = [];
        if (empty($familyID)) {
            $state = 'no_family';
            $messages[] = __('The registration form is not connected to a family.');
        } else {
            $existing = $SepaGateway->getFamilySEPA($familyID);
            if (isset($seenFamilies[$familyID])) {
                $state = 'duplicate';
                $messages[] = sprintf(__('The family is already used by registration form %s'), $seenFamilies[$familyID]);
            } elseif (!empty($existing)) {
                $state = 'existing';
                $messages[] = __('The family already has a SEPA. Select the row only if it should be overwritten.');
                if (!empty(trim($existing[0]['note'] ?? ''))) {
                    $record['note'] = $existing[0]['note'] . "\n" . $record['note'];
                }
            }
            $seenFamilies[$familyID] = $seenFamilies[$familyID] ?? $application['gibbonAdmissionsApplicationID'];
        }
        // Account holder: missing or suspicious values are checked against the parents of the family
        $adults = $state !== 'no_family' ? $SepaGateway->selectAdultsByFamily($familyID)->fetchAll() : [];
        if ($record['payer'] === '' && !empty($adults)) {
            $record['payer'] = trim($adults[0]['preferredName'] . ' ' . $adults[0]['surname']);
            $messages[] = sprintf(__('The account holder is missing, the first parent of the family was suggested. Please check.'));
        } elseif ($record['payer'] === '') {
            $messages[] = __('The account holder is missing.');
        } else {
            $payerLower = mb_strtolower($record['payer']);
            if (preg_match('/\d/', $record['payer']) || mb_strlen($record['payer']) < 4 || strpos(trim($record['payer']), ' ') === false) {
                $messages[] = __('The account holder looks incomplete or wrong (needs first and last name, no digits).');
            }
            $matchesParent = empty($adults);
            foreach ($adults as $adult) {
                if ($adult['surname'] !== '' && mb_strpos($payerLower, mb_strtolower($adult['surname'])) !== false) {
                    $matchesParent = true;
                    break;
                }
            }
            if (!$matchesParent) {
                $messages[] = __('The account holder does not match the surname of any parent of the family.');
            }
        }
        if ($record['payer'] !== '' && $state !== 'no_family') {
            foreach ($SepaGateway->getSEPAByPayer($record['payer']) as $sameName) {
                if ($sameName['gibbonFamilyID'] != $familyID) {
                    $messages[] = __('Another family already has a SEPA with this account holder.');
                    break;
                }
            }
        }
        if ($record['IBAN'] === '') {
            $messages[] = __('The IBAN is missing.');
        } elseif (strlen($record['IBAN']) <= 4) {
            $messages[] = __('The IBAN is incomplete (only the country code or a few characters were entered). Enter it or clear the field.');
        } elseif (!IbanCipher::isValid($record['IBAN'])) {
            $messages[] = __('The IBAN is not valid (checksum).');
        }

        $rows[] = [
            'application' => $application,
            'record' => $record,
            'state' => $state,
            'messages' => $messages,
            'existing' => $existing[0] ?? null,
        ];
        $sessionRows[] = [
            'familyID' => $familyID,
            'applicationID' => $application['gibbonAdmissionsApplicationID'],
            'state' => $state,
            'existingSEPAID' => $existing[0]['gibbonSEPAID'] ?? null,
        ];
    }

    // Only family / form IDs are kept in the session, never the IBANs.
    $_SESSION[$guid]['sepaFormImportRows'] = $sessionRows;

    $statusLabels = [
        'new' => [__('New'), 'green'],
        'existing' => [__('Existing'), 'orange'],
        'duplicate' => [__('Duplicate family'), 'orange'],
        'no_family' => [__('No family'), 'red'],
    ];

    // One card per registration form: wide inputs instead of a cramped table
    $html = "<style>
        .formImportCard { border: 1px solid #ccc; border-radius: 4px; padding: 10px 12px; margin-bottom: 12px; background: #fff; }
        .formImportCard.skipped { background: #fafafa; }
        .formImportHead { display: flex; flex-wrap: wrap; align-items: center; gap: 14px; margin-bottom: 8px; }
        .formImportHead label { font-weight: bold; }
        .formImportFields { display: flex; flex-wrap: wrap; gap: 10px 14px; }
        .formImportFields > div { flex: 1 1 200px; min-width: 200px; }
        .formImportFields > div.wide { flex: 2 1 320px; }
        .formImportFields label { display: block; font-size: 12px; color: #555; margin-bottom: 2px; }
        .formImportFields input[type=text], .formImportFields input[type=date], .formImportFields select, .formImportFields textarea { width: 100%; box-sizing: border-box; }
        .formImportCurrent { margin-top: 8px; padding: 6px 8px; background: #f2f2f2; font-style: italic; font-size: 12px; }
    </style>";
    $html .= "<p><label><input type='checkbox' id='selectAll' /> <strong>" . $e(__('Select All')) . "</strong></label></p>";

    $count = ['new' => 0, 'existing' => 0, 'duplicate' => 0, 'no_family' => 0];
    foreach ($rows as $index => $item) {
        $record = $item['record'];
        $state = $item['state'];
        $count[$state]++;
        $selectable = $state !== 'no_family';
        // Ready to import: a new SEPA with an account holder and a valid IBAN
        $checked = $state === 'new' && $record['payer'] !== '' && IbanCipher::isValid($record['IBAN']);
        $childName = trim($e($item['application']['formData']['preferredName'] ?? '') . ' ' . $e($item['application']['formData']['surname'] ?? ''));

        $html .= "<div class='formImportCard" . ($selectable ? '' : ' skipped') . "'><div class='formImportHead'>";
        $html .= $selectable
            ? "<label><input type='checkbox' name='import[]' value='{$index}'" . ($checked ? ' checked' : '') . ' /> ' . $e(__('Import')) . '</label>'
            : '';
        $html .= "<span style='color:{$statusLabels[$state][1]}; font-weight:bold;'>" . $e($statusLabels[$state][0]) . '</span>';
        $html .= '<span>' . $e(__('Form')) . ' #' . $e($item['application']['gibbonAdmissionsApplicationID']) . ($childName !== '' ? ' - ' . $childName : '') . '</span>';
        $html .= '<span>' . $e(__('Family')) . ': ' . $e($item['application']['familyName'] ?? '-') . '</span></div>';

        if ($selectable) {
            $html .= "<div class='formImportFields'>";
            $html .= "<div class='wide'><label>" . $e(__('Account holder')) . "</label><input type='text' name='rows[{$index}][payer]' value='" . $e($record['payer']) . "' /></div>";
            $html .= "<div class='wide'><label>" . $e(__('IBAN')) . "</label><input type='text' class='ibanInput' name='rows[{$index}][IBAN]' value='" . $e(IbanCipher::format($record['IBAN'])) . "' /></div>";
            $html .= "<div><label>" . $e(__('BIC')) . "</label><input type='text' name='rows[{$index}][BIC]' value='" . $e($record['BIC']) . "' maxlength='11' /></div>";
            $html .= "<div><label>" . $e(__('Withdrawal cadence')) . "</label><select name='rows[{$index}][withdrawCadence]'><option value=''></option>";
            foreach ($cadenceOptions as $value => $label) {
                $html .= "<option value='{$value}'" . ($record['withdrawCadence'] === $value ? ' selected' : '') . '>' . $e($label) . '</option>';
            }
            $html .= '</select></div>';
            $html .= "<div><label>" . $e(__('Preferred withdrawal day')) . "</label><select name='rows[{$index}][withdrawDay]'><option value=''></option>";
            foreach (SepaGateway::getWithdrawDayOptions() as $day) {
                $html .= "<option value='{$day}'" . ((string) $record['withdrawDay'] === (string) $day ? ' selected' : '') . ">{$day}</option>";
            }
            $html .= '</select></div>';
            $html .= "<div><label>" . $e(__('Date of SEPA signature')) . "</label><input type='date' name='rows[{$index}][SEPA_signedDate]' value='" . $e($record['SEPA_signedDate']) . "' /></div>";
            $html .= "<div class='wide'><label>" . $e(__('Note')) . "</label><textarea name='rows[{$index}][note]' rows='2'>" . $e($record['note']) . '</textarea></div>';
            $html .= '</div>';
        }

        if (!empty($item['messages'])) {
            $html .= "<ul style='color:#c60; margin:8px 0 0; padding-left:18px;'>";
            foreach ($item['messages'] as $message) {
                $html .= '<li>' . $e($message) . '</li>';
            }
            $html .= '</ul>';
        }

        if ($item['existing']) {
            $current = $item['existing'];
            $html .= "<div class='formImportCurrent'>" . $e(__('Current Data:')) . ' '
                . $e($current['payer']) . ' | '
                . $e(IbanCipher::format(IbanCipher::decryptForDisplay($current['IBAN']))) . ' | '
                . $e($current['BIC']) . ' | '
                . $e($cadenceOptions[$current['withdrawCadence']] ?? '') . ' | '
                . $e($current['withdrawDay']) . ' | '
                . $e($current['SEPA_signedDate']) . ' | '
                . $e($current['note']) . '</div>';
        }
        $html .= '</div>';
    }

    $html .= "<script>
    document.getElementById('selectAll').addEventListener('change', function () {
        var boxes = document.getElementsByName('import[]');
        for (var i = 0; i < boxes.length; i++) { boxes[i].checked = this.checked; }
    });
    function ibanValid(value) {
        value = value.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
        if (!/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}\$/.test(value)) { return false; }
        var s = value.substring(4) + value.substring(0, 4), n = '';
        for (var i = 0; i < s.length; i++) { var c = s.charCodeAt(i); n += (c >= 65 ? (c - 55) : s.charAt(i)); }
        var r = 0;
        for (var j = 0; j < n.length; j++) { r = (r * 10 + parseInt(n.charAt(j), 10)) % 97; }
        return r === 1;
    }
    function checkIbanInput(input) {
        input.style.borderColor = (input.value === '' || ibanValid(input.value)) ? '' : 'red';
    }
    var ibanInputs = document.getElementsByClassName('ibanInput');
    for (var k = 0; k < ibanInputs.length; k++) {
        checkIbanInput(ibanInputs[k]);
        ibanInputs[k].addEventListener('input', function () { checkIbanInput(this); });
    }
    </script>";

    echo "<div class='success' style='margin-bottom:15px;'><strong>" . __('Summary:') . '</strong> '
        . __('New') . ": {$count['new']} | " . __('Existing') . ": {$count['existing']} | "
        . __('Duplicate family') . ": {$count['duplicate']} | " . __('No family') . ": {$count['no_family']}</div>";
    echo '<p>' . __('Check the data, correct it where needed and select the rows to import. Rows with a red IBAN field have an invalid IBAN and can not be imported until it is corrected.') . '</p>';

    $form = Form::create('importFormStep3', $StepLink . '4');
    $form->addHiddenValue('address', $_SESSION[$guid]['address']);
    $form->addRow()->addContent($html);
    $row = $form->addRow();
    $row->addFooter();
    $row->addSubmit(__('Import Selected'));

    echo $form->getOutput();
    return;
}

// ---------------------------------------------------------------------------------------------
// STEP 4: import
// ---------------------------------------------------------------------------------------------
$sessionRows = $_SESSION[$guid]['sepaFormImportRows'] ?? null;
if (empty($sessionRows)) {
    $page->addError(__('Invalid data'));
    return;
}

$selected = array_unique(array_map('intval', $_POST['import'] ?? []));
$rowsInput = $_POST['rows'] ?? [];
$customFieldsGateway = $container->get(CustomFieldsGateway::class);
$customData = json_encode($customFieldsGateway->getCustomFieldInitialData());

$inserted = [];
$updated = [];
$failed = [];
$skipped = count($sessionRows) - count($selected);

foreach ($selected as $index) {
    $meta = $sessionRows[$index] ?? null;
    $input = $rowsInput[$index] ?? null;
    if (!$meta || !is_array($input)) {
        continue;
    }
    $label = sprintf(__('Form #%s'), $meta['applicationID']) . ': ' . trim($input['payer'] ?? '');

    $data = [
        'payer' => trim($input['payer'] ?? ''),
        'IBAN' => IbanCipher::normalize($input['IBAN'] ?? ''),
        'BIC' => strtoupper(preg_replace('/\s+/', '', $input['BIC'] ?? '')),
        'withdrawCadence' => $input['withdrawCadence'] ?? '',
        'withdrawDay' => $input['withdrawDay'] ?? '',
        'SEPA_signedDate' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['SEPA_signedDate'] ?? '') ? $input['SEPA_signedDate'] : null,
        'note' => trim($input['note'] ?? ''),
    ];

    // Server side checks (the review page checks are only a help)
    if (empty($meta['familyID']) || $meta['state'] === 'no_family') {
        $failed[] = $label . ' - ' . __('The registration form is not connected to a family.');
        continue;
    }
    if ($data['payer'] === '') {
        $failed[] = $label . ' - ' . __('The account holder is missing.');
        continue;
    }
    if ($data['IBAN'] !== '' && !IbanCipher::isValid($data['IBAN'])) {
        $failed[] = $label . ' - ' . __('The IBAN is not valid (checksum).');
        continue;
    }

    try {
        $currentSEPA = $SepaGateway->getFamilySEPA($meta['familyID']);
        if (!empty($currentSEPA)) {
            if ($meta['state'] !== 'existing' || $currentSEPA[0]['gibbonSEPAID'] != $meta['existingSEPAID']) {
                $failed[] = $label . ' - ' . __('The family has a SEPA already (it was added after the review).');
                continue;
            }
            $SepaGateway->updateSEPABySEPAID($currentSEPA[0]['gibbonSEPAID'], $data);
            $updated[] = $label;
        } else {
            if ($meta['state'] === 'existing') {
                $failed[] = $label . ' - ' . __('The SEPA of the family was deleted after the review.');
                continue;
            }
            if (!$SepaGateway->insertSEPA($meta['familyID'], $data, $customData)) {
                $failed[] = $label . ' - ' . __('Failed to insert');
                continue;
            }
            $inserted[] = $label;
        }
    } catch (Exception $ex) {
        $failed[] = $label . ' - ' . $ex->getMessage();
    }
}

echo '<h3>' . __('Import Results') . '</h3>';
$printList = function ($class, $title, $items) use ($e) {
    if (empty($items)) {
        return;
    }
    echo "<div class='{$class}'><strong>" . $e($title) . '</strong><ul><li>' . implode('</li><li>', array_map($e, $items)) . '</li></ul></div>';
};
$printList('success', sprintf(__('Successfully inserted %d new records'), count($inserted)), $inserted);
$printList('success', sprintf(__('Successfully updated %d existing records'), count($updated)), $updated);
$printList('error', sprintf(__('Failed to process %d records'), count($failed)), $failed);

echo "<div class='success' style='margin-top:20px;'><strong>" . __('Summary:') . '</strong><br/>'
    . __('Inserted:') . ' ' . count($inserted) . '<br/>'
    . __('Updated:') . ' ' . count($updated) . '<br/>'
    . __('Skipped:') . " {$skipped}<br/>"
    . __('Errors:') . ' ' . count($failed) . '</div>';

if (empty($failed)) {
    unset($_SESSION[$guid]['sepaFormImportRows']);
}
