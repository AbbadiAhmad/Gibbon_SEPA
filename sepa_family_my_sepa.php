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
    $page->addError(__m('You do not have access to this action.'));
} else {
    $page->breadcrumbs->add(__m('My Family SEPA'));

    $_GET = $container->get(Validator::class)->sanitize($_GET);
    $SepaGateway = $container->get(SepaGateway::class);
    $e = function ($value) {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    };

    $gibbonFamilyIDs = $SepaGateway->getfamilyPerPerson($_SESSION[$guid]['gibbonPersonID']);
    $schoolYearID = $_SESSION[$guid]['gibbonSchoolYearID'];

    if (empty($gibbonFamilyIDs)) {
        echo __m('The SEPA information is missing for your family. Please ask the school\'s administration for more information.');
        return;
    } elseif (count($gibbonFamilyIDs) > 1) {
        echo __m('You are added to multiple families. Please ask the school\'s administration for more information');
        return;
    }
    $gibbonFamilyID = array_values($gibbonFamilyIDs)[0];
    $familyInfo = $SepaGateway->getFamilyInfo($gibbonFamilyID);
    $FamilySEPA = $SepaGateway->getFamilySEPA($gibbonFamilyID);

    echo '<h2>' . __m('My Family SEPA') . '</h2>';
    if (!empty($familyInfo)) {
        echo '<h3>' . __m('Family: ') . $e($familyInfo[0]['name'] ?? '') . '</h3>';
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
            . __m('You can change this data. Your changes are checked by the school administration first; until then the data below stays valid.')
            . '</div>';
    }

    $cadences = SepaGateway::getWithdrawCadenceOptions();
    $hasSepa = !empty($FamilySEPA);
    if (!$hasSepa && !$canEdit) {
        // No SEPA for this family and no right to enter one: nothing to show, change or check
        echo "<div class='error'>" . __m('SEPA information is not available for this family.') . ' ' . __m('Please contact the school administration.') . '</div>';
        return;
    }
    if (!$hasSepa) {
        echo "<div class='warning'>" . __m('There is no SEPA for your family yet. You can enter the SEPA data here; the school administration checks it before it is used.') . '</div>';
    }
    {
        if (count($FamilySEPA) > 1) {
            echo "<div class='error'>" . __m('More than one SEPA record is entered for this family. Please contact the school administration.') . '</div>';
        }
        foreach ($FamilySEPA as $sepa) {
            $iban = IbanCipher::decrypt($sepa['IBAN'] ?? '');
            $ibanText = $iban === null ? __m('not available') : IbanCipher::maskForDisplay($iban);

            echo "<table class='smallIntBorder fullWidth' cellspacing='0'><tbody>";
            $rows = [
                __m('Account holder') => $sepa['payer'],
                __m('IBAN') => $ibanText,
                __m('BIC') => $sepa['BIC'],
                __m('Withdrawal cadence') => $cadences[$sepa['withdrawCadence']] ?? '',
                __m('Preferred withdrawal day') => $sepa['withdrawDay'],
                __m('Date of SEPA signature') => !empty($sepa['SEPA_signedDate']) && strpos($sepa['SEPA_signedDate'], '0000') !== 0 ? \Gibbon\Services\Format::date($sepa['SEPA_signedDate']) : '',
            ];
            foreach ($rows as $label => $value) {
                echo '<tr><td style="width: 35%;"><strong>' . $e($label) . '</strong></td><td>' . $e($value) . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        // History of changes of this SEPA (the IBAN half hidden)
        if (count($FamilySEPA) <= 1) {
            $history = $hasSepa
                ? $requestGateway->selectLog($FamilySEPA[0]['gibbonSEPAID'], 20)
                : $requestGateway->selectLog(null, 20, $gibbonFamilyID);
            if (!empty($history)) {
                // folded by default
                echo '<details style="margin: 1em 0;"><summary style="cursor: pointer; font-weight: bold;">' . __m('History of changes') . ' (' . count($history) . ')</summary>';
                echo sepaRenderChangeLog($history, false, true);
                echo '</details>';
            }
        }

        // Changes entered by the family: current and entered values until the administration decides
        if ($canEdit && count($FamilySEPA) <= 1) {
            // without SEPA the "current" data is empty and the request is for a new SEPA
            $sepa = $FamilySEPA[0] ?? SepaChangeRequestGateway::emptySepa();
            $editURL = $session->get('absoluteURL') . '/index.php?q=/modules/Sepa/sepa_family_my_sepa_edit.php';
            $pending = $requestGateway->getPending($sepa['gibbonSEPAID'], $gibbonFamilyID);

            if (!$pending) {
                echo '<p><a class="button" href="' . $e($editURL) . '">' . ($hasSepa ? __m('Change my SEPA data') : __m('Enter my SEPA data')) . '</a></p>';
            } else {
                $current = SepaChangeRequestGateway::clearValues($sepa);
                $entered = SepaChangeRequestGateway::enteredValues($pending, $sepa);
                $maskIban = function ($iban) {
                    return $iban === null ? __m('not available') : IbanCipher::maskForDisplay($iban);
                };

                echo '<h3>' . ($hasSepa ? __m('Your changes are waiting for approval') : __m('Your SEPA data is waiting for approval')) . '</h3>';
                echo '<p>' . sprintf($hasSepa
                    ? __m('Entered on %s. The school administration has to approve them; until then the current data is used.')
                    : __m('Entered on %s. The school administration has to approve it before the SEPA is used.'), $e(\Gibbon\Services\Format::dateTime($pending['timestampUpdated'] ?: $pending['timestampSubmitted']))) . '</p>';
                echo "<table class='smallIntBorder fullWidth' cellspacing='0'><thead><tr><th></th><th>" . __m('Current') . '</th><th>' . __m('Entered (waiting for approval)') . '</th></tr></thead><tbody>';
                $compare = [
                    __m('Account holder') => [$current['payer'], $entered['payer']],
                    __m('IBAN') => [$maskIban($current['IBAN']), $maskIban($entered['IBAN'])],
                    __m('BIC') => [$current['BIC'], $entered['BIC']],
                    __m('Withdrawal cadence') => [$cadences[$current['withdrawCadence']] ?? '', $cadences[$entered['withdrawCadence']] ?? ''],
                    __m('Preferred withdrawal day') => [$current['withdrawDay'], $entered['withdrawDay']],
                    __m('Date of SEPA signature') => [$current['SEPA_signedDate'] ? \Gibbon\Services\Format::date($current['SEPA_signedDate']) : '', $entered['SEPA_signedDate'] ? \Gibbon\Services\Format::date($entered['SEPA_signedDate']) : ''],
                ];
                foreach ($compare as $label => [$old, $new]) {
                    $changed = (string) $old !== (string) $new;
                    echo '<tr><td style="width: 35%;"><strong>' . $e($label) . '</strong></td><td>' . $e($old) . '</td><td' . ($changed ? ' style="font-weight: bold;"' : '') . '>' . $e($new) . '</td></tr>';
                }
                echo '</tbody></table>';

                // Problems of the entered values (no data of other families)
                $others = $entered['IBAN'] ? $requestGateway->getOtherFamiliesWithIban($entered['IBAN'], (int) $sepa['gibbonSEPAID']) : [];
                foreach (SepaChangeRequestGateway::validate($entered, $hasSepa ? $current : null, $requestGateway->getAdultSurnames($gibbonFamilyID), $others) as $flag) {
                    if (in_array($flag['code'], ['ibanChanged', 'payerChanged', 'signedChanged', 'newSepa'], true)) {
                        continue;
                    }
                    $text = $flag['code'] === 'sharedIban' ? __m('The IBAN is also used by another family.') : $flag['text'];
                    echo "<div class='" . ($flag['severity'] === 'Error' ? 'error' : 'warning') . "'><strong>" . ($flag['severity'] === 'Error' ? __m('Error') : __m('Warning')) . ':</strong> ' . $e($text) . '</div>';
                }

                echo '<p><a class="button" href="' . $e($editURL) . '">' . __m('Change my entries') . '</a></p>';
                $discard = Form::create('discardChanges', $session->get('absoluteURL') . '/modules/Sepa/sepa_family_my_sepa_editProcess.php');
                $discard->addHiddenValue('address', $session->get('address'));
                $discard->addHiddenValue('action', 'discard');
                $discard->addRow()->addSubmit(__m('Discard my changes'));
                echo $discard->getOutput();
            }
        }
    }

    // Without SEPA there is nothing to check
    if (!$hasSepa) {
        return;
    }

    // Problems with the data (without internal details or data of other families)
    $issues = array_filter($SepaGateway->getSEPAIssues($schoolYearID, false, $gibbonFamilyID), function ($issue) {
        return $issue['code'] !== 'internal';
    });

    echo '<h3>' . __m('Check of your SEPA data') . '</h3>';
    if (empty($issues)) {
        echo "<div class='success'>" . __m('No problems found.') . '</div>';
    } else {
        foreach ($issues as $issue) {
            $text = $issue['problem'];
            if ($issue['code'] === 'sharedIban') {
                $text = __m('The IBAN is also used by another family.');
            } elseif ($issue['code'] === 'decrypt') {
                $text = __m('The IBAN is not available.');
            }
            echo "<div class='" . ($issue['severity'] === 'Error' ? 'error' : 'warning') . "'>"
                . '<strong>' . ($issue['severity'] === 'Error' ? __m('Error') : __m('Warning')) . ':</strong> ' . $e($text) . '</div>';
        }
        echo '<p>' . __m('Please contact the school administration to correct this.') . '</p>';
    }
}
