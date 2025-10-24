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

include '../../gibbon.php';
include './moduleFunctions.php';
use Gibbon\Module\Sepa\Domain\CustomFieldsGateway;


$_POST = $container->get(Validator::class)->sanitize($_POST);
$URL = $gibbon->session->get('absoluteURL') . '/index.php?q=/modules/' . $gibbon->session->get('module') . '/sepa_family_add.php';
$URLSuccess = $gibbon->session->get('absoluteURL') . '/index.php?q=/modules/' . $gibbon->session->get('module') . '/sepa_family_view.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_family_add.php')) {
    // Access denied
    $URL = $URL . '&return=error0';
    header("Location: {$URL}");
} else {
    // Proceed!
    $payer = htmlspecialchars($_POST['payer'] ?? '', ENT_QUOTES, 'UTF-8');
    $IBAN = htmlspecialchars($_POST['IBAN'] ?? '', ENT_QUOTES, 'UTF-8');
    $BIC = htmlspecialchars($_POST['BIC'] ?? '', ENT_QUOTES, 'UTF-8');
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
            $data = array('gibbonFamilyID' => $gibbonFamilyID, 'payer' => $payer, 'IBAN' => $IBAN, 'BIC' => $BIC, 'SEPA_signedDate' => $SEPA_signedDate, 'note' => $note, 'customData' => $customData);
            $sql = "INSERT INTO gibbonSEPA SET gibbonFamilyID=:gibbonFamilyID, payer=:payer, IBAN=:IBAN, BIC=:BIC, SEPA_signedDate=:SEPA_signedDate, note=:note, customData=:customData";
            $result = $connection2->prepare($sql);
            $result->execute($data);
        } catch (PDOException $e) {
            $URL .= '&return=error2';
            header("Location: {$URL}");
            exit();
        }
    }
    $AI = $connection2->lastInsertID();
    // Your SQL or Gateway insert query
    $URL .= "&return=success0&editID=$AI";
    header("Location: {$URLSuccess}");
}
