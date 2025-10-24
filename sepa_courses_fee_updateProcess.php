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
use Gibbon\Module\Sepa\Domain\CoursesFeeGateway;


$_POST = $container->get(Validator::class)->sanitize($_POST);

$URL = $gibbon->session->get('absoluteURL') . '/index.php?q=/modules/' . $gibbon->session->get('module') . '/sepa_courses_fee_view.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_payment_view.php')) {
    // Access denied
    $URL = $URL . '&return=error0';
    header("Location: {$URL}");
} else {

    $CoursesFeeGateway = $container->get(CoursesFeeGateway::class);
    $criteria = $CoursesFeeGateway->newQueryCriteria(true)
        ->fromPOST();
    $coursesFee = $CoursesFeeGateway->queryCoursesFees($criteria, $_SESSION[$guid]["gibbonSchoolYearID"]);
    $error = false;
    foreach ($coursesFee as $course) {
        if (!empty($_POST[$course['gibbonCourseID']])) {
            $data = array(
                'gibbonCourseID' => $course['gibbonCourseID'],
                'fees' => $_POST[$course['gibbonCourseID']],
                'gibbonSepaCoursesCostID' => $course['gibbonSepaCoursesCostID']
            );

            try {
                $result = $CoursesFeeGateway->updateCoursesFees($data, $_SESSION[$guid]['username']);
                if(!$result) $error = true;
            } catch (PDOException $e) {
                $error = true;
            }

        }

    }
    if ($error) {
        $URL .= '&return=error2';
        header("Location: {$URL}");
    } else {
        $URL .= "&return=success0";
        header("Location: {$URL}");
    }


}
