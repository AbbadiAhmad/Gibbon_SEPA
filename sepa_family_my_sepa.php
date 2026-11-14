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
use Gibbon\Forms\Form;
use Gibbon\Module\Sepa\Domain\SepaGateway;
use Gibbon\Module\Sepa\Domain\SepaChangeRequestGateway;
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

    // Families with the permission enter changes themselves (approved by the school administration),
    // for all others the changes are made by the school administration only
    $canEdit = isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_family_my_sepa_edit.php');
    $requestGateway = $container->get(SepaChangeRequestGateway::class);
    $canEdit = $canEdit && $requestGateway->isFamilyAdult($_SESSION[$guid]['gibbonPersonID'], $gibbonFamilyID);

    if (!empty($_SESSION[$guid]['sepaMyNotice'])) {
        echo "<div class='success'>" . $e($_SESSION[$guid]['sepaMyNotice']) . '</div>';
        unset($_SESSION[$guid]['sepaMyNotice']);
    }

    // Only when there is exactly one SEPA the family can change it
    if ($canEdit && count($FamilySEPA) === 1) {
        echo "<div class='message'>"
            . __('You can change this data. Your changes are checked by the school administration first; until then the data below stays valid.')
            . '</div>';
    }

    if (empty($FamilySEPA)) {
        // No SEPA for this family: nothing to show, change or check
        echo "<div class='error'>" . __('SEPA information is not available for this family.') . ' ' . __('Please contact the school administration.') . '</div>';
        return;
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
                __('Date of SEPA signature') => !empty($sepa['SEPA_signedDate']) && strpos($sepa['SEPA_signedDate'], '0000') !== 0 ? \Gibbon\Services\Format::date($sepa['SEPA_signedDate']) : '',
            ];
            foreach ($rows as $label => $value) {
                echo '<tr><td style="width: 35%;"><strong>' . $e($label) . '</strong></td><td>' . $e($value) . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        // History of changes of this SEPA (the IBAN half hidden)
        if (count($FamilySEPA) === 1) {
            $history = $requestGateway->selectLog($FamilySEPA[0]['gibbonSEPAID'], 20);
            if (!empty($history)) {
                // folded by default
                echo '<details style="margin: 1em 0;"><summary style="cursor: pointer; font-weight: bold;">' . __('History of changes') . ' (' . count($history) . ')</summary>';
                echo sepaRenderChangeLog($history, false, true);
                echo '</details>';
            }
        }

        // Changes entered by the family: current and entered values until the administration decides
        if ($canEdit && count($FamilySEPA) === 1) {
            $sepa = $FamilySEPA[0];
            $editURL = $session->get('absoluteURL') . '/index.php?q=/modules/Sepa/sepa_family_my_sepa_edit.php';
            $pending = $requestGateway->getPendingBySEPAID($sepa['gibbonSEPAID']);

            if (!$pending) {
                echo '<p><a class="button" href="' . $e($editURL) . '">' . __('Change my SEPA data') . '</a></p>';
            } else {
                $current = SepaChangeRequestGateway::clearValues($sepa);
                $entered = SepaChangeRequestGateway::enteredValues($pending, $sepa);
                $maskIban = function ($iban) {
                    return $iban === null ? __('not available') : IbanCipher::maskForDisplay($iban);
                };

                echo '<h3>' . __('Your changes are waiting for approval') . '</h3>';
                echo '<p>' . sprintf(__('Entered on %s. The school administration has to approve them; until then the current data is used.'), $e(\Gibbon\Services\Format::dateTime($pending['timestampUpdated'] ?: $pending['timestampSubmitted']))) . '</p>';
                echo "<table class='smallIntBorder fullWidth' cellspacing='0'><thead><tr><th></th><th>" . __('Current') . '</th><th>' . __('Entered (waiting for approval)') . '</th></tr></thead><tbody>';
                $compare = [
                    __('Account holder') => [$current['payer'], $entered['payer']],
                    __('IBAN') => [$maskIban($current['IBAN']), $maskIban($entered['IBAN'])],
                    __('BIC') => [$current['BIC'], $entered['BIC']],
                    __('Withdrawal cadence') => [$cadences[$current['withdrawCadence']] ?? '', $cadences[$entered['withdrawCadence']] ?? ''],
                    __('Preferred withdrawal day') => [$current['withdrawDay'], $entered['withdrawDay']],
                    __('Date of SEPA signature') => [$current['SEPA_signedDate'] ? \Gibbon\Services\Format::date($current['SEPA_signedDate']) : '', $entered['SEPA_signedDate'] ? \Gibbon\Services\Format::date($entered['SEPA_signedDate']) : ''],
                ];
                foreach ($compare as $label => [$old, $new]) {
                    $changed = (string) $old !== (string) $new;
                    echo '<tr><td style="width: 35%;"><strong>' . $e($label) . '</strong></td><td>' . $e($old) . '</td><td' . ($changed ? ' style="font-weight: bold;"' : '') . '>' . $e($new) . '</td></tr>';
                }
                echo '</tbody></table>';

                // Problems of the entered values (no data of other families)
                $others = $entered['IBAN'] ? $requestGateway->getOtherFamiliesWithIban($entered['IBAN'], $sepa['gibbonSEPAID']) : [];
                foreach (SepaChangeRequestGateway::validate($entered, $current, $requestGateway->getAdultSurnames($gibbonFamilyID), $others) as $flag) {
                    if (in_array($flag['code'], ['ibanChanged', 'payerChanged', 'signedChanged'], true)) {
                        continue;
                    }
                    $text = $flag['code'] === 'sharedIban' ? __('The IBAN is also used by another family.') : $flag['text'];
                    echo "<div class='" . ($flag['severity'] === 'Error' ? 'error' : 'warning') . "'><strong>" . ($flag['severity'] === 'Error' ? __('Error') : __('Warning')) . ':</strong> ' . $e($text) . '</div>';
                }

                echo '<p><a class="button" href="' . $e($editURL) . '">' . __('Change my entries') . '</a></p>';
                $discard = Form::create('discardChanges', $session->get('absoluteURL') . '/modules/Sepa/sepa_family_my_sepa_editProcess.php');
                $discard->addHiddenValue('address', $session->get('address'));
                $discard->addHiddenValue('action', 'discard');
                $discard->addRow()->addSubmit(__('Discard my changes'));
                echo $discard->getOutput();
            }
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
