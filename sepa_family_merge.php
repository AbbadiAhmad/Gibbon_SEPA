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
use Gibbon\Data\Validator;
use Gibbon\Module\Sepa\Domain\FamilyMerger;
use Gibbon\Module\Sepa\Security\IbanCipher;

// Module includes
require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_family_merge.php')) {
    $page->addError(__m('You do not have access to this action.'));
    return;
}

$page->breadcrumbs->add(__m('Merge Families'));

$_GET = $container->get(Validator::class)->sanitize($_GET);
$_POST = $container->get(Validator::class)->sanitize($_POST);

$esc = function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$personName = function ($person) use ($esc) {
    return $esc(trim(($person['title'] ?? '') . ' ' . $person['preferredName'] . ' ' . $person['surname']));
};

$pageURL = $session->get('absoluteURL') . '/index.php?q=/modules/Sepa/sepa_family_merge.php';
$merger = new FamilyMerger($connection2);

// Result of the last merge (set by the process page)
if (!empty($_SESSION[$guid]['sepaMergeReport'])) {
    echo '<div class="success"><strong>' . __m('The families have been merged.') . '</strong><ul>';
    foreach ($_SESSION[$guid]['sepaMergeReport'] as $line) {
        echo '<li>' . $esc($line) . '</li>';
    }
    echo '</ul></div>';
    unset($_SESSION[$guid]['sepaMergeReport']);
}

$isPreview = ($_POST['step'] ?? '') === 'preview';
$source = $isPreview ? $_POST : $_GET;
$keepFamilyID = FamilyMerger::id($source['keepFamilyID'] ?? 0);
$dropFamilyID = FamilyMerger::id($source['dropFamilyID'] ?? 0);

echo '<p>' . __m('Use this when one family was registered twice (for example once by the mother and once by the father). The members, the family data and the SEPA of the second family are moved into the family to keep.') . '</p>';

// STEP 1: choose the two families
$options = $merger->getFamilyOptions();
$form = Form::create('mergePick', $session->get('absoluteURL') . '/index.php', 'get');
$form->setClass('noIntBorder fullWidth');
$form->addHiddenValue('q', '/modules/Sepa/sepa_family_merge.php');
$row = $form->addRow();
$row->addLabel('keepFamilyID', __m('Family to keep'))->description(__m('The users, family data and SEPA are merged into this family.'));
$row->addSelect('keepFamilyID')->fromArray($options)->selected($keepFamilyID ?: '')->placeholder()->required();
$row = $form->addRow();
$row->addLabel('dropFamilyID', __m('Family to merge into it'))->description(__m('This family is emptied, its SEPA is merged and then deleted.'));
$row->addSelect('dropFamilyID')->fromArray($options)->selected($dropFamilyID ?: '')->placeholder()->required();
$row = $form->addRow();
$row->addSubmit(__m('Compare'));
echo $form->getOutput();

if ($keepFamilyID <= 0 || $dropFamilyID <= 0) {
    return;
}
if ($keepFamilyID === $dropFamilyID) {
    $page->addError(__m('A family can not be merged into itself.'));
    return;
}

$keepFamily = $merger->getFamily($keepFamilyID);
$dropFamily = $merger->getFamily($dropFamilyID);
if (!$keepFamily || !$dropFamily) {
    $page->addError(__m('The specified record cannot be found.'));
    return;
}
$sepaRows = ['keep' => $merger->getSepaRows($keepFamilyID), 'drop' => $merger->getSepaRows($dropFamilyID)];
foreach ($sepaRows as $rows) {
    if (count($rows) > 1) {
        $page->addError(__m('A family has more than one SEPA. Fix this first.'));
        return;
    }
}
$keepSepa = $sepaRows['keep'][0] ?? null;
$dropSepa = $sepaRows['drop'][0] ?? null;

$members = [
    'keep' => ['Adult' => $merger->getMembers($keepFamilyID, 'Adult'), 'Child' => $merger->getMembers($keepFamilyID, 'Child')],
    'drop' => ['Adult' => $merger->getMembers($dropFamilyID, 'Adult'), 'Child' => $merger->getMembers($dropFamilyID, 'Child')],
];

$ibanOf = function ($sepa) use ($esc) {
    if (!$sepa || $sepa['IBAN'] === null || $sepa['IBAN'] === '') {
        return '';
    }
    return IbanCipher::format(IbanCipher::decryptForDisplay($sepa['IBAN']));
};

// ------------------------------------------------------------------------------------------------
// STEP 3: preview with the field choices
// ------------------------------------------------------------------------------------------------
if ($isPreview) {
    [$plan, $errors] = $merger->buildPlan($_POST);
    if (!empty($errors)) {
        foreach ($errors as $error) {
            $page->addError($error);
        }
        return;
    }

    $processURL = $session->get('absoluteURL') . '/modules/Sepa/sepa_family_mergeProcess.php';
    $form = Form::create('mergeConfirm', $processURL);
    $form->addHiddenValue('address', $session->get('address'));
    $form->addHiddenValue('keepFamilyID', $keepFamilyID);
    $form->addHiddenValue('dropFamilyID', $dropFamilyID);
    foreach ($_POST['targetAction'] ?? [] as $personID => $action) {
        $form->addHiddenValue('targetAction[' . (int) $personID . ']', $action === 'remove' ? 'remove' : 'keep');
    }
    foreach ($_POST['sourceAction'] ?? [] as $personID => $action) {
        $form->addHiddenValue('sourceAction[' . (int) $personID . ']', (string) $action);
    }

    // a table with one radio pair per field that differs
    $choiceTable = function ($fieldPrefix, array $rows, $leftTitle, $rightTitle) use ($esc) {
        if (empty($rows)) {
            return '<p><em>' . __m('Nothing differs, nothing to choose.') . '</em></p>';
        }
        $html = '<table class="fullWidth colorOddEven"><thead><tr><th>' . __m('Field') . '</th><th>' . $esc($leftTitle) . '</th><th>' . $esc($rightTitle) . '</th></tr></thead><tbody>';
        foreach ($rows as $field => [$left, $right]) {
            $name = $fieldPrefix . '[' . $field . ']';
            // keep the value of the first column unless it is empty
            $takeRight = trim((string) $left) === '' && trim((string) $right) !== '';
            $html .= '<tr><td>' . $esc($field) . '</td>'
                . '<td><label><input type="radio" name="' . $esc($name) . '" value="target"' . ($takeRight ? '' : ' checked') . '> ' . $esc($left) . '</label></td>'
                . '<td><label><input type="radio" name="' . $esc($name) . '" value="source"' . ($takeRight ? ' checked' : '') . '> ' . $esc($right) . '</label></td></tr>';
        }
        return $html . '</tbody></table>';
    };

    // Summary of what will happen
    $summary = '<ul>';
    $allMembers = [];
    foreach (['keep', 'drop'] as $side) {
        foreach ($members[$side] as $list) {
            foreach ($list as $person) {
                $allMembers[FamilyMerger::id($person['gibbonPersonID'])] = $person;
            }
        }
    }
    foreach ($plan['targetAction'] as $personID => $action) {
        if ($action === 'remove') {
            $summary .= '<li>' . sprintf(__m('%s is removed from %s.'), $personName($allMembers[$personID]), $esc($keepFamily['name'])) . '</li>';
        }
    }
    foreach ($plan['sourceAction'] as $personID => $action) {
        $name = $personName($allMembers[$personID]);
        if ($action === 'move') {
            $summary .= '<li>' . sprintf(__m('%s moves to %s.'), $name, $esc($keepFamily['name'])) . '</li>';
        } elseif ($action === 'remove') {
            $summary .= '<li>' . sprintf(__m('%s is removed from %s (the user itself is not deleted).'), $name, $esc($dropFamily['name'])) . '</li>';
        } else {
            $summary .= '<li>' . sprintf(__m('%s is merged with %s.'), $name, $personName($allMembers[$plan['mergeInto'][$personID]])) . '</li>';
        }
    }
    if ($dropSepa && $keepSepa) {
        $counts = $merger->getSepaLinkCounts($dropSepa['gibbonSEPAID']);
        $summary .= '<li>' . sprintf(__m('The SEPA of %s is merged into the SEPA of %s: %d payments, %d adjustments and %d snapshots move over, then the SEPA is deleted.'), $esc($dropFamily['name']), $esc($keepFamily['name']), $counts['payments'], $counts['adjustments'], $counts['snapshots']) . '</li>';
    } elseif ($dropSepa) {
        $summary .= '<li>' . sprintf(__m('The SEPA of %s moves to %s.'), $esc($dropFamily['name']), $esc($keepFamily['name'])) . '</li>';
    }
    $summary .= '<li>' . sprintf(__m('The family %s stays as an empty family; delete it in the family administration when it is not needed.'), $esc($dropFamily['name'])) . '</li></ul>';

    $form->addRow()->addHeading('Summary', __m('What will happen'));
    $form->addRow()->addContent($summary);

    // Family data
    $rows = [];
    foreach (FamilyMerger::FAMILY_FIELDS as $field) {
        if (array_key_exists($field, $keepFamily) && (string) $keepFamily[$field] !== (string) $dropFamily[$field]) {
            $rows[$field] = [$keepFamily[$field], $dropFamily[$field]];
        }
    }
    $form->addRow()->addHeading('Family data', __m('Family data'));
    $form->addRow()->addContent($choiceTable('familyFields', $rows, $keepFamily['name'] . ' (' . __m('keep') . ')', $dropFamily['name']));

    // SEPA data
    if ($keepSepa && $dropSepa) {
        $rows = [];
        foreach (FamilyMerger::SEPA_FIELDS as $field) {
            if ($field === 'IBAN') {
                // the stored value is encrypted differently every time: compare the clear IBAN
                if (IbanCipher::normalize($ibanOf($keepSepa)) !== IbanCipher::normalize($ibanOf($dropSepa))) {
                    $rows[$field] = [$ibanOf($keepSepa), $ibanOf($dropSepa)];
                }
            } elseif ((string) $keepSepa[$field] !== (string) $dropSepa[$field]) {
                $rows[$field] = [$keepSepa[$field], $dropSepa[$field]];
            }
        }
        $form->addRow()->addHeading('SEPA data', __m('SEPA data'));
        if ($ibanOf($keepSepa) !== '' && IbanCipher::normalize($ibanOf($keepSepa)) === IbanCipher::normalize($ibanOf($dropSepa))) {
            $form->addRow()->addContent('<p>' . __m('Both families use the same IBAN.') . '</p>');
        }
        $form->addRow()->addContent($choiceTable('sepaFields', $rows, $keepFamily['name'] . ' (' . __m('keep') . ')', $dropFamily['name']));
    }

    // Users that are merged
    if (!empty($plan['mergeInto'])) {
        $form->addRow()->addHeading('Merged users', __m('Merged users'));
        foreach ($plan['mergeInto'] as $sourceID => $targetID) {
            $target = $merger->getPerson($targetID);
            $sourcePerson = $merger->getPerson($sourceID);
            $form->addRow()->addContent('<h4>' . sprintf(__m('%s (kept) and %s'), $personName($target), $personName($sourcePerson)) . '</h4>');
            $form->addRow()->addContent($choiceTable('personFields[' . (int) $sourceID . ']', $merger->getPersonDifferences($target, $sourcePerson), __m('Keep user') . ' ' . $targetID, __m('Other user') . ' ' . $sourceID));
        }
        $row = $form->addRow();
        $row->addLabel('moveRelated', __m('Move all other records'))->description(__m('Enrolments, attendance and everything else of the other user move to the kept user. The other user is set to Left and can not log in. Without it, only the family link and the chosen data are merged.'));
        $row->addCheckbox('moveRelated')->setValue('1')->checked('1');
    }

    $row = $form->addRow();
    $row->addContent('<a href="' . $esc($pageURL . '&keepFamilyID=' . $keepFamilyID . '&dropFamilyID=' . $dropFamilyID) . '">' . __m('Back') . '</a>');
    $row->addSubmit(__m('Merge families'));

    echo '<h2>' . __m('Check and merge') . '</h2>';
    echo '<div class="warning">' . __m('The merge changes the data of the users and the SEPA and can not be undone. Make a database backup first.') . '</div>';
    echo $form->getOutput();
    return;
}

// ------------------------------------------------------------------------------------------------
// STEP 2: the two families side by side
// ------------------------------------------------------------------------------------------------
$swapURL = $pageURL . '&keepFamilyID=' . $dropFamilyID . '&dropFamilyID=' . $keepFamilyID;

$infoTable = function (array $rows) use ($esc) {
    $html = '<table class="smallIntBorder fullWidth" cellspacing="0"><tbody>';
    foreach ($rows as $label => $value) {
        $html .= '<tr><td style="width:40%"><strong>' . $esc($label) . '</strong></td><td>' . $esc($value) . '</td></tr>';
    }
    return $html . '</tbody></table>';
};

$sepaInfo = function ($sepa) use ($merger, $ibanOf, $infoTable) {
    if (!$sepa) {
        return '<p><em>' . __m('This family has no SEPA.') . '</em></p>';
    }
    $counts = $merger->getSepaLinkCounts($sepa['gibbonSEPAID']);
    return $infoTable([
        __m('Payer') => $sepa['payer'],
        __m('IBAN') => $ibanOf($sepa),
        __m('BIC') => $sepa['BIC'],
        __m('Withdrawal cadence') => $sepa['withdrawCadence'],
        __m('Preferred withdrawal day') => $sepa['withdrawDay'],
        __m('Date of SEPA signature') => $sepa['SEPA_signedDate'],
        __m('Payments / adjustments') => $counts['payments'] . ' / ' . $counts['adjustments'],
    ]);
};

$familyInfo = function ($family) use ($infoTable) {
    return $infoTable([
        __m('Name') => $family['name'],
        __m('Status') => $family['status'] ?? '',
        __m('Address') => trim(($family['homeAddress'] ?? '') . ' ' . ($family['homeAddressDistrict'] ?? '') . ' ' . ($family['homeAddressCountry'] ?? '')),
    ]);
};

// Same name (and date of birth when both have one) is suggested as the same user
$suggestions = [];
$taken = [];
foreach (['Adult', 'Child'] as $role) {
    foreach ($members['drop'][$role] as $drop) {
        foreach ($members['keep'][$role] as $keep) {
            $keepID = FamilyMerger::id($keep['gibbonPersonID']);
            if (isset($taken[$keepID])) {
                continue;
            }
            $sameName = mb_strtolower(trim($drop['preferredName'] . ' ' . $drop['surname'])) === mb_strtolower(trim($keep['preferredName'] . ' ' . $keep['surname']));
            $sameDob = empty($drop['dob']) || empty($keep['dob']) || $drop['dob'] === $keep['dob'];
            if ($sameName && $sameDob) {
                $suggestions[FamilyMerger::id($drop['gibbonPersonID'])] = $keepID;
                $taken[$keepID] = true;
                break;
            }
        }
    }
}

$memberRows = function ($side, $role) use ($members, $personName, $esc, $suggestions) {
    $html = '';
    foreach ($members[$side][$role] as $person) {
        $personID = FamilyMerger::id($person['gibbonPersonID']);
        $detail = $esc(trim($person['email'] . ' ' . ($person['dob'] ? '(' . $person['dob'] . ')' : '')));
        $select = '';
        if ($side === 'keep') {
            $select = '<select name="targetAction[' . $personID . ']"><option value="keep">' . __m('Keep in family') . '</option><option value="remove">' . __m('Remove from family') . '</option></select>';
        } else {
            $select = '<select name="sourceAction[' . $personID . ']"><option value="move">' . __m('Move to the family to keep') . '</option><option value="remove">' . __m('Remove from family (do not move)') . '</option>';
            foreach ($members['keep'][$role] as $keep) {
                $keepID = FamilyMerger::id($keep['gibbonPersonID']);
                $selected = ($suggestions[$personID] ?? null) === $keepID ? ' selected' : '';
                $select .= '<option value="merge:' . $keepID . '"' . $selected . '>' . sprintf(__m('Same user as %s (merge)'), $personName($keep)) . '</option>';
            }
            $select .= '</select>';
        }
        $html .= '<tr><td>' . $personName($person) . '<br><small>' . $detail . ' ' . $esc($person['status']) . '</small></td><td>' . $select . '</td></tr>';
    }
    return $html ?: '<tr><td colspan="2"><em>' . __m('None') . '</em></td></tr>';
};

$column = function ($side, $family, $sepa, $title) use ($familyInfo, $sepaInfo, $memberRows) {
    return '<div style="flex:1 1 440px;min-width:320px;border:1px solid #bbb;padding:0.75rem;box-sizing:border-box">'
        . '<h3 style="margin-top:0">' . $title . '</h3>'
        . $familyInfo($family)
        . '<h4>' . __m('SEPA') . '</h4>' . $sepaInfo($sepa)
        . '<h4>' . __m('Adults') . '</h4><table class="fullWidth"><tbody>' . $memberRows($side, 'Adult') . '</tbody></table>'
        . '<h4>' . __m('Children') . '</h4><table class="fullWidth"><tbody>' . $memberRows($side, 'Child') . '</tbody></table>'
        . '</div>';
};

$sameIban = $keepSepa && $dropSepa && $ibanOf($keepSepa) !== '' && IbanCipher::normalize($ibanOf($keepSepa)) === IbanCipher::normalize($ibanOf($dropSepa));

$form = Form::create('mergeCompare', $pageURL);
$form->addHiddenValue('step', 'preview');
$form->addHiddenValue('keepFamilyID', $keepFamilyID);
$form->addHiddenValue('dropFamilyID', $dropFamilyID);

$sideBySide = '<div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-start">'
    . $column('keep', $keepFamily, $keepSepa, sprintf(__m('Keep: %s'), $esc($keepFamily['name'])))
    . $column('drop', $dropFamily, $dropSepa, sprintf(__m('Merge into it: %s'), $esc($dropFamily['name'])))
    . '</div>';

$form->addRow()->addContent('<p><a href="' . $esc($swapURL) . '">' . __m('Swap the two families') . '</a></p>'
    . ($sameIban ? '<p><strong>' . __m('Both families use the same IBAN.') . '</strong></p>' : '')
    . $sideBySide
    . '<p>' . __m('Choose per user what happens. Users registered in both families are suggested as "Same user"; the field-by-field choice follows in the next step.') . '</p>');

$row = $form->addRow();
$row->addFooter();
$row->addSubmit(__m('Next: choose the data'));

echo '<h2>' . __m('Compare the families') . '</h2>';
echo $form->getOutput();
