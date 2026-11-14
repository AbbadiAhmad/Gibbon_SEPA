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
use Gibbon\Module\Sepa\Domain\SepaGateway;
use Gibbon\Module\Sepa\Security\IbanCipher;

require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_merge.php')) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$page->breadcrumbs->add(__('Check SEPA Data'), 'sepa_check.php');
$page->breadcrumbs->add(__('Merge SEPA'));

$_GET = $container->get(Validator::class)->sanitize($_GET);
$esc = function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$checkURL = $session->get('absoluteURL') . '/index.php?q=/modules/Sepa/sepa_check.php';
$merger = new FamilyMerger($connection2);

// Result of the last merge (set by the process page)
if (!empty($_SESSION[$guid]['sepaMergeReport'])) {
    echo '<div class="success"><ul>';
    foreach ($_SESSION[$guid]['sepaMergeReport'] as $line) {
        echo '<li>' . $esc($line) . '</li>';
    }
    echo '</ul></div>';
    unset($_SESSION[$guid]['sepaMergeReport']);
}

$dropID = FamilyMerger::id($_GET['gibbonSEPAID'] ?? 0);
if ($dropID <= 0) {
    echo '<p><a href="' . $esc($checkURL) . '">' . __('Back') . '</a></p>';
    return;
}
$drop = $merger->getSepa($dropID);
if (!$drop) {
    $page->addError(__('The specified record cannot be found.'));
    return;
}

$ibanOf = function ($sepa) {
    return empty($sepa['IBAN']) ? '' : IbanCipher::format(IbanCipher::decryptForDisplay($sepa['IBAN']));
};
$describe = function ($sepa) use ($ibanOf) {
    return ($sepa['familyName'] ?? __('(family deleted)')) . ' - ' . $sepa['payer'] . ' - ' . $ibanOf($sepa) . ' (' . FamilyMerger::id($sepa['gibbonSEPAID']) . ')';
};

echo '<h2>' . __('Merge SEPA') . '</h2>';
echo '<p>' . sprintf(__('SEPA to merge away: %s'), '<strong>' . $esc($describe($drop)) . '</strong>') . '</p>';
echo '<p>' . __('Its payments, adjustments and snapshots move to the SEPA you choose, then this SEPA is deleted. The family and users are not changed; use Merge Families for that.') . '</p>';

// the SEPAs it can be merged into: of existing families only
$targets = [];
$suggested = '';
$dropIban = IbanCipher::normalize($ibanOf($drop));
foreach ($container->get(SepaGateway::class)->getAllSEPAWithFamily() as $sepa) {
    $id = FamilyMerger::id($sepa['gibbonSEPAID']);
    if ($id === $dropID || $sepa['familyName'] === null) {
        continue;
    }
    $targets[$id] = $describe($sepa);
    // same IBAN is the most likely duplicate
    if ($suggested === '' && $dropIban !== '' && IbanCipher::normalize($ibanOf($sepa)) === $dropIban) {
        $suggested = $id;
    }
}

$keepID = FamilyMerger::id($_GET['targetSEPAID'] ?? 0);
if ($keepID <= 0 && $suggested !== '') {
    $keepID = $suggested;
}

$form = Form::create('sepaMergePick', $session->get('absoluteURL') . '/index.php', 'get');
$form->setClass('noIntBorder fullWidth');
$form->addHiddenValue('q', '/modules/Sepa/sepa_merge.php');
$form->addHiddenValue('gibbonSEPAID', $dropID);
$row = $form->addRow();
$row->addLabel('targetSEPAID', __('Merge into SEPA'))->description(__('A SEPA with the same IBAN is preselected.'));
$row->addSelect('targetSEPAID')->fromArray($targets)->selected($keepID ?: '')->placeholder()->required();
$row = $form->addRow();
$row->addSubmit(__('Compare'));
echo $form->getOutput();

if ($keepID <= 0 || !isset($targets[$keepID])) {
    echo '<p><a href="' . $esc($checkURL) . '">' . __('Back') . '</a></p>';
    return;
}
$keep = $merger->getSepa($keepID);

// field by field choice
$form = Form::create('sepaMerge', $session->get('absoluteURL') . '/modules/Sepa/sepa_mergeProcess.php');
$form->addHiddenValue('address', $session->get('address'));
$form->addHiddenValue('gibbonSEPAID', $dropID);
$form->addHiddenValue('targetSEPAID', $keepID);

$counts = $merger->getSepaLinkCounts($dropID);
$html = '<p>' . sprintf(__('%d payments, %d adjustments and %d snapshots move to the SEPA of %s.'), $counts['payments'], $counts['adjustments'], $counts['snapshots'], $esc($keep['familyName'])) . '</p>';

$rows = [];
foreach (FamilyMerger::SEPA_FIELDS as $field) {
    if ($field === 'IBAN') {
        if (IbanCipher::normalize($ibanOf($keep)) !== IbanCipher::normalize($ibanOf($drop))) {
            $rows[$field] = [$ibanOf($keep), $ibanOf($drop)];
        }
    } elseif ((string) $keep[$field] !== (string) $drop[$field]) {
        $rows[$field] = [$keep[$field], $drop[$field]];
    }
}
if (empty($rows)) {
    $html .= '<p><em>' . __('Nothing differs, nothing to choose.') . '</em></p>';
} else {
    $html .= '<table class="fullWidth colorOddEven"><thead><tr><th>' . __('Field') . '</th><th>' . $esc(sprintf(__('Keep SEPA %s'), $keepID)) . '</th><th>' . $esc(sprintf(__('SEPA %s'), $dropID)) . '</th></tr></thead><tbody>';
    foreach ($rows as $field => [$left, $right]) {
        $takeRight = trim((string) $left) === '' && trim((string) $right) !== '';
        $name = 'sepaFields[' . $field . ']';
        $html .= '<tr><td>' . $esc($field) . '</td>'
            . '<td><label><input type="radio" name="' . $esc($name) . '" value="target"' . ($takeRight ? '' : ' checked') . '> ' . $esc($left) . '</label></td>'
            . '<td><label><input type="radio" name="' . $esc($name) . '" value="source"' . ($takeRight ? ' checked' : '') . '> ' . $esc($right) . '</label></td></tr>';
    }
    $html .= '</tbody></table>';
}
$form->addRow()->addContent('<div class="warning">' . __('This changes the SEPA data and can not be undone. Make a database backup first.') . '</div>' . $html);

$row = $form->addRow();
$row->addContent('<a href="' . $esc($checkURL) . '">' . __('Back') . '</a>');
$row->addSubmit(__('Merge SEPA'));

echo $form->getOutput();
