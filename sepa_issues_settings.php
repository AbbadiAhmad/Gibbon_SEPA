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
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

use Gibbon\Forms\Form;
use Gibbon\Module\Sepa\Domain\IssuesGateway;
use Gibbon\Data\Validator;

$_GET = $container->get(Validator::class)->sanitize($_GET);
$_POST = $container->get(Validator::class)->sanitize($_POST);

// Module includes
require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_issues_settings.php')) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    $page->breadcrumbs
        ->add(__('Payment & Data Issues Dashboard'), 'sepa_issues_view.php')
        ->add(__('Configure Detection Settings'));

    // Display success/error messages
    if (isset($_GET['return'])) {
        if ($_GET['return'] == 'success0') {
            $page->addSuccess(__('Settings saved successfully.'));
        } elseif ($_GET['return'] == 'error2') {
            $page->addError(__('Failed to save settings.'));
        }
    }

    $issuesGateway = $container->get(IssuesGateway::class);

    // Get current settings
    $oldDateThreshold = $issuesGateway->getIssueSetting('sepa_old_date_threshold_years');
    $similarIBANEnabled = $issuesGateway->getIssueSetting('similar_iban_detection_enabled');
    $similarPayerEnabled = $issuesGateway->getIssueSetting('similar_payer_detection_enabled');
    $balanceMethod = $issuesGateway->getIssueSetting('balance_method_less_than');
    $balanceAttribute = $issuesGateway->getIssueSetting('balance_method_attribute');
    $balanceMoreThan = $issuesGateway->getIssueSetting('balance_method_more_than_attribute');

    echo '<h2>' . __('Issue Detection Settings') . '</h2>';
    echo '<p>' . __('Configure thresholds and detection methods for identifying payment and data quality issues.') . '</p>';

    $form = Form::create('issueSettings', $_SESSION[$guid]['absoluteURL'] . '/modules/Sepa/sepa_issues_settings_process.php');
    $form->addHiddenValue('address', $_SESSION[$guid]['address']);

    // General Detection Settings
    $row = $form->addRow()->addHeading(__('General Detection Settings'));

    $row = $form->addRow();
    $row->addLabel('similar_iban_detection_enabled', __('Detect Similar IBANs'))
        ->description(__('Find families sharing the same masked IBAN'));
    $row->addCheckbox('similar_iban_detection_enabled')
        ->setValue('1')
        ->checked($similarIBANEnabled == '1');

    $row = $form->addRow();
    $row->addLabel('similar_payer_detection_enabled', __('Detect Similar Payers'))
        ->description(__('Find payer names that sound similar (phonetic matching)'));
    $row->addCheckbox('similar_payer_detection_enabled')
        ->setValue('1')
        ->checked($similarPayerEnabled == '1');

    $row = $form->addRow();
    $row->addLabel('sepa_old_date_threshold_years', __('Old SEPA Threshold (Years)'))
        ->description(__('Number of years after which SEPA authorization is considered old'));
    $row->addNumber('sepa_old_date_threshold_years')
        ->minimum(1)
        ->maximum(10)
        ->setValue($oldDateThreshold)
        ->required();

    // Balance Detection Settings
    $row = $form->addRow()->addHeading(__('Balance Detection Settings'));

    $row = $form->addRow();
    $row->addLabel('balance_method_less_than', __('Low Balance Detection Method'))
        ->description(__('Choose how to detect underpayment'));
    $row->addSelect('balance_method_less_than')
        ->fromArray([
            'number' => __('Absolute Number (euros)'),
            'percentage' => __('Percentage of Expected Fees'),
            'proportion_to_academic_year' => __('Proportion to Academic Year Progress')
        ])
        ->selected($balanceMethod)
        ->required();

    $row = $form->addRow();
    $row->addLabel('balance_method_attribute', __('Low Balance Threshold'))
        ->description(__('Threshold value (meaning depends on method selected above)'));
    $row->addNumber('balance_method_attribute')
        ->minimum(0)
        ->setValue($balanceAttribute)
        ->required();

    $form->addRow()->addContent(
        '<div style="background-color: #f0f8ff; padding: 12px; border-left: 4px solid #007BFF; margin: 10px 0;">' .
        '<strong>' . __('Threshold Examples:') . '</strong><br/>' .
        '• ' . __('For "Absolute Number": Use 2 to detect balances less than 2 euros below expected') . '<br/>' .
        '• ' . __('For "Percentage": Use 20 to detect balances less than 20% of expected fees') . '<br/>' .
        '• ' . __('For "Proportion to Academic Year": Use 2 to allow 2% variance from expected payment timeline') .
        '</div>'
    );

    $row = $form->addRow();
    $row->addLabel('balance_method_more_than_attribute', __('High Balance Threshold (Euros)'))
        ->description(__('Detect overpayments when balance exceeds this amount (in euros)'));
    $row->addNumber('balance_method_more_than_attribute')
        ->minimum(0)
        ->setValue($balanceMoreThan)
        ->required();

    // Academic Year Progress Explanation
    $form->addRow()->addContent(
        '<div style="background-color: #fffacd; padding: 12px; border-left: 4px solid #ffc107; margin: 10px 0;">' .
        '<strong>' . __('How "Proportion to Academic Year" Works:') . '</strong><br/>' .
        __('This method calculates expected payment based on academic year progress.') . '<br/><br/>' .
        '<strong>' . __('Example:') . '</strong><br/>' .
        '• ' . __('Academic year: 10 months (Sept - June)') . '<br/>' .
        '• ' . __('Current: Month 4 (40% through year)') . '<br/>' .
        '• ' . __('Total fees: €1,000') . '<br/>' .
        '• ' . __('Expected by now: €1,000 × 0.4 = €400') . '<br/>' .
        '• ' . __('Actual paid: €380') . '<br/>' .
        '• ' . __('Shortfall: €20') . '<br/>' .
        '• ' . __('If threshold is €2, this family is flagged (shortfall > €2)') . '<br/><br/>' .
        '<em>' . __('Formula: (payments + positive_adjustments) < (totalFees + negative_adjustments) × (current_month / total_months)') . '</em>' .
        '</div>'
    );

    $row = $form->addRow();
    $row->addSubmit(__('Save Settings'));

    echo $form->getOutput();

    // Display current values summary
    echo '<h3>' . __('Current Configuration') . '</h3>';
    echo '<table class="standardWidth" cellspacing="0">';
    echo '<tr>';
    echo '<th style="width: 40%;">' . __('Setting') . '</th>';
    echo '<th>' . __('Value') . '</th>';
    echo '</tr>';
    echo '<tr>';
    echo '<td>' . __('Similar IBAN Detection') . '</td>';
    echo '<td>' . ($similarIBANEnabled == '1' ? '✅ ' . __('Enabled') : '❌ ' . __('Disabled')) . '</td>';
    echo '</tr>';
    echo '<tr>';
    echo '<td>' . __('Similar Payer Detection') . '</td>';
    echo '<td>' . ($similarPayerEnabled == '1' ? '✅ ' . __('Enabled') : '❌ ' . __('Disabled')) . '</td>';
    echo '</tr>';
    echo '<tr>';
    echo '<td>' . __('Old SEPA Threshold') . '</td>';
    echo '<td>' . $oldDateThreshold . ' ' . __('years') . '</td>';
    echo '</tr>';
    echo '<tr>';
    echo '<td>' . __('Balance Method') . '</td>';
    echo '<td>' . $balanceMethod . '</td>';
    echo '</tr>';
    echo '<tr>';
    echo '<td>' . __('Low Balance Threshold') . '</td>';
    echo '<td>' . $balanceAttribute . '</td>';
    echo '</tr>';
    echo '<tr>';
    echo '<td>' . __('High Balance Threshold') . '</td>';
    echo '<td>' . $balanceMoreThan . ' ' . __('euros') . '</td>';
    echo '</tr>';
    echo '</table>';
}
