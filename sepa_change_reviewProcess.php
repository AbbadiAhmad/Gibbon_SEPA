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
        // What the reviewer accepts per field, with the corrected values
        $sepa = $gateway->getSepaRow($request['gibbonSEPAID']);
        if (!$sepa) {
            header('Location: ' . $URLList . '&return=error1');
            exit;
        }
        $posted = is_array($_POST['value'] ?? null) ? $_POST['value'] : [];
        $accept = is_array($_POST['accept'] ?? null) ? $_POST['accept'] : [];
        $values = SepaChangeRequestGateway::cleanReviewValues($posted, SepaChangeRequestGateway::enteredValues($request, $sepa));
        $decision = SepaChangeRequestGateway::buildDecision($request, $sepa, $accept, $values);

        // The checks are made on the values that are saved; errors need a confirmation.
        // The reviewer's values are kept for the page.
        $errors = array_filter($gateway->getFlagsForValues($decision['finalClear'], $request), function ($flag) {
            return $flag['severity'] === 'Error';
        });
        if (!empty($errors) && empty($_POST['confirmErrors'])) {
            $_SESSION[$guid]['sepaReviewDraft'][$id] = ['value' => $posted, 'accept' => $accept, 'reviewNote' => $note];
            header('Location: ' . $URL . '&return=error3');
            exit;
        }

        // Nothing accepted that differs from the current data: that is a rejection
        if (empty(SepaChangeRequestGateway::describeChanges($sepa, $decision['final']))) {
            $gateway->reject($id, $personID, $note);
        } else {
            $gateway->approve($id, $personID, $note, $decision);
            // Read back: the SEPA must hold the approved values now
            if (!SepaChangeRequestGateway::holdsValues($gateway->getSepaRow($request['gibbonSEPAID']), $decision['final'])) {
                header('Location: ' . $URL . '&return=error2');
                exit;
            }
        }
    }
} catch (\PDOException | \RuntimeException $e) {
    header('Location: ' . $URL . '&return=error2');
    exit;
}

header('Location: ' . $URLList . '&return=success0');
exit;
