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
use Gibbon\Tables\DataTable;
use Gibbon\Module\Sepa\Domain\SepaGateway;
use Gibbon\Module\Sepa\Security\IbanCipher;
use Gibbon\Data\Validator;

// Module includes
require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_check.php')) {
    $page->addError(__('You do not have access to this action.'));
} else {
    $page->breadcrumbs->add(__('Check SEPA Data'));

    $_GET = $container->get(Validator::class)->sanitize($_GET);
    $SepaGateway = $container->get(SepaGateway::class);
    $schoolYearID = !empty($_GET['schoolYearID']) ? $_GET['schoolYearID'] : $_SESSION[$guid]['gibbonSchoolYearID'];

    $form = Form::create('checkFilter', $session->get('absoluteURL') . '/index.php', 'get');
    $form->setClass('noIntBorder fullWidth');
    $form->addHiddenValue('q', '/modules/Sepa/sepa_check.php');
    $row = $form->addRow();
    $row->addLabel('schoolYearID', __('Academic Year'))->description(__('Families with enrolled students in this year must have a SEPA.'));
    $row->addSelect('schoolYearID')->fromArray($SepaGateway->getAllSchoolYears())->selected($schoolYearID);
    $row = $form->addRow();
    $row->addSubmit(__('Check'));
    echo $form->getOutput();

    $sepas = $SepaGateway->getAllSEPAWithFamily();
    $enrolledFamilies = $SepaGateway->getFamiliesWithEnrolledChildren($schoolYearID);

    $sepasByFamily = [];
    foreach ($sepas as $sepa) {
        $sepasByFamily[$sepa['gibbonFamilyID']][] = $sepa;
    }
    $adultsByFamily = $SepaGateway->selectAdultsByFamily(array_unique(array_column($sepas, 'gibbonFamilyID')))->fetchGrouped();

    // Clear IBANs of all records, to find IBANs used by several records
    $ibans = [];
    $ibanUsers = [];
    foreach ($sepas as $sepa) {
        $iban = IbanCipher::decrypt($sepa['IBAN'] ?? '');
        $ibans[$sepa['gibbonSEPAID']] = $iban;
        if (!empty($iban) && IbanCipher::isValid($iban)) {
            $ibanUsers[IbanCipher::normalize($iban)][] = $sepa['familyName'];
        }
    }

    $issues = [];
    $add = function ($severity, $familyName, $payer, $problem, $link) use (&$issues) {
        $issues[] = ['severity' => $severity, 'familyName' => $familyName, 'payer' => $payer, 'problem' => $problem, 'link' => $link];
    };
    $editLink = function ($sepa) {
        return ['edit', '/modules/Sepa/sepa_family_edit.php', ['gibbonSEPAID' => $sepa['gibbonSEPAID']]];
    };

    // Enrolled families without SEPA
    foreach ($enrolledFamilies as $familyID => $familyName) {
        if (empty($sepasByFamily[$familyID])) {
            $add('Error', $familyName, '', __('No SEPA for a family with enrolled students'), ['add', '/modules/Sepa/sepa_family_add.php', ['gibbonFamilyID' => $familyID]]);
        }
    }

    foreach ($sepasByFamily as $familyID => $familySepas) {
        if (count($familySepas) > 1) {
            $add('Error', $familySepas[0]['familyName'], '', sprintf(__('%d SEPA records for one family'), count($familySepas)), $editLink($familySepas[0]));
        }
    }

    foreach ($sepas as $sepa) {
        $familyName = $sepa['familyName'] ?? '';
        $payer = trim($sepa['payer'] ?? '');
        $link = $editLink($sepa);
        $stored = $sepa['IBAN'] ?? '';
        $iban = $ibans[$sepa['gibbonSEPAID']];

        // IBAN
        if ($iban === null) {
            $add('Error', $familyName, $payer, __('The IBAN can not be decrypted (wrong key or damaged data)'), $link);
        } elseif ($iban === '') {
            $add('Error', $familyName, $payer, __('IBAN is missing'), $link);
        } elseif (strpos($iban, '*') !== false) {
            $add('Error', $familyName, $payer, __('Only a masked IBAN is stored, the full IBAN has to be entered again'), $link);
        } else {
            $clean = IbanCipher::normalize($iban);
            if (strlen($clean) <= 4) {
                $add('Error', $familyName, $payer, __('IBAN is incomplete (only the country code or a few characters)'), $link);
            } elseif (!IbanCipher::isValid($clean)) {
                $add('Error', $familyName, $payer, __('IBAN is not valid (format or checksum)'), $link);
            } else {
                $others = array_diff_key(array_count_values($ibanUsers[$clean] ?? []), [$familyName => 1]);
                if (!empty($others)) {
                    $add('Error', $familyName, $payer, sprintf(__('The same IBAN is used by: %s'), implode(', ', array_keys($others))), $link);
                }
                if (!IbanCipher::isEncrypted($stored)) {
                    $add('Warning', $familyName, $payer, __('The IBAN is not encrypted yet (it is encrypted when the record is saved)'), $link);
                }
            }
        }

        // Account holder
        if ($payer === '') {
            $add('Error', $familyName, $payer, __('Account holder is missing'), $link);
        } else {
            if (preg_match('/\d/', $payer) || mb_strlen($payer) < 4 || strpos($payer, ' ') === false) {
                $add('Warning', $familyName, $payer, __('The account holder looks incomplete or wrong (needs first and last name, no digits)'), $link);
            }
            $adults = $adultsByFamily[$sepa['gibbonFamilyID']] ?? [];
            $matches = empty($adults);
            foreach ($adults as $adult) {
                if ($adult['surname'] !== '' && mb_strpos(mb_strtolower($payer), mb_strtolower($adult['surname'])) !== false) {
                    $matches = true;
                    break;
                }
            }
            if (!$matches) {
                $add('Warning', $familyName, $payer, __('The account holder does not match the surname of any parent'), $link);
            }
        }

        // Mandate and withdrawal data
        if (empty($sepa['SEPA_signedDate'])) {
            $add('Warning', $familyName, $payer, __('Date of SEPA signature is missing'), $link);
        }
        if (empty($sepa['withdrawDay'])) {
            $add('Warning', $familyName, $payer, __('Preferred withdrawal day is missing'), $link);
        }
        if (!isset($enrolledFamilies[$sepa['gibbonFamilyID']])) {
            $add('Warning', $familyName, $payer, __('The family has no enrolled students in the selected academic year'), $link);
        }
    }

    // Errors first
    usort($issues, function ($a, $b) {
        return [$a['severity'] !== 'Error', $a['familyName']] <=> [$b['severity'] !== 'Error', $b['familyName']];
    });

    $errors = count(array_filter($issues, function ($i) { return $i['severity'] === 'Error'; }));
    echo '<h2>' . __('Check SEPA Data') . '</h2>';
    if (empty($issues)) {
        echo "<div class='success'>" . __('No problems found.') . '</div>';
    } else {
        echo "<div class='" . ($errors ? 'error' : 'warning') . "'>" . sprintf(__('%1$d errors and %2$d warnings found.'), $errors, count($issues) - $errors) . '</div>';

        $table = DataTable::create('sepaCheck');
        $table->addColumn('severity', __('Type'))->format(function ($row) {
            return $row['severity'] === 'Error'
                ? '<span style="color: red; font-weight: bold;">' . __('Error') . '</span>'
                : '<span style="color: #c60;">' . __('Warning') . '</span>';
        });
        $table->addColumn('familyName', __('Family'));
        $table->addColumn('payer', __('Payer'));
        $table->addColumn('problem', __('Problem'));
        $table->addActionColumn()->format(function ($row, $actions) {
            $action = $actions->addAction($row['link'][0], $row['link'][0] === 'add' ? __('Add SEPA') : __('Edit'))
                ->setURL($row['link'][1]);
            foreach ($row['link'][2] as $name => $value) {
                $action->addParam($name, $value);
            }
        });

        echo $table->render($issues);
    }
}
