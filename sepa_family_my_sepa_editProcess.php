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

use Gibbon\Data\Validator;
use Gibbon\Services\Format;
use Gibbon\Module\Sepa\Domain\SepaGateway;
use Gibbon\Module\Sepa\Domain\SepaChangeRequestGateway;
use Gibbon\Module\Sepa\Security\IbanCipher;

include '../../gibbon.php';
require_once __DIR__ . '/moduleFunctions.php';

$_POST = $container->get(Validator::class)->sanitize($_POST);

$base = $gibbon->session->get('absoluteURL') . '/index.php?q=/modules/Sepa/';
$URL = $base . 'sepa_family_my_sepa_edit.php';
$URLView = $base . 'sepa_family_my_sepa.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_family_my_sepa_edit.php')) {
    header('Location: ' . $URL . '&return=error0');
    exit;
}

// Only the adults of the own family, with exactly one SEPA record
$personID = $_SESSION[$guid]['gibbonPersonID'];
$sepaGateway = $container->get(SepaGateway::class);
$requestGateway = $container->get(SepaChangeRequestGateway::class);
$families = $sepaGateway->getfamilyPerPerson($personID);
if (count($families) !== 1) {
    header('Location: ' . $URL . '&return=error0');
    exit;
}
$gibbonFamilyID = array_values($families)[0];
$sepas = $sepaGateway->getFamilySEPA($gibbonFamilyID);
if (count($sepas) !== 1 || !$requestGateway->isFamilyAdult($personID, $gibbonFamilyID)) {
    header('Location: ' . $URL . '&return=error0');
    exit;
}
$sepa = $sepas[0];

// Discard the entries waiting for approval
if (($_POST['action'] ?? '') === 'discard') {
    $requestGateway->discardPending($sepa['gibbonSEPAID'], $personID);
    $_SESSION[$guid]['sepaMyNotice'] = __('Your changes have been discarded.');
    header('Location: ' . $URLView . '&return=success0');
    exit;
}

// Entered values
$payer = trim(preg_replace('/\s+/', ' ', (string) ($_POST['payer'] ?? '')));
$iban = IbanCipher::normalize($_POST['IBAN'] ?? '');
$bic = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['BIC'] ?? '')));
$cadence = in_array($_POST['withdrawCadence'] ?? '', SepaGateway::WITHDRAW_CADENCES, true) ? $_POST['withdrawCadence'] : $sepa['withdrawCadence'];
$day = (int) ($_POST['withdrawDay'] ?? 0);
$day = ($day >= 1 && $day <= 31) ? $day : null;

// Date of the signature: empty keeps the existing date, never earlier than the existing date
$signed = !empty($_POST['SEPA_signedDate']) ? Format::dateConvert($_POST['SEPA_signedDate']) : '';
$existingSigned = (string) ($sepa['SEPA_signedDate'] ?? '');
if ($signed !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $signed)) {
    header('Location: ' . $URL . '&return=error3');
    exit;
}
if ($signed === '') {
    $signed = $existingSigned;
} elseif ($existingSigned !== '' && $signed < $existingSigned) {
    $_SESSION[$guid]['sepaMyError'] = sprintf(__('The date of the SEPA signature can not be earlier than the existing date (%s).'), Format::date($existingSigned));
    header('Location: ' . $URL);
    exit;
}

if ($payer === '' || mb_strlen($payer) > 255 || mb_strlen($iban) > 40 || strlen($bic) > 11) {
    header('Location: ' . $URL . '&return=error3');
    exit;
}

try {
    // An empty IBAN field keeps the current IBAN (it stays encrypted as it is)
    $storedIban = $iban === '' ? $sepa['IBAN'] : IbanCipher::encrypt($iban);
} catch (\RuntimeException $e) {
    header('Location: ' . $URL . '&return=error2');
    exit;
}

$values = ['payer' => $payer, 'IBAN' => $storedIban, 'BIC' => $bic ?: null, 'withdrawCadence' => $cadence, 'withdrawDay' => $day, 'SEPA_signedDate' => $signed ?: null];

// Nothing changed: no request is needed (and an open one is dropped)
$new = SepaChangeRequestGateway::clearValues($values);
$old = SepaChangeRequestGateway::clearValues($sepa);
$sameIban = IbanCipher::normalize((string) $new['IBAN']) === IbanCipher::normalize((string) $old['IBAN']);
if ($sameIban && $new['payer'] === $old['payer'] && $new['BIC'] === $old['BIC'] && $new['withdrawCadence'] === $old['withdrawCadence'] && (string) $new['withdrawDay'] === (string) $old['withdrawDay'] && $new['SEPA_signedDate'] === $old['SEPA_signedDate']) {
    $requestGateway->discardPending($sepa['gibbonSEPAID'], $personID);
    $_SESSION[$guid]['sepaMyNotice'] = __('Nothing is different from the current data, so there is nothing to approve.');
    header('Location: ' . $URLView . '&return=success0');
    exit;
}

try {
    $requestGateway->savePending($sepa['gibbonSEPAID'], $gibbonFamilyID, $values, $personID);
} catch (\PDOException $e) {
    header('Location: ' . $URL . '&return=error2');
    exit;
}

header('Location: ' . $URLView . '&return=success0');
exit;
