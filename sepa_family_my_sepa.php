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
use Gibbon\Module\Sepa\Domain\SepaGateway;
use Gibbon\Module\Sepa\Security\IbanCipher;
use Gibbon\Data\Validator;

// Module includes
require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_family_my_sepa.php')) {
    $page->addError(__('You do not have access to this action.'));
} else {
    $page->breadcrumbs->add(__('My Family SEPA'));

    $_GET = $container->get(Validator::class)->sanitize($_GET);
    $SepaGateway = $container->get(SepaGateway::class);
    $e = function ($value) {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    };

    $gibbonFamilyIDs = $SepaGateway->getfamilyPerPerson($_SESSION[$guid]['gibbonPersonID']);
    $schoolYearID = $_SESSION[$guid]['gibbonSchoolYearID'];

    if (empty($gibbonFamilyIDs)) {
        echo __('The SEPA information is missing for your family. Please ask the school\'s administration for more information.');
        return;
    } elseif (count($gibbonFamilyIDs) > 1) {
        echo __('You are added to multiple families. Please ask the school\'s administration for more information');
        return;
    }
    $gibbonFamilyID = array_values($gibbonFamilyIDs)[0];
    $familyInfo = $SepaGateway->getFamilyInfo($gibbonFamilyID);
    $FamilySEPA = $SepaGateway->getFamilySEPA($gibbonFamilyID);

    echo '<h2>' . __('My Family SEPA') . '</h2>';
    if (!empty($familyInfo)) {
        echo '<h3>' . __('Family: ') . $e($familyInfo[0]['name'] ?? '') . '</h3>';
    }

    // Changes are made by the school administration only
    echo "<div class='warning'>"
        . __('If you want to change this data, please visit the school administration.')
        . '<br/>Wenn Sie diese Daten ändern möchten, wenden Sie sich bitte an die Schulverwaltung.'
        . '<br/>إذا أردتم تغيير هذه البيانات، يرجى مراجعة إدارة المدرسة.'
        . '</div>';

    if (empty($FamilySEPA)) {
        echo "<div class='error'>" . __('SEPA information is not available for this family.') . '</div>';
    } else {
        if (count($FamilySEPA) > 1) {
            echo "<div class='error'>" . __('More than one SEPA record is entered for this family. Please contact the school administration.') . '</div>';
        }
        $cadences = SepaGateway::getWithdrawCadenceOptions();

        foreach ($FamilySEPA as $sepa) {
            $iban = IbanCipher::decrypt($sepa['IBAN'] ?? '');
            $ibanText = $iban === null ? __('not available') : IbanCipher::maskForDisplay($iban);

            echo "<table class='smallIntBorder fullWidth' cellspacing='0'><tbody>";
            $rows = [
                __('Account holder') => $sepa['payer'],
                __('IBAN') => $ibanText,
                __('BIC') => $sepa['BIC'],
                __('Withdrawal cadence') => $cadences[$sepa['withdrawCadence']] ?? '',
                __('Preferred withdrawal day') => $sepa['withdrawDay'],
                __('Date of SEPA signature') => !empty($sepa['SEPA_signedDate']) ? \Gibbon\Services\Format::date($sepa['SEPA_signedDate']) : '',
            ];
            foreach ($rows as $label => $value) {
                echo '<tr><td style="width: 35%;"><strong>' . $e($label) . '</strong></td><td>' . $e($value) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
    }

    // Problems with the data (without internal details or data of other families)
    $issues = array_filter($SepaGateway->getSEPAIssues($schoolYearID, false, $gibbonFamilyID), function ($issue) {
        return $issue['code'] !== 'internal';
    });

    echo '<h3>' . __('Check of your SEPA data') . '</h3>';
    if (empty($issues)) {
        echo "<div class='success'>" . __('No problems found.') . '</div>';
    } else {
        foreach ($issues as $issue) {
            $text = $issue['problem'];
            if ($issue['code'] === 'sharedIban') {
                $text = __('The IBAN is also used by another family.');
            } elseif ($issue['code'] === 'decrypt') {
                $text = __('The IBAN is not available.');
            }
            echo "<div class='" . ($issue['severity'] === 'Error' ? 'error' : 'warning') . "'>"
                . '<strong>' . ($issue['severity'] === 'Error' ? __('Error') : __('Warning')) . ':</strong> ' . $e($text) . '</div>';
        }
        echo '<p>' . __('Please contact the school administration to correct this.') . '</p>';
    }
}
