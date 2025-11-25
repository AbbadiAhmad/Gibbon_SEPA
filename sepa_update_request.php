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
use Gibbon\Module\Sepa\Domain\SepaUpdateRequestGateway;
use Gibbon\Module\Sepa\Domain\CustomFieldsGateway;
use Gibbon\Data\Validator;

// Module includes
require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_update_request.php')) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    $_GET = $container->get(Validator::class)->sanitize($_GET);
    $_POST = $container->get(Validator::class)->sanitize($_POST);

    $page->breadcrumbs->add(__('Update SEPA Information'));

    // Get current user's family ID
    $gibbonPersonID = $_SESSION[$guid]['gibbonPersonID'] ?? '';

    $dataFamily = array('gibbonPersonID' => $gibbonPersonID);
    $sqlFamily = 'SELECT gibbonFamilyID FROM gibbonFamilyAdult WHERE gibbonPersonID=:gibbonPersonID AND childDataAccess="Y"';
    $resultFamily = $pdo->executeQuery($dataFamily, $sqlFamily);

    if ($resultFamily->rowCount() == 0) {
        $page->addError(__('You are not associated with a family or do not have data access.'));
        return;
    }

    $familyData = $resultFamily->fetch();
    $gibbonFamilyID = $familyData['gibbonFamilyID'];

    $SepaGateway = $container->get(SepaGateway::class);
    $UpdateRequestGateway = $container->get(SepaUpdateRequestGateway::class);
    $CustomFieldsGateway = $container->get(CustomFieldsGateway::class);

    // Check for pending request
    $hasPending = $UpdateRequestGateway->hasPendingRequest($gibbonFamilyID);

    if ($hasPending) {
        $page->addWarning(__('You have a pending SEPA information update request. Please wait for approval before submitting another request.'));
    }

    // Display current SEPA information
    echo '<h3>' . __('Current SEPA Information') . '</h3>';

    $FamilySEPA = $SepaGateway->getFamilySEPA($gibbonFamilyID);

    if (empty($FamilySEPA)) {
        echo '<div class="warning">';
        echo __('No SEPA information found for your family. You can submit a new request below.');
        echo '</div>';
        $currentData = null;
    } else {
        $currentData = $FamilySEPA[0];

        echo '<table class="smallIntBorder fullWidth" cellspacing="0">';
        echo '<tr>';
        echo '<td style="width: 30%; vertical-align: top;"><strong>' . __('Account Holder Name') . '</strong></td>';
        echo '<td>' . htmlspecialchars($currentData['payer'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<td style="width: 30%; vertical-align: top;"><strong>' . __('IBAN') . '</strong></td>';
        echo '<td>' . htmlspecialchars($currentData['IBAN'] ?? 'Not Set', ENT_QUOTES, 'UTF-8') . '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<td style="width: 30%; vertical-align: top;"><strong>' . __('SEPA Signed Date') . '</strong></td>';
        echo '<td>' . htmlspecialchars($currentData['SEPA_signedDate'] ?? 'Not Set', ENT_QUOTES, 'UTF-8') . '</td>';
        echo '</tr>';
        if (!empty($currentData['note'])) {
            echo '<tr>';
            echo '<td style="width: 30%; vertical-align: top;"><strong>' . __('Note') . '</strong></td>';
            echo '<td>' . htmlspecialchars($currentData['note'], ENT_QUOTES, 'UTF-8') . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }

    // Show previous update requests
    echo '<h3>' . __('Update Request History') . '</h3>';

    $criteria = $UpdateRequestGateway->newQueryCriteria(true)
        ->sortBy(['submittedDate'], 'DESC')
        ->fromPOST();

    $requests = $UpdateRequestGateway->queryRequestsByFamily($gibbonFamilyID, $criteria);

    $table = DataTable::createPaginated('updateHistory', $criteria);
    $table->addColumn('submittedDate', __('Submitted Date'))
        ->format(function($row) {
            return date('Y-m-d H:i', strtotime($row['submittedDate']));
        });
    $table->addColumn('status', __('Status'))
        ->format(function($row) {
            $colors = [
                'pending' => 'orange',
                'approved' => 'green',
                'rejected' => 'red'
            ];
            $color = $colors[$row['status']] ?? 'black';
            return '<span style="color: ' . $color . '; font-weight: bold;">' . ucfirst($row['status']) . '</span>';
        });
    $table->addColumn('approvedDate', __('Decision Date'))
        ->format(function($row) {
            return $row['approvedDate'] ? date('Y-m-d H:i', strtotime($row['approvedDate'])) : '-';
        });
    $table->addColumn('approver', __('Decided By'))
        ->format(function($row) {
            if (!empty($row['approverSurname'])) {
                return htmlspecialchars($row['approverPreferredName'] . ' ' . $row['approverSurname'], ENT_QUOTES, 'UTF-8');
            }
            return '-';
        });

    echo $table->render($requests);

    // Update request form
    if (!$hasPending) {
        echo '<h3>' . __('Submit Update Request') . '</h3>';

        $form = Form::create('sepaUpdateRequest', $session->get('absoluteURL') . '/modules/' . $session->get('module') . '/sepa_update_request_process.php');
        $form->addHiddenValue('address', $session->get('address'));
        $form->addHiddenValue('gibbonFamilyID', $gibbonFamilyID);

        if ($currentData) {
            $form->addHiddenValue('gibbonSEPAID', $currentData['gibbonSEPAID']);
        }

        $form->addRow()->addHeading(__('New SEPA Information'));

        $row = $form->addRow();
        $row->addLabel('new_payer', __('Account Holder Name'))->description(__('The name on the bank account'));
        $row->addTextField('new_payer')
            ->required()
            ->maxLength(255)
            ->setValue($currentData['payer'] ?? '');

        $row = $form->addRow();
        $row->addLabel('new_IBAN', __('IBAN'))->description(__('International Bank Account Number'));
        $row->addTextField('new_IBAN')
            ->required()
            ->maxLength(34)
            ->setValue('');

        $row = $form->addRow();
        $row->addLabel('new_BIC', __('BIC / SWIFT Code'))->description(__('Optional - Bank Identifier Code'));
        $row->addTextField('new_BIC')
            ->maxLength(11);

        $row = $form->addRow();
        $row->addLabel('new_SEPA_signedDate', __('SEPA Mandate Signed Date'));
        $row->addDate('new_SEPA_signedDate')
            ->setValue(date('Y-m-d'));

        $row = $form->addRow();
        $row->addLabel('new_note', __('Note'))->description(__('Optional note about this update'));
        $row->addTextArea('new_note')
            ->setRows(3);

        // Add custom fields if any exist
        $customFields = $CustomFieldsGateway->getCustomFields();
        if (!empty($customFields)) {
            $form->addRow()->addHeading(__('Additional Information'));

            $customFieldData = [];
            if ($currentData && !empty($currentData['customData'])) {
                $customFieldData = json_decode($currentData['customData'], true) ?? [];
            }

            $CustomFieldsGateway->addCustomFieldsToForm($form, $customFieldData);
        }

        $form->addRow()->addAlert(__('Your update request will be reviewed by an administrator before being applied to your account. You will be notified once it has been processed.'), 'message');

        $row = $form->addRow();
        $row->addFooter();
        $row->addSubmit();

        echo $form->getOutput();
    }
}
