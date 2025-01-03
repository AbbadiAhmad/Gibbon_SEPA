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

include '../../gibbon.php';

$URLDelete = $gibbon->session->get('absoluteURL') . '/index.php?q=/modules/' . $gibbon->session->get('module') . '/sepa_family_view.php';
$URL = $gibbon->session->get('absoluteURL') . '/index.php?q=/modules/' . $gibbon->session->get('module') . '/sepa_family_delete.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_family_delete.php')) {
    // Access denied
    $URL = $URL . '&return=error0';
    header("Location: {$URL}");
} else {
    // Proceed!
    $gibbonSEPAID = htmlspecialchars($_GET['gibbonSEPAID'] ?? '', ENT_QUOTES, 'UTF-8');
    //Check if gibbonPersonID specified
    if ($gibbonSEPAID == '') {
        $URL .= '&return=error1';
        header("Location: {$URL}");
    } else {
        try {
            $data = array('gibbonSEPAID' => $gibbonSEPAID);
            $sql = 'SELECT * FROM gibbonSEPA WHERE gibbonSEPAID=:gibbonSEPAID';
            $result = $connection2->prepare($sql);
            $result->execute($data);
        } catch (PDOException $e) {
            $URL .= '&return=error2';
            header("Location: {$URL}");
            exit();
        }

        if ($result->rowCount() != 1) {
            $URL .= '&return=error2';
            header("Location: {$URL}");
        } else {
            //Write to database
            try {
                $data = array('gibbonSEPAID' => $gibbonSEPAID);
                $sql = 'DELETE FROM gibbonSEPA WHERE gibbonSEPAID=:gibbonSEPAID';
                $result = $connection2->prepare($sql);
                $result->execute($data);
            } catch (PDOException $e) {
                $URL .= '&return=error2';
                header("Location: {$URL}");
                exit();
            }

            $URLDelete = $URLDelete . '&return=success0';
            header("Location: {$URLDelete}");
        }
    }
}
