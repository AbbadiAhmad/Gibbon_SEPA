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
use Gibbon\Module\Sepa\Domain\SepaChangeRequestGateway;

include '../../gibbon.php';
require_once __DIR__ . '/moduleFunctions.php';

$_POST = $container->get(Validator::class)->sanitize($_POST);

$base = $gibbon->session->get('absoluteURL') . '/index.php?q=/modules/Sepa/';
$URLList = $base . 'sepa_change_requests.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_change_review.php')) {
    header('Location: ' . $URLList . '&return=error0');
    exit;
}

$id = (int) ($_POST['gibbonSEPAChangeRequestID'] ?? 0);
$decision = $_POST['decision'] ?? '';
$note = trim((string) ($_POST['reviewNote'] ?? ''));
$URL = $base . 'sepa_change_review.php&gibbonSEPAChangeRequestID=' . $id;

$gateway = $container->get(SepaChangeRequestGateway::class);
$request = $gateway->getByID($id);
if (!$request || $request['status'] !== 'Pending' || !in_array($decision, ['approve', 'reject'], true)) {
    header('Location: ' . $URLList . '&return=error1');
    exit;
}

$personID = $_SESSION[$guid]['gibbonPersonID'];

try {
    if ($decision === 'reject') {
        $gateway->reject($id, $personID, $note);
    } else {
        // The checks are made again here, errors need a confirmation
        $errors = array_filter($gateway->getRequestFlags($request), function ($flag) {
            return $flag['severity'] === 'Error';
        });
        if (!empty($errors) && empty($_POST['confirmErrors'])) {
            header('Location: ' . $URL . '&return=error3');
            exit;
        }
        $gateway->approve($id, $personID, $note);
    }
} catch (\PDOException $e) {
    header('Location: ' . $URL . '&return=error2');
    exit;
}

header('Location: ' . $URLList . '&return=success0');
exit;
