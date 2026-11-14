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
use Gibbon\Module\Sepa\Domain\BulkAdjustment;
use Gibbon\Module\Sepa\Domain\SepaPaymentAdjustmentGateway;

include '../../gibbon.php';
require_once __DIR__ . '/moduleFunctions.php';

$_POST = $container->get(Validator::class)->sanitize($_POST);

$base = $gibbon->session->get('absoluteURL') . '/index.php?q=/modules/Sepa/';
$URL = $base . 'sepa_payment_adjustment_bulk.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_payment_adjustment_bulk.php')) {
    header('Location: ' . $URL . '&return=error0');
    exit;
}

// The list can be entered once: the token of the preview is used up here
$token = (string) ($_POST['token'] ?? '');
$expected = (string) ($_SESSION[$guid]['sepaBulkToken'] ?? '');
unset($_SESSION[$guid]['sepaBulkToken']);
if ($token === '' || $expected === '' || !hash_equals($expected, $token)) {
    header('Location: ' . $URL . '&return=error1');
    exit;
}

$year = (string) ($_POST['academicYear'] ?? '');
$rows = is_array($_POST['rows'] ?? null) ? $_POST['rows'] : [];
if ($year === '' || !ctype_digit($year) || empty($rows)) {
    header('Location: ' . $URL . '&return=error1');
    exit;
}

// Only the ticked rows, with a valid SEPA, a description and an amount more than 0
$entries = [];
foreach ($rows as $sepaID => $row) {
    if (empty($row['apply']) || !is_array($row) || !ctype_digit((string) $sepaID)) {
        continue;
    }
    $amount = BulkAdjustment::number($row['amount'] ?? '');
    $description = trim((string) ($row['description'] ?? ''));
    if ($amount === null || $amount <= 0 || $amount > BulkAdjustment::MAX_AMOUNT || $description === '') {
        header('Location: ' . $URL . '&return=error3');
        exit;
    }
    $entries[(int) $sepaID] = [
        'amount' => $amount,
        'description' => mb_substr($description, 0, 2000),
        'note' => mb_substr(trim((string) ($row['note'] ?? '')), 0, 2000),
    ];
}
if (empty($entries)) {
    header('Location: ' . $URL . '&return=error1');
    exit;
}

$placeholders = implode(',', array_fill(0, count($entries), '?'));
$statement = $connection2->prepare("SELECT gibbonSEPAID FROM gibbonSEPA WHERE gibbonSEPAID IN ($placeholders)");
$statement->execute(array_keys($entries));
$existing = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
if (count($existing) !== count($entries)) {
    header('Location: ' . $URL . '&return=error1');
    exit;
}

// All or nothing
$gateway = $container->get(SepaPaymentAdjustmentGateway::class);
$username = $_SESSION[$guid]['username'];
$total = 0.0;
try {
    $connection2->beginTransaction();
    foreach ($entries as $sepaID => $entry) {
        $inserted = $gateway->insertAdjustment([
            'gibbonSEPAID' => $sepaID,
            'amount' => $entry['amount'],
            'description' => $entry['description'],
            'note' => $entry['note'],
            'academicYear' => $year,
        ], $username);
        if (!$inserted) {
            throw new \RuntimeException('insert failed');
        }
        $total += $entry['amount'];
    }
    $connection2->commit();
} catch (\Exception $e) {
    if ($connection2->inTransaction()) {
        $connection2->rollBack();
    }
    header('Location: ' . $URL . '&return=error2');
    exit;
}

$_SESSION[$guid]['sepaBulkNotice'] = sprintf(__m('%1$d adjustments entered, together %2$s.'), count($entries), number_format($total, 2));
header('Location: ' . $base . 'sepa_payment_adjustment_manage.php&return=success0');
exit;
