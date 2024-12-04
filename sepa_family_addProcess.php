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

include '../../gibbon.php';
include './moduleFunctions.php';

$URL = $gibbon->session->get('absoluteURL') . '/index.php?q=/modules/' . $gibbon->session->get('module') . '/sepa_family_add.php';
$URLSuccess = $gibbon->session->get('absoluteURL') . '/index.php?q=/modules/' . $gibbon->session->get('module') . '/sepa_family_view.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_family_add.php')) {
    // Access denied
    $URL = $URL . '&return=error0';
    header("Location: {$URL}");
} else {
    // Proceed!
    $sepaOwnerName = htmlspecialchars($_POST['sepaOwnerName'] ?? '', ENT_QUOTES, 'UTF-8');
    $SEPAIBAN = htmlspecialchars($_POST['SEPAIBAN'] ?? '', ENT_QUOTES, 'UTF-8');
    $SEPABIC = htmlspecialchars($_POST['SEPABIC'] ?? '', ENT_QUOTES, 'UTF-8');
    $FamilyID = htmlspecialchars($_POST['FamilyID'] ?? '', ENT_QUOTES, 'UTF-8');
    $note = htmlspecialchars($_POST['note'] ?? '', ENT_QUOTES, 'UTF-8');
    $SEPASignedDate = !empty($_POST['SEPASignedDate']) ? Format::dateConvert($_POST['SEPASignedDate']) : null;


    // Check that your required variables are present
    if (empty($sepaOwnerName) || empty($FamilyID)) {
        $URL = $URL . '&return=error3';
        header("Location: {$URL}");
        exit;
    } else {
        try {
            $data = array('gibbonFamilyID' => $FamilyID, 'SEPA_ownerName' => $sepaOwnerName, 'SEPA_IBAN' => $SEPAIBAN, 'SEPA_BIC' => $SEPABIC, 'SEPA_signedDate' => $SEPASignedDate, 'note' => $note, 'customData' => '{}');
            $sql = "INSERT INTO gibbonSEPA SET gibbonFamilyID=:gibbonFamilyID, SEPA_ownerName=:SEPA_ownerName, SEPA_IBAN=:SEPA_IBAN, SEPA_BIC=:SEPA_BIC, SEPA_signedDate=:SEPA_signedDate, note=:note, customData=:customData";
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
