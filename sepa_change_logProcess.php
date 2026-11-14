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

$URL = $gibbon->session->get('absoluteURL') . '/index.php?q=/modules/Sepa/sepa_change_log.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_change_log.php')) {
    header('Location: ' . $URL . '&return=error0');
    exit;
}
if (empty($_POST['confirm'])) {
    header('Location: ' . $URL . '&return=error1');
    exit;
}

try {
    // the gateway sets the cut-off (two years), nothing newer is deleted
    $deleted = $container->get(SepaChangeRequestGateway::class)->purgeExpired();
} catch (\PDOException $e) {
    header('Location: ' . $URL . '&return=error2');
    exit;
}

$_SESSION[$guid]['sepaLogNotice'] = sprintf(__('%1$d log line(s) and %2$d decided request(s) older than two years were deleted.'), $deleted['log'], $deleted['requests']);
header('Location: ' . $URL . '&return=success0');
exit;
