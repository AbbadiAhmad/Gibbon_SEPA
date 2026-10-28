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
use Gibbon\Services\Format;
use Gibbon\Data\Validator;
use Gibbon\Module\Sepa\Domain\CustomFieldsGateway;
use Gibbon\Module\Sepa\Domain\SepaGateway;
use Gibbon\Module\Sepa\Security\IbanCipher;
include '../../gibbon.php';
include './moduleFunctions.php';

$_POST = $container->get(Validator::class)->sanitize($_POST);
$URL = $gibbon->session->get('absoluteURL') . '/index.php?q=/modules/' . $gibbon->session->get('module') . '/sepa_family_edit.php';
$URLSuccess = $gibbon->session->get('absoluteURL') . '/index.php?q=/modules/' . $gibbon->session->get('module') . '/sepa_family_view.php';
$checkReturn = getSepaCheckReturnParams($_POST);
if (!empty($checkReturn)) {
    unset($checkReturn['returnTo']);
    $URLSuccess = $gibbon->session->get('absoluteURL') . '/index.php?q=/modules/' . $gibbon->session->get('module') . '/sepa_check.php&' . http_build_query($checkReturn);
}

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_family_edit.php')) {
    // Access denied
    $URL = $URL . '&return=error0';
    header("Location: {$URL}");
} else {
    // Proceed!
    $gibbonSEPAID = htmlspecialchars($_POST['gibbonSEPAID'] ?? '', ENT_QUOTES, 'UTF-8');
    if ($gibbonSEPAID == '') {
        $URL .= '&return=error1';
        header("Location: {$URL}");
    } else {

        // Proceed!
        $payer = htmlspecialchars($_POST['payer'] ?? '', ENT_QUOTES, 'UTF-8');
        $IBAN = IbanCipher::normalize($_POST['IBAN'] ?? '');
        $BIC = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_POST['BIC'] ?? ''));
    $withdrawCadence = in_array($_POST['withdrawCadence'] ?? '', SepaGateway::WITHDRAW_CADENCES, true) ? $_POST['withdrawCadence'] : 'Monthly';
    $withdrawDay = (int) ($_POST['withdrawDay'] ?? 0);
    $withdrawDay = ($withdrawDay >= 1 && $withdrawDay <= 31) ? $withdrawDay : null;
        $gibbonFamilyID = htmlspecialchars($_POST['gibbonFamilyID'] ?? '', ENT_QUOTES, 'UTF-8');
        $note = htmlspecialchars($_POST['note'] ?? '', ENT_QUOTES, 'UTF-8');
        $SEPA_signedDate = !empty($_POST['SEPA_signedDate']) ? Format::dateConvert($_POST['SEPA_signedDate']) : null;

        // custom fields
        $customFieldsGateway = $container->get(customFieldsGateway::class);
        $customFieldsData = $customFieldsGateway->getCustomFieldInitialData();
        foreach ($customFieldsData as $key => $value) {
            foreach ($customFieldsData as $key => $value) {
                if (isset($_POST[$key])) {
                    $customFieldsData[$key] = htmlspecialchars($_POST[$key] ?? '', ENT_QUOTES, 'UTF-8');
                }
            }
        }
        $customData = json_encode($customFieldsData);

        // Check that your required variables are present
        if (empty($payer) || empty($gibbonFamilyID)) {
            $URL = $URL . '&return=error3';
            header("Location: {$URL}");
            exit;
        } else {
            try {
                // The IBAN is encrypted before storage
                $sepaGateway = $container->get(SepaGateway::class);
                $encryptedIBAN = $sepaGateway->encryptIBAN($IBAN);

                $data = array('gibbonFamilyID' => $gibbonFamilyID, 'payer' => $payer, 'IBAN' => $encryptedIBAN, 'BIC' => $BIC ?: null, 'withdrawCadence' => $withdrawCadence, 'withdrawDay' => $withdrawDay, 'SEPA_signedDate' => $SEPA_signedDate, 'note' => $note, 'customData' => $customData, 'gibbonSEPAID' => $gibbonSEPAID);
                $sql = "UPDATE gibbonSEPA SET gibbonFamilyID=:gibbonFamilyID, payer=:payer, IBAN=:IBAN, BIC=:BIC, withdrawCadence=:withdrawCadence, withdrawDay=:withdrawDay, SEPA_signedDate=:SEPA_signedDate, note=:note, customData=:customData WHERE gibbonSEPAID=:gibbonSEPAID";
                $result = $connection2->prepare($sql);
                $result->execute($data);
            } catch (PDOException | \RuntimeException $e) {
                $URL .= '&return=error2';
                header("Location: {$URL}");
                exit();
            }
        }

        header("Location: {$URLSuccess}&return=success0");

    }
}