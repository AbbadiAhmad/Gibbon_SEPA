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
$sepa = $request ? $gateway->getSepaRow($request['gibbonSEPAID']) : null;
if (!$request || !$sepa) {
    $page->addError(__('The specified record cannot be found.'));
    return;
}

$entered = SepaChangeRequestGateway::clearValues($request);
$current = SepaChangeRequestGateway::clearValues($sepa);
$cadences = SepaGateway::getWithdrawCadenceOptions();
$isPending = $request['status'] === 'Pending';
$fullIban = function ($iban) {
    return $iban === null ? '[' . __('cannot decrypt - check the encryption key') . ']' : IbanCipher::format($iban);
};

echo '<h2>' . sprintf(__('SEPA change of family %s'), $e($request['familyName'])) . '</h2>';
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

// Flags
$flags = $isPending ? $gateway->getRequestFlags($request) : [];
$errors = 0;
foreach ($flags as $flag) {
    $errors += $flag['severity'] === 'Error' ? 1 : 0;
    echo "<div class='" . ($flag['severity'] === 'Error' ? 'error' : 'warning') . "'><strong>" . ($flag['severity'] === 'Error' ? __('Error') : __('Warning')) . ':</strong> ' . $e($flag['text']) . '</div>';
}
if ($isPending && empty($flags)) {
    echo "<div class='success'>" . __('No problems found.') . '</div>';
}

// Current and entered values
$rows = [
    __('Account holder') => [$current['payer'], $entered['payer']],
    __('IBAN') => [$fullIban($current['IBAN']), $fullIban($entered['IBAN'])],
    __('BIC') => [$current['BIC'], $entered['BIC']],
    __('Withdrawal cadence') => [$cadences[$current['withdrawCadence']] ?? $current['withdrawCadence'], $cadences[$entered['withdrawCadence']] ?? $entered['withdrawCadence']],
    __('Preferred withdrawal day') => [$current['withdrawDay'], $entered['withdrawDay']],
    __('Date of SEPA signature') => [$current['SEPA_signedDate'] ? Format::date($current['SEPA_signedDate']) : '', $entered['SEPA_signedDate'] ? Format::date($entered['SEPA_signedDate']) : ''],
];
$html = '<table class="fullWidth colorOddEven"><thead><tr><th></th><th>' . __('Current') . '</th><th>' . __('Entered by the family') . '</th></tr></thead><tbody>';
foreach ($rows as $label => [$old, $new]) {
    $changed = (string) $old !== (string) $new;
    $html .= '<tr><td><strong>' . $e($label) . '</strong></td><td>' . $e($old) . '</td><td' . ($changed ? ' style="font-weight: bold; background: #ffe;"' : '') . '>' . $e($new) . ($changed ? ' *' : '') . '</td></tr>';
}
$html .= '</tbody></table><p><small>* ' . __('differs from the current data') . '</small></p>';
echo $html;

echo '<h3>' . __('Log of this SEPA') . '</h3>';
if (isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_change_log.php')) {
    echo '<p><a href="' . $session->get('absoluteURL') . '/index.php?q=/modules/Sepa/sepa_change_log.php&sepa=' . (int) $request['gibbonSEPAID'] . '">' . __('Open the full log') . '</a></p>';
}
echo sepaRenderChangeLog($gateway->selectLog($request['gibbonSEPAID'], 50), false);

if (!$isPending) {
    return;
}

$form = Form::create('sepaChangeReview', $session->get('absoluteURL') . '/modules/Sepa/sepa_change_reviewProcess.php');
$form->addHiddenValue('address', $session->get('address'));
$form->addHiddenValue('gibbonSEPAChangeRequestID', $request['gibbonSEPAChangeRequestID']);

if ($errors > 0) {
    $row = $form->addRow();
    $row->addLabel('confirmErrors', __('Approve despite errors'))->description(__('The entered data has errors. Only tick this when the data is really correct.'));
    $row->addCheckbox('confirmErrors')->setValue('1');
}
$row = $form->addRow();
$row->addLabel('reviewNote', __('Note'))->description(__('Optional, stored with the decision.'));
$row->addTextArea('reviewNote')->setRows(3);

$row = $form->addRow();
$row->addContent('<a href="' . $session->get('absoluteURL') . '/index.php?q=/modules/Sepa/sepa_change_requests.php">' . __('Back') . '</a>');
$row->addContent('<button type="submit" name="decision" value="approve">' . __('Approve') . '</button> '
    . '<button type="submit" name="decision" value="reject">' . __('Reject') . '</button>')->setClass('right');

echo $form->getOutput();
