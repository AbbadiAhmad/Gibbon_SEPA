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
use Gibbon\Module\Sepa\Domain\CustomFieldsGateway;
use Gibbon\Data\Validator;

$_GET = $container->get(Validator::class)->sanitize($_GET);
$_POST = $container->get(Validator::class)->sanitize($_POST);
// Module includes
require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_family_edit.php')) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    $page->breadcrumbs
        ->add(__('Manage Family SEPA'), 'sepa_family_view.php')
        ->add(__('Edit SEPA'));

    $gibbonSEPAID = htmlspecialchars($_GET['gibbonSEPAID'] ?? '', ENT_QUOTES, 'UTF-8');

    if ($gibbonSEPAID == '') {
        $page->addError(__('You have not specified one or more required parameters.'));
        return;
    } else {

        $data = array('gibbonSEPAID' => $gibbonSEPAID);
        $sql = 'SELECT * FROM gibbonSEPA WHERE gibbonSEPAID=:gibbonSEPAID';
        $result = $connection2->prepare($sql);
        $result->execute($data);

        if ($result->rowCount() != 1) {
            $page->addError(__('The specified record cannot be found.'));
            return;
        } else {
            $SEPA = $result->fetch(); // get the data

            $form = Form::create('addSEPA', $session->get('absoluteURL') . '/modules/' . $session->get('module') . '/sepa_family_editProcess.php');

            $form->addHiddenValue('address', $session->get('address'));
            $form->addHiddenValue('gibbonSEPAID', $SEPA['gibbonSEPAID']);

            // BASIC INFORMATION
            $form->addRow()->addHeading('SEPA Information', __('SEPA Information'));

            $row = $form->addRow();
            $row->addLabel('Account owner name', __('Sepa owner name'))->description(__('The name of SEPA account owner.'));
            $row->addTextField('payer')->required()->maxLength(255);

            $row = $form->addRow();
            $row->addLabel('SEPAIBAN', __('SEPA IBAN'))->description(__('IBAN of the bank account, The money will be withdrawn from this account.'));
            $row->addTextField('IBAN')->maxLength(22);

            $row = $form->addRow();
            $row->addLabel('SEPABIC', __('BIC'))->description(__('IBAN of the bank account.'));
            $row->addTextField('BIC')->maxLength(11);


            $FamiliesName = "SELECT gibbonFamily.gibbonFamilyID as value, name FROM gibbonFamily LEFT JOIN gibbonSEPA ON gibbonFamily.gibbonFamilyID = gibbonSEPA.gibbonFamilyID WHERE (gibbonSEPA.gibbonFamilyID is NULL || gibbonFamily.gibbonFamilyID = {$SEPA['gibbonFamilyID']} )";
            $row = $form->addRow();
            $row->addLabel('Family', __('Family'))->description('Only families without a SEPA account can be selected.');
            $row->addSelect('gibbonFamilyID')
                ->fromQuery($pdo, $FamiliesName)
                ->required()
                ->placeholder();

            $row = $form->addRow();
            $row->addLabel('note', __('Note'));
            $row->addTextArea('note')->setRow(4);

            $row = $form->addRow();
            $row->addLabel('SEPAsignedDate', __('Date of SEPA signature'));
            $row->addDate('SEPA_signedDate');

            $customFieldsGateway = $container->get(customFieldsGateway::class);
            $customFieldsGateway->addCustomFieldsToForm($form, $SEPA['customData']);

            // SUBMIT
            $row = $form->addRow();
            $row->addFooter();
            $row->addSubmit();

            $form->loadAllValuesFrom($SEPA);

            echo $form->getOutput();
        }

    }

}
// For a form
// Check out https:// gist.github.com/SKuipers/3a4de3a323ab9d0969951894c29940ae for a cheatsheet / guide
// Don't forget to use the posted ID and a query to be able to $form->loadAllValuesFrom($values);

