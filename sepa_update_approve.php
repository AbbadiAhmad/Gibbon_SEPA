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
use Gibbon\Module\Sepa\Domain\SepaUpdateRequestGateway;
use Gibbon\Module\Sepa\Domain\SepaGateway;
use Gibbon\Data\Validator;

// Module includes
require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_update_approve.php')) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    $_GET = $container->get(Validator::class)->sanitize($_GET);
    $_POST = $container->get(Validator::class)->sanitize($_POST);

    $page->breadcrumbs->add(__('Approve SEPA Updates'));

    $UpdateRequestGateway = $container->get(SepaUpdateRequestGateway::class);
    $SepaGateway = $container->get(SepaGateway::class);

    // Check if viewing details of a specific request
    $gibbonSEPAUpdateRequestID = $_GET['gibbonSEPAUpdateRequestID'] ?? null;

    if ($gibbonSEPAUpdateRequestID) {
        // Show detailed view with approval form
        $request = $UpdateRequestGateway->getRequestByID($gibbonSEPAUpdateRequestID);

        if (!$request) {
            $page->addError(__('Update request not found.'));
            return;
        }

        echo '<h3>' . __('Update Request Details') . '</h3>';

        // Family and submitter info
        $dataFamily = ['gibbonFamilyID' => $request['gibbonFamilyID']];
        $sqlFamily = 'SELECT name FROM gibbonFamily WHERE gibbonFamilyID=:gibbonFamilyID';
        $resultFamily = $pdo->executeQuery($dataFamily, $sqlFamily);
        $familyInfo = $resultFamily->fetch();

        $dataSubmitter = ['gibbonPersonID' => $request['gibbonPersonIDSubmitted']];
        $sqlSubmitter = 'SELECT title, surname, preferredName FROM gibbonPerson WHERE gibbonPersonID=:gibbonPersonID';
        $resultSubmitter = $pdo->executeQuery($dataSubmitter, $sqlSubmitter);
        $submitterInfo = $resultSubmitter->fetch();

        echo '<table class="smallIntBorder fullWidth" cellspacing="0">';
        echo '<tr>';
        echo '<td style="width: 30%; vertical-align: top;"><strong>' . __('Family') . '</strong></td>';
        echo '<td>' . htmlspecialchars($familyInfo['name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') . '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<td style="width: 30%; vertical-align: top;"><strong>' . __('Submitted By') . '</strong></td>';
        echo '<td>' . htmlspecialchars($submitterInfo['preferredName'] . ' ' . $submitterInfo['surname'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<td style="width: 30%; vertical-align: top;"><strong>' . __('Submitted Date') . '</strong></td>';
        echo '<td>' . date('Y-m-d H:i', strtotime($request['submittedDate'])) . '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<td style="width: 30%; vertical-align: top;"><strong>' . __('Status') . '</strong></td>';
        echo '<td><span style="font-weight: bold; color: orange;">' . ucfirst($request['status']) . '</span></td>';
        echo '</tr>';
        echo '</table>';

        // Comparison table
        echo '<h4>' . __('Requested Changes') . '</h4>';

        echo '<table class="smallIntBorder fullWidth" cellspacing="0">';
        echo '<thead>';
        echo '<tr>';
        echo '<th style="width: 25%;">' . __('Field') . '</th>';
        echo '<th style="width: 37.5%;">' . __('Current Value') . '</th>';
        echo '<th style="width: 37.5%;">' . __('New Value') . '</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        // Compare payer
        $oldPayer = $request['old_payer'] ?? __('Not Set');
        $newPayer = $request['new_payer'] ?? '';
        $payerChanged = $oldPayer !== $newPayer;

        echo '<tr' . ($payerChanged ? ' style="background-color: #ffffcc;"' : '') . '>';
        echo '<td><strong>' . __('Account Holder') . '</strong></td>';
        echo '<td>' . htmlspecialchars($oldPayer, ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars($newPayer, ENT_QUOTES, 'UTF-8') . '</td>';
        echo '</tr>';

        // Compare IBAN (show full IBAN for approval - will be masked when stored)
        $oldIBAN = $request['old_IBAN'] ?? __('Not Set');
        $newIBAN = $request['new_IBAN'] ?? '';
        $ibanChanged = $oldIBAN !== $newIBAN;

        echo '<tr' . ($ibanChanged ? ' style="background-color: #ffffcc;"' : '') . '>';
        echo '<td><strong>' . __('IBAN') . '</strong></td>';
        echo '<td>' . htmlspecialchars($oldIBAN, ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars($newIBAN, ENT_QUOTES, 'UTF-8') . '</td>';
        echo '</tr>';

        // Compare BIC
        $oldBIC = $request['old_BIC'] ?? __('Not Set');
        $newBIC = $request['new_BIC'] ?? __('Not Set');
        $bicChanged = $oldBIC !== $newBIC;

        echo '<tr' . ($bicChanged ? ' style="background-color: #ffffcc;"' : '') . '>';
        echo '<td><strong>' . __('BIC') . '</strong></td>';
        echo '<td>' . htmlspecialchars($oldBIC, ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars($newBIC, ENT_QUOTES, 'UTF-8') . '</td>';
        echo '</tr>';

        // Compare signed date
        $oldDate = $request['old_SEPA_signedDate'] ?? __('Not Set');
        $newDate = $request['new_SEPA_signedDate'] ?? __('Not Set');
        $dateChanged = $oldDate !== $newDate;

        echo '<tr' . ($dateChanged ? ' style="background-color: #ffffcc;"' : '') . '>';
        echo '<td><strong>' . __('SEPA Signed Date') . '</strong></td>';
        echo '<td>' . htmlspecialchars($oldDate, ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars($newDate, ENT_QUOTES, 'UTF-8') . '</td>';
        echo '</tr>';

        // Compare note
        $oldNote = $request['old_note'] ?? '';
        $newNote = $request['new_note'] ?? '';
        $noteChanged = $oldNote !== $newNote;

        if ($oldNote || $newNote) {
            echo '<tr' . ($noteChanged ? ' style="background-color: #ffffcc;"' : '') . '>';
            echo '<td><strong>' . __('Note') . '</strong></td>';
            echo '<td>' . htmlspecialchars($oldNote ?: __('Empty'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars($newNote ?: __('Empty'), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';

        // Approval form
        if ($request['status'] === 'pending') {
            echo '<h4>' . __('Review Decision') . '</h4>';

            $form = Form::create('approveRequest', $session->get('absoluteURL') . '/modules/' . $session->get('module') . '/sepa_update_approve_process.php');
            $form->addHiddenValue('address', $session->get('address'));
            $form->addHiddenValue('gibbonSEPAUpdateRequestID', $gibbonSEPAUpdateRequestID);

            $row = $form->addRow();
            $row->addLabel('decision', __('Decision'));
            $row->addRadio('decision')
                ->fromArray([
                    'approve' => __('Approve - Update SEPA information'),
                    'reject' => __('Reject - Keep current information')
                ])
                ->required()
                ->inline();

            $row = $form->addRow();
            $row->addLabel('approvalNote', __('Note'))->description(__('Optional note about your decision'));
            $row->addTextArea('approvalNote')
                ->setRows(3);

            $row = $form->addRow();
            $row->addFooter();
            $row->addSubmit();

            echo $form->getOutput();
        }

        // Back button
        echo '<div style="margin-top: 20px;">';
        echo '<a href="' . $session->get('absoluteURL') . '/index.php?q=/modules/' . $session->get('module') . '/sepa_update_approve.php">';
        echo __('Back to Pending Requests');
        echo '</a>';
        echo '</div>';

    } else {
        // Show list of pending requests
        echo '<h3>' . __('Pending SEPA Update Requests') . '</h3>';

        $criteria = $UpdateRequestGateway->newQueryCriteria(true)
            ->sortBy(['submittedDate'], 'DESC')
            ->fromPOST();

        $requests = $UpdateRequestGateway->queryPendingRequests($criteria);

        $table = DataTable::createPaginated('pendingRequests', $criteria);

        $table->addColumn('familyName', __('Family'));

        $table->addColumn('submitter', __('Submitted By'))
            ->format(function($row) {
                return htmlspecialchars($row['submitterPreferredName'] . ' ' . $row['submitterSurname'], ENT_QUOTES, 'UTF-8');
            });

        $table->addColumn('submittedDate', __('Submitted Date'))
            ->format(function($row) {
                return date('Y-m-d H:i', strtotime($row['submittedDate']));
            });

        $table->addColumn('currentPayer', __('Current Payer'))
            ->format(function($row) {
                return htmlspecialchars($row['currentPayer'] ?? __('Not Set'), ENT_QUOTES, 'UTF-8');
            });

        $table->addColumn('currentIBAN', __('Current IBAN'))
            ->format(function($row) {
                return htmlspecialchars($row['currentIBAN'] ?? __('Not Set'), ENT_QUOTES, 'UTF-8');
            });

        $table->addActionColumn()
            ->addParam('gibbonSEPAUpdateRequestID', 'gibbonSEPAUpdateRequestID')
            ->format(function ($row, $actions) {
                $actions->addAction('review', __('Review'))
                    ->setURL('/modules/Sepa/sepa_update_approve.php');
            });

        echo $table->render($requests);

        if ($requests->getResultCount() == 0) {
            echo '<div class="success">';
            echo __('No pending update requests at this time.');
            echo '</div>';
        }
    }
}
