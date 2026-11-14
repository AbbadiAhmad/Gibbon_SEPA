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
use Gibbon\Module\Sepa\Domain\CoursesFeeGateway;

include '../../gibbon.php';
require_once __DIR__ . '/moduleFunctions.php';

$_POST = $container->get(Validator::class)->sanitize($_POST);

$URL = $gibbon->session->get('absoluteURL') . '/index.php?q=/modules/Sepa/sepa_courses_fee_view.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_courses_fee_view.php')) {
    header('Location: ' . $URL . '&return=error0');
    exit;
}

$schoolYearID = sepaSelectedSchoolYear($_POST, $gibbon->session->get('gibbonSchoolYearID'));
$URL .= '&schoolYearID=' . urlencode($schoolYearID);
if ($schoolYearID === 'all') {
    header('Location: ' . $URL . '&return=error1');
    exit;
}

try {
    $result = $container->get(CoursesFeeGateway::class)->copyFeesFromPreviousYear($schoolYearID, $_SESSION[$guid]['username']);
} catch (\Exception $e) {
    header('Location: ' . $URL . '&return=error2');
    exit;
}

if ($result === null) {
    // no previous academic year
    header('Location: ' . $URL . '&return=error1');
    exit;
}

$_SESSION[$guid]['sepaFeesCopyReport'] = $result;
header('Location: ' . $URL . '&return=success0');
exit;
