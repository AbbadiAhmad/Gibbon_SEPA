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
use Gibbon\Module\Sepa\Domain\BulkAdjustment;
use Gibbon\Module\Sepa\Domain\CoursesFeeGateway;
use Gibbon\Module\Sepa\Domain\SepaGateway;

// Module includes
require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_payment_adjustment_bulk.php')) {
    $page->addError(__m('You do not have access to this action.'));
    return;
}

$page->breadcrumbs->add(__m('Payment Adjustment'), 'sepa_payment_adjustment_manage.php');
$page->breadcrumbs->add(__m('Bulk entry'));

$_POST = $container->get(Validator::class)->sanitize($_POST);
$SepaGateway = $container->get(SepaGateway::class);
$e = function ($value) {
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
};
$pageURL = $session->get('absoluteURL') . '/index.php?q=/modules/Sepa/sepa_payment_adjustment_bulk.php';
$schoolYears = $SepaGateway->getAllSchoolYears();

if (!empty($_SESSION[$guid]['sepaBulkNotice'])) {
    echo "<div class='success'>" . $e($_SESSION[$guid]['sepaBulkNotice']) . '</div>';
    unset($_SESSION[$guid]['sepaBulkNotice']);
}

$step = in_array($_POST['step'] ?? '', ['filters', 'preview'], true) ? $_POST['step'] : 'settings';
[$settings, $errors] = BulkAdjustment::parseSettings($_POST);
if ($step !== 'settings' && !empty($errors)) {
    foreach ($errors as $error) {
        echo "<div class='error'>" . $e($error) . '</div>';
    }
    $step = 'settings';
}

// the settings are carried from step to step as hidden values
$carry = function (Form $form, array $settings) {
    foreach (['academicYear', 'mode', 'base', 'value', 'description', 'note'] as $name) {
        $form->addHiddenValue($name, $settings[$name]);
    }
};
$stepNames = [__m('Discount'), __m('Filters'), __m('Check and enter')];
$stepLine = function ($current) use ($stepNames, $e) {
    $parts = [];
    foreach ($stepNames as $i => $name) {
        $parts[] = ($i + 1 === $current ? '<strong>' : '') . $e(sprintf(__m('Step %d'), $i + 1) . ': ' . $name) . ($i + 1 === $current ? '</strong>' : '');
    }
    return '<p>' . implode(' &rarr; ', $parts) . '</p>';
};

echo '<h2>' . __m('Bulk payment adjustment') . '</h2>';

// ------------------------------------------------------------------------------------------------
// STEP 1: the discount
// ------------------------------------------------------------------------------------------------
if ($step === 'settings') {
    echo $stepLine(1);
    echo '<p>' . __m('Enter the same adjustment (for example a discount) for many families at once. The balance is negative when a family owes money; an adjustment is a positive amount that reduces the debt, and it is never larger than what the family owes.') . '</p>';

    $form = Form::create('bulkSettings', $pageURL);
    $form->addHiddenValue('address', $session->get('address'));
    $form->addHiddenValue('step', 'filters');

    $row = $form->addRow();
    $row->addLabel('academicYear', __m('Academic Year'));
    $row->addSelect('academicYear')->fromArray($schoolYears)->required()->selected($settings['academicYear'] ?: $session->get('gibbonSchoolYearID'));

    $row = $form->addRow();
    $row->addLabel('mode', __m('Discount type'));
    $row->addSelect('mode')->fromArray(['percent' => __m('Percentage'), 'fixed' => __m('Fixed value per family')])->required()->selected($settings['mode']);

    $form->toggleVisibilityByClass('bulkBase')->onSelect('mode')->when('percent');
    $row = $form->addRow()->addClass('bulkBase');
    $row->addLabel('base', __m('Percentage of'));
    $row->addSelect('base')->fromArray(['owed' => __m('The amount the family owes'), 'fees' => __m('The total fees of the year')])->selected($settings['base']);

    $row = $form->addRow();
    $row->addLabel('value', __m('Discount'))->description(__m('A percentage (for example 10) or an amount (for example 50), as chosen above.'));
    $row->addTextField('value')->required()->maxLength(10)->setValue($settings['value'] ? (string) $settings['value'] : '');

    $row = $form->addRow();
    $row->addLabel('description', __m('Description'))->description(__m('Shown to the family. Can be changed per family later.'));
    $row->addTextArea('description')->required()->setRows(3)->setValue($settings['description']);

    $row = $form->addRow();
    $row->addLabel('note', __m('Note'))->description(__m('For the administration. Can be changed per family later.'));
    $row->addTextArea('note')->setRows(3)->setValue($settings['note']);

    $row = $form->addRow();
    $row->addFooter();
    $row->addSubmit(__m('Next'));
    echo $form->getOutput();
    return;
}

// ------------------------------------------------------------------------------------------------
// STEP 2: the filters
// ------------------------------------------------------------------------------------------------
if ($step === 'filters') {
    echo $stepLine(2);
    echo '<p>' . __m('Only families with a SEPA and enrolled students in the academic year can get an adjustment. Leave a filter empty to not use it; all filters have to match.') . '</p>';

    $courseGateway = $container->get(CoursesFeeGateway::class);
    $courses = [];
    foreach ($courseGateway->queryCoursesFees($courseGateway->newQueryCriteria(false), $settings['academicYear']) as $course) {
        $courses[(int) $course['gibbonCourseID']] = $course['nameShort'];
    }

    $form = Form::create('bulkFilters', $pageURL);
    $form->addHiddenValue('address', $session->get('address'));
    $form->addHiddenValue('step', 'preview');
    $carry($form, $settings);

    $row = $form->addRow();
    $row->addLabel('cadence', __m('Withdrawal cadence'))->description(__m('Nothing selected: all.'));
    $row->addCheckbox('cadence')->fromArray(SepaGateway::getWithdrawCadenceOptions())->alignLeft();

    $row = $form->addRow();
    $row->addLabel('childrenOp', __m('Number of enrolled children'));
    $col = $row->addColumn()->addClass('inline right');
    $col->addSelect('childrenOp')->fromArray(['any' => __m('Any'), 'gt' => __m('More than'), 'lt' => __m('Fewer than'), 'eq' => __m('Exactly')])->selected('any')->setClass('shortWidth');
    $col->addNumber('childrenN')->minimum(0)->maximum(50)->setValue(3)->setClass('shortWidth');

    $row = $form->addRow();
    $row->addLabel('owedOp', __m('Amount owed'))->description(__m('The balance is negative when a family owes money; enter the amount owed as a positive number.'));
    $col = $row->addColumn()->addClass('inline right');
    $col->addSelect('owedOp')->fromArray(['any' => __m('Any'), 'gt' => __m('More than'), 'lt' => __m('Less than')])->selected('any')->setClass('shortWidth');
    $col->addTextField('owedX')->maxLength(10)->setValue('0')->setClass('shortWidth');

    if (!empty($courses)) {
        $row = $form->addRow();
        $row->addLabel('courses', __m('Course'))->description(__m('Families with a child in one of the selected courses. Nothing selected: all.'));
        $row->addSelect('courses')->fromArray($courses)->selectMultiple();
    }

    $row = $form->addRow();
    $row->addLabel('noPayments', __m('Nothing paid yet'))->description(__m('Only families without any payment in the academic year.'));
    $row->addCheckbox('noPayments')->setValue('1');

    $row = $form->addRow();
    $row->addLabel('hasAdjustment', __m('Existing adjustments'));
    $row->addSelect('hasAdjustment')->fromArray(['any' => __m('Any'), 'with' => __m('Families that have an adjustment in this year'), 'without' => __m('Families without an adjustment in this year')])->selected('any');

    $row = $form->addRow();
    $row->addContent('<a href="' . $e($pageURL) . '">' . __m('Back') . '</a>');
    $row->addSubmit(__m('Next'));
    echo $form->getOutput();
    return;
}

// ------------------------------------------------------------------------------------------------
// STEP 3: the families, check and change the amounts
// ------------------------------------------------------------------------------------------------
echo $stepLine(3);
$filters = BulkAdjustment::parseFilters($_POST);

$matched = [];
$noDebt = 0;
foreach ($SepaGateway->getBulkAdjustmentRows($settings['academicYear']) as $row) {
    if (!BulkAdjustment::matches($row, $filters)) {
        continue;
    }
    $row['amount'] = BulkAdjustment::amountFor($row, $settings);
    if ($row['amount'] <= 0) {
        $noDebt++;
        continue;
    }
    $matched[] = $row;
}
usort($matched, function ($a, $b) {
    return strcasecmp($a['familyName'], $b['familyName']);
});

$yearName = $schoolYears[$settings['academicYear']] ?? '';
$what = $settings['mode'] === 'percent'
    ? sprintf($settings['base'] === 'fees' ? __m('%s%% of the total fees') : __m('%s%% of the amount owed'), $settings['value'])
    : sprintf(__m('%s per family'), number_format($settings['value'], 2));
echo '<p>' . sprintf(__m('Academic year %1$s, discount: %2$s.'), $e($yearName), $e($what)) . '</p>';
if ($noDebt > 0) {
    echo "<div class='message'>" . sprintf(__m('%d more families match the filters but owe nothing, they get no adjustment.'), $noDebt) . '</div>';
}

$back = Form::create('bulkBack', $pageURL);
$back->addHiddenValue('address', $session->get('address'));
$back->addHiddenValue('step', 'filters');
$carry($back, $settings);
$back->addRow()->addSubmit(__m('Back'));

if (empty($matched)) {
    echo "<div class='warning'>" . __m('No family matches the filters.') . '</div>';
    echo $back->getOutput();
    return;
}

// one-time token against entering the same list twice
$token = bin2hex(random_bytes(16));
$_SESSION[$guid]['sepaBulkToken'] = $token;

$form = Form::create('bulkPreview', $session->get('absoluteURL') . '/modules/Sepa/sepa_payment_adjustment_bulkProcess.php');
$form->addHiddenValue('address', $session->get('address'));
$form->addHiddenValue('academicYear', $settings['academicYear']);
$form->addHiddenValue('token', $token);

$html = '<p>' . sprintf(__m('%d families. Untick a family to skip it; the new adjustment, the description and the note can be changed.'), count($matched)) . '</p>';
$html .= '<table class="fullWidth colorOddEven" id="bulkTable"><thead><tr>'
    . '<th><input type="checkbox" id="bulkAll" checked title="' . $e(__m('Select All')) . '"></th>'
    . '<th>' . __m('Family') . '</th><th>' . __m('Payer') . '</th>'
    . '<th>' . __m('Total Fees') . '</th><th>' . __m('Payments') . '</th><th>' . __m('Adjustments') . '</th><th>' . __m('Balance') . '</th>'
    . '<th>' . __m('New adjustment') . '</th><th>' . __m('New balance') . '</th><th>' . __m('Description') . ' / ' . __m('Note') . '</th></tr></thead><tbody>';
$total = 0.0;
foreach ($matched as $row) {
    $id = (int) $row['gibbonSEPAID'];
    $total += $row['amount'];
    $html .= '<tr>'
        . '<td><input type="checkbox" class="bulkApply" name="rows[' . $id . '][apply]" value="1" checked></td>'
        . '<td>' . $e($row['familyName']) . '</td><td>' . $e($row['payer']) . '</td>'
        . '<td>' . number_format($row['fees'], 2) . '</td><td>' . number_format($row['payments'], 2) . '</td><td>' . number_format($row['adjustments'], 2) . '</td>'
        . '<td>' . number_format($row['balance'], 2) . '</td>'
        . '<td><input type="text" class="bulkAmount" name="rows[' . $id . '][amount]" value="' . number_format($row['amount'], 2, '.', '') . '" data-balance="' . number_format($row['balance'], 2, '.', '') . '" size="8" style="text-align: right;"></td>'
        . '<td class="bulkNew">' . number_format($row['balance'] + $row['amount'], 2) . '</td>'
        . '<td><input type="text" name="rows[' . $id . '][description]" value="' . $e($settings['description']) . '" maxlength="2000" style="width: 98%;"><br>'
        . '<input type="text" name="rows[' . $id . '][note]" value="' . $e($settings['note']) . '" maxlength="2000" style="width: 98%;" placeholder="' . $e(__m('Note')) . '"></td>'
        . '</tr>';
}
$html .= '</tbody><tfoot><tr><td colspan="7"><strong>' . __m('Total of the ticked adjustments') . '</strong></td><td><strong id="bulkTotal">' . number_format($total, 2) . '</strong></td><td colspan="2"></td></tr></tfoot></table>';

// the new balance and the total follow the amounts
$html .= '<script>
(function () {
    var table = document.getElementById("bulkTable");
    function number(text) { var n = parseFloat(String(text).replace(",", ".")); return isNaN(n) ? 0 : n; }
    function update() {
        var total = 0;
        table.querySelectorAll("tbody tr").forEach(function (tr) {
            var input = tr.querySelector(".bulkAmount");
            var amount = number(input.value);
            tr.querySelector(".bulkNew").textContent = (number(input.getAttribute("data-balance")) + amount).toFixed(2);
            if (tr.querySelector(".bulkApply").checked) { total += amount; }
        });
        document.getElementById("bulkTotal").textContent = total.toFixed(2);
    }
    table.addEventListener("input", update);
    table.addEventListener("change", update);
    document.getElementById("bulkAll").addEventListener("change", function () {
        var on = this.checked;
        table.querySelectorAll(".bulkApply").forEach(function (box) { box.checked = on; });
        update();
    });
})();
</script>';
$form->addRow()->addContent($html);

$row = $form->addRow();
$row->addContent('');
$row->addSubmit(__m('Enter the adjustments'));

echo $form->getOutput();
echo $back->getOutput();
