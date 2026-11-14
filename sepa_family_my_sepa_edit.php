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
use Gibbon\Module\Sepa\Domain\SepaGateway;
use Gibbon\Module\Sepa\Domain\SepaChangeRequestGateway;

// Module includes
require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_family_my_sepa_edit.php')) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$page->breadcrumbs->add(__('My Family SEPA'), 'sepa_family_my_sepa.php');
$page->breadcrumbs->add(__('Change my SEPA data'));

$SepaGateway = $container->get(SepaGateway::class);
$requestGateway = $container->get(SepaChangeRequestGateway::class);

$families = $SepaGateway->getfamilyPerPerson($_SESSION[$guid]['gibbonPersonID']);
if (count($families) !== 1) {
    $page->addError(__('The SEPA information is missing for your family. Please ask the school\'s administration for more information.'));
    return;
}
$gibbonFamilyID = array_values($families)[0];
$sepas = $SepaGateway->getFamilySEPA($gibbonFamilyID);
if (count($sepas) > 1 || !$requestGateway->isFamilyAdult($_SESSION[$guid]['gibbonPersonID'], $gibbonFamilyID)) {
    $page->addError(__('You can not change the SEPA data of this family. Please contact the school administration.'));
    return;
}
// Without SEPA the family enters a new one
$isNew = empty($sepas);
$sepa = $sepas[0] ?? SepaChangeRequestGateway::emptySepa();

// The values shown: the entries waiting for approval, otherwise the current data
$pending = $requestGateway->getPending($sepa['gibbonSEPAID'], $gibbonFamilyID);
$values = $pending ? SepaChangeRequestGateway::enteredValues($pending, $sepa) : SepaChangeRequestGateway::clearValues($sepa);
$current = SepaChangeRequestGateway::clearValues($sepa);

if (!empty($_SESSION[$guid]['sepaMyError'])) {
    echo "<div class='error'>" . htmlspecialchars($_SESSION[$guid]['sepaMyError'], ENT_QUOTES, 'UTF-8') . '</div>';
    unset($_SESSION[$guid]['sepaMyError']);
}
echo '<h2>' . ($isNew ? __('Enter my SEPA data') : __('Change my SEPA data')) . '</h2>';
echo "<div class='message'>" . ($isNew
    ? __('Your family has no SEPA yet. What you enter is checked by the school administration before it is used. You can change or discard your entries at any time.')
    : __('Your changes are checked by the school administration before they apply. Until then, the current data stays valid. You can change or discard your entries at any time.')) . '</div>';

$form = Form::create('sepaChange', $session->get('absoluteURL') . '/modules/Sepa/sepa_family_my_sepa_editProcess.php');
$form->addHiddenValue('address', $session->get('address'));
$form->addHiddenValue('action', 'save');

$row = $form->addRow();
$row->addLabel('payer', __('Account holder'))->description(__('First and last name of the owner of the bank account.'));
$row->addTextField('payer')->required()->maxLength(255)->setValue($values['payer']);

$row = $form->addRow();
if ($isNew) {
    $row->addLabel('IBAN', __('IBAN'))->description(__('IBAN of the bank account the money is withdrawn from.'));
    $row->addTextField('IBAN')->maxLength(40)->required()->setValue($pending ? (string) $values['IBAN'] : '');
} else {
    $row->addLabel('IBAN', __('IBAN'))->description($pending
        ? __('The IBAN you entered. Change it if needed.')
        : sprintf(__('Current IBAN: %s. Leave empty to keep it.'), $current['IBAN'] ? \Gibbon\Module\Sepa\Security\IbanCipher::maskForDisplay($current['IBAN']) : '-'));
    $row->addTextField('IBAN')->maxLength(40)->setValue($pending ? (string) $values['IBAN'] : '');
}

$row = $form->addRow();
$row->addLabel('BIC', __('BIC'));
$row->addTextField('BIC')->maxLength(11)->setValue($values['BIC']);

$row = $form->addRow();
$row->addLabel('withdrawCadence', __('Withdrawal cadence'))->description(__('How often the money is withdrawn.'));
$row->addSelect('withdrawCadence')->fromArray(SepaGateway::getWithdrawCadenceOptions())->required()->selected($values['withdrawCadence'] ?: 'Monthly');

$row = $form->addRow();
$row->addLabel('withdrawDay', __('Preferred withdrawal day'))->description(__('Preferred day of the month for the withdrawal.'));
$row->addSelect('withdrawDay')->fromArray(SepaGateway::getWithdrawDayOptions())->placeholder()->selected($values['withdrawDay']);

$row = $form->addRow();
$row->addLabel('SEPA_signedDate', __('Date of SEPA signature'))->description($current['SEPA_signedDate']
    ? sprintf(__('Can not be earlier than the existing date (%s).'), \Gibbon\Services\Format::date($current['SEPA_signedDate']))
    : '');
$signedField = $row->addDate('SEPA_signedDate');
if ($values['SEPA_signedDate']) {
    // the field takes the database date (Y-m-d) and shows it in the date format of the installation
    $signedField->setDateFromValue($values['SEPA_signedDate']);
}

$row = $form->addRow();
$row->addContent('<a href="' . $session->get('absoluteURL') . '/index.php?q=/modules/Sepa/sepa_family_my_sepa.php">' . __('Back') . '</a>');
$row->addSubmit(__('Save for approval'));

echo $form->getOutput();
