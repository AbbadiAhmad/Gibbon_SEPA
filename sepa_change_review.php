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
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

use Gibbon\Forms\Form;
use Gibbon\Data\Validator;
use Gibbon\Services\Format;
use Gibbon\Module\Sepa\Domain\SepaGateway;
use Gibbon\Module\Sepa\Domain\SepaChangeRequestGateway;
use Gibbon\Module\Sepa\Security\IbanCipher;

// Module includes
require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_change_review.php')) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$page->breadcrumbs->add(__('Approve SEPA Changes'), 'sepa_change_requests.php');
$page->breadcrumbs->add(__('Review'));

$_GET = $container->get(Validator::class)->sanitize($_GET);
$gateway = $container->get(SepaChangeRequestGateway::class);
$SepaGateway = $container->get(SepaGateway::class);
$e = function ($value) {
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
};

$request = $gateway->getByID((int) ($_GET['gibbonSEPAChangeRequestID'] ?? 0));
if (!$request) {
    $page->addError(__('The specified record cannot be found.'));
    return;
}
// The SEPA the request is about; a family without SEPA asks for a new one (current data empty)
$target = $gateway->getTargetSepa($request);
$isNew = !$target;
$sepa = $target ?: SepaChangeRequestGateway::emptySepa();

$entered = SepaChangeRequestGateway::enteredValues($request, $sepa);
$current = SepaChangeRequestGateway::clearValues($sepa);
$cadences = SepaGateway::getWithdrawCadenceOptions();
$isPending = $request['status'] === 'Pending';
$fullIban = function ($iban) {
    return $iban === null ? '[' . __('cannot decrypt - check the encryption key') . ']' : IbanCipher::format($iban);
};

echo '<h2>' . sprintf($isNew && $request['status'] === 'Pending' ? __('New SEPA of family %s') : __('SEPA change of family %s'), $e($request['familyName'])) . '</h2>';
echo '<p>' . sprintf(__('Entered by %1$s on %2$s.'), $e($request['submittedBy']), $e(Format::dateTime($request['timestampUpdated'] ?: $request['timestampSubmitted']))) . '</p>';

if (!$isPending) {
    $text = ['Approved' => __('Approved'), 'Rejected' => __('Rejected')][$request['status']] ?? $request['status'];
    echo "<div class='" . ($request['status'] === 'Approved' ? 'success' : 'warning') . "'>" . $e($text) . ' ' . $e(Format::dateTime($request['timestampReviewed']))
        . ($request['reviewNote'] ? '<br>' . $e($request['reviewNote']) : '') . '</div>';
}

// The members of the family
$members = [];
foreach ([__('Adults') => $SepaGateway->selectAdultsByFamily($request['gibbonFamilyID']), __('Children') => $SepaGateway->selectChildrenByFamily($request['gibbonFamilyID'])] as $title => $people) {
    $names = [];
    foreach ($people as $person) {
        $names[] = $e(trim($person['preferredName'] . ' ' . $person['surname'])) . ($person['status'] !== 'Full' ? ' (' . $e($person['status']) . ')' : '');
    }
    $members[] = '<strong>' . $e($title) . ':</strong> ' . ($names ? implode(', ', $names) : '-');
}
echo '<p>' . implode('<br>', $members) . '</p>';

// Values of the reviewer that were not saved yet (sent back because of errors)
$requestID = (int) $request['gibbonSEPAChangeRequestID'];
$draft = $_SESSION[$guid]['sepaReviewDraft'][$requestID] ?? null;
unset($_SESSION[$guid]['sepaReviewDraft'][$requestID]);

$values = $entered;
$values['IBAN'] = (string) $entered['IBAN'];
// ticked by default: only what the family really changed (to a value that is not empty)
$accept = SepaChangeRequestGateway::defaultAccept($entered, SepaChangeRequestGateway::clearValues($sepa));
if ($isPending && $isNew && !$draft) {
    // a new SEPA: what the family left empty gets the defaults (day 5, signed when entered)
    foreach (SepaChangeRequestGateway::newSepaDefaults($request) as $field => $default) {
        if ((string) $values[$field] === '') {
            $values[$field] = $default;
            $accept[$field] = true;
        }
    }
}
if ($isPending && $draft) {
    $values = SepaChangeRequestGateway::cleanReviewValues($draft['value'] ?? [], $values);
    foreach ($accept as $field => $on) {
        $accept[$field] = !empty($draft['accept'][$field]);
    }
}

// Flags: of the values that would be saved
if ($isPending) {
    try {
        $decision = SepaChangeRequestGateway::buildDecision($request, $sepa, $accept, $values);
        $flags = $gateway->getFlagsForValues($decision['finalClear'], $request);
    } catch (\RuntimeException $ex) {
        $flags = $gateway->getRequestFlags($request);
    }
} else {
    $flags = [];
}
$errors = 0;
foreach ($flags as $flag) {
    $errors += $flag['severity'] === 'Error' ? 1 : 0;
    echo "<div class='" . ($flag['severity'] === 'Error' ? 'error' : 'warning') . "'><strong>" . ($flag['severity'] === 'Error' ? __('Error') : __('Warning')) . ':</strong> ' . $e($flag['text']) . '</div>';
}
if ($isPending && empty($flags)) {
    echo "<div class='success'>" . __('No problems found.') . '</div>';
}

$labels = [
    'payer' => __('Account holder'),
    'IBAN' => __('IBAN'),
    'BIC' => __('BIC'),
    'withdrawCadence' => __('Withdrawal cadence'),
    'withdrawDay' => __('Preferred withdrawal day'),
    'SEPA_signedDate' => __('Date of SEPA signature'),
];
$show = function ($field, $value) use ($cadences, $fullIban) {
    if ($field === 'IBAN') {
        return $value === null || $value !== '' ? $fullIban($value) : '';
    }
    if ($field === 'withdrawCadence') {
        return $cadences[$value] ?? $value;
    }
    if ($field === 'SEPA_signedDate') {
        return $value ? Format::date($value) : '';
    }
    return (string) $value;
};
$differs = function ($field) use ($current, $entered) {
    return $field === 'IBAN'
        ? IbanCipher::normalize((string) $current['IBAN']) !== IbanCipher::normalize((string) $entered['IBAN'])
        : (string) $current[$field] !== (string) $entered[$field];
};

// ------------------------------------------------------------------ decided request: read only
if (!$isPending) {
    $html = '<table class="fullWidth colorOddEven"><thead><tr><th></th><th>' . __('Entered by the family') . '</th></tr></thead><tbody>';
    foreach ($labels as $field => $label) {
        $html .= '<tr><td><strong>' . $e($label) . '</strong></td><td>' . $e($show($field, $entered[$field])) . '</td></tr>';
    }
    echo $html . '</tbody></table>';
} else {
    // -------------------------------------------------------------- approval: choose and correct per field
    $input = function ($field) use ($values, $e, $cadences) {
        $name = 'value[' . $field . ']';
        $value = (string) $values[$field];
        if ($field === 'withdrawCadence') {
            $html = '<select name="' . $name . '">';
            foreach ($cadences as $key => $text) {
                $html .= '<option value="' . $e($key) . '"' . ($key === $value ? ' selected' : '') . '>' . $e($text) . '</option>';
            }
            return $html . '</select>';
        }
        if ($field === 'withdrawDay') {
            $html = '<select name="' . $name . '"><option value=""></option>';
            for ($day = 1; $day <= 31; $day++) {
                $html .= '<option value="' . $day . '"' . ((string) $day === $value ? ' selected' : '') . '>' . $day . '</option>';
            }
            return $html . '</select>';
        }
        if ($field === 'SEPA_signedDate') {
            return '<input type="date" name="' . $name . '" value="' . $e($value) . '">';
        }
        if ($field === 'IBAN') {
            $value = IbanCipher::format($value);
        }
        $max = ['payer' => 255, 'IBAN' => 42, 'BIC' => 11][$field];
        return '<input type="text" name="' . $name . '" value="' . $e($value) . '" maxlength="' . $max . '" style="width: 95%;">';
    };

    $html = '<table class="fullWidth colorOddEven"><thead><tr><th></th><th>' . __('Current') . '</th><th>' . __('Entered by the family') . '</th><th>' . __('Value to save (can be corrected)') . '</th><th>' . __('Accept') . '</th></tr></thead><tbody>';
    foreach ($labels as $field => $label) {
        $changed = $differs($field);
        $html .= '<tr><td><strong>' . $e($label) . '</strong></td>'
            . '<td>' . $e($show($field, $current[$field])) . '</td>'
            . '<td' . ($changed ? ' style="font-weight: bold; background: #ffe;"' : '') . '>' . $e($show($field, $entered[$field])) . ($changed ? ' *' : '') . '</td>'
            . '<td>' . $input($field) . '</td>'
            . '<td style="text-align: center;"><input type="checkbox" name="accept[' . $field . ']" value="1"' . ($accept[$field] ? ' checked' : '') . '></td></tr>';
    }
    $html .= '</tbody></table><p><small>* ' . __('differs from the current data') . '. '
        . __('Approve saves the ticked rows with the value in the "Value to save" column; rows that are not ticked keep the current value. Rows the family did not change are not ticked.') . '</small></p>';

    $form = Form::create('sepaChangeReview', $session->get('absoluteURL') . '/modules/Sepa/sepa_change_reviewProcess.php');
    $form->addHiddenValue('address', $session->get('address'));
    $form->addHiddenValue('gibbonSEPAChangeRequestID', $requestID);
    $form->addRow()->addContent($html);

    $row = $form->addRow();
    $row->addLabel('confirmErrors', __('Approve despite errors'))->description(__('Only needed when the values to save have errors. Only tick this when the data is really correct.'));
    $row->addCheckbox('confirmErrors')->setValue('1');

    $row = $form->addRow();
    $row->addLabel('reviewNote', __('Note'))->description(__('Optional, stored with the decision.'));
    $row->addTextArea('reviewNote')->setRows(3)->setValue($draft['reviewNote'] ?? '');

    $row = $form->addRow();
    $row->addContent('<a href="' . $session->get('absoluteURL') . '/index.php?q=/modules/Sepa/sepa_change_requests.php">' . __('Back') . '</a>');
    $row->addContent('<button type="submit" name="decision" value="approve">' . __('Approve the ticked changes') . '</button> '
        . '<button type="submit" name="decision" value="reject">' . __('Reject all') . '</button>')->setClass('right');

    echo $form->getOutput();
}

// ------------------------------------------------------------------ the last log lines of this SEPA
echo '<h3>' . __('Last changes of this SEPA') . '</h3>';
echo sepaRenderChangeLog($isNew ? $gateway->selectLog(null, 10, $request['gibbonFamilyID']) : $gateway->selectLog($sepa['gibbonSEPAID'], 10), false);
if (!$isNew && isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_change_log.php')) {
    echo '<p><a href="' . $session->get('absoluteURL') . '/index.php?q=/modules/Sepa/sepa_change_log.php&sepa=' . (int) $sepa['gibbonSEPAID'] . '">' . __('More: the full log of this SEPA') . '</a></p>';
}
