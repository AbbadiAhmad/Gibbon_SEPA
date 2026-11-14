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

$URL = $gibbon->session->get('absoluteURL') . '/index.php?q=/modules/Sepa/sepa_family_merge.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_family_merge.php')) {
    header('Location: ' . $URL . '&return=error0');
    exit;
}

$merger = new FamilyMerger($connection2);
[$plan, $errors] = $merger->buildPlan($_POST);
if (!empty($errors)) {
    header('Location: ' . $URL . '&return=error1');
    exit;
}

try {
    $report = $merger->merge($plan);
} catch (\Exception $e) {
    // everything is rolled back
    header('Location: ' . $URL . '&return=error2&keepFamilyID=' . $plan['keepFamilyID'] . '&dropFamilyID=' . $plan['dropFamilyID']);
    exit;
}

$_SESSION[$guid]['sepaMergeReport'] = $report;
header('Location: ' . $URL . '&return=success0');
exit;
