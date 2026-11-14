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
use Gibbon\Module\Sepa\Domain\FamilyMerger;

include '../../gibbon.php';
require_once __DIR__ . '/moduleFunctions.php';

$_POST = $container->get(Validator::class)->sanitize($_POST);

$URL = $gibbon->session->get('absoluteURL') . '/index.php?q=/modules/Sepa/sepa_merge.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_merge.php')) {
    header('Location: ' . $URL . '&return=error0');
    exit;
}

$dropID = FamilyMerger::id($_POST['gibbonSEPAID'] ?? 0);
$keepID = FamilyMerger::id($_POST['targetSEPAID'] ?? 0);
if ($dropID <= 0 || $keepID <= 0 || $dropID === $keepID) {
    header('Location: ' . $URL . '&return=error1');
    exit;
}

$fields = [];
foreach (FamilyMerger::SEPA_FIELDS as $field) {
    $fields[$field] = ($_POST['sepaFields'][$field] ?? 'target') === 'source' ? 'source' : 'target';
}

try {
    $report = (new FamilyMerger($connection2))->mergeSepaRecords($dropID, $keepID, $fields);
} catch (\Exception $e) {
    // everything is rolled back
    header('Location: ' . $URL . '&return=error2&gibbonSEPAID=' . $dropID);
    exit;
}

$_SESSION[$guid]['sepaMergeReport'] = $report;
header('Location: ' . $URL . '&return=success0');
exit;
