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
use Gibbon\Module\Sepa\Domain\SepaGateway;
use Gibbon\Module\Sepa\Security\IbanCipher;
use Gibbon\Data\Validator;

$_GET = $container->get(Validator::class)->sanitize($_GET);
$_POST = $container->get(Validator::class)->sanitize($_POST);
// Module includes
require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_family_edit.php')) {
    // Access denied
    $page->addError(__m('You do not have access to this action.'));
} else {
    $page->breadcrumbs
        ->add(__m('Manage Family SEPA'), 'sepa_family_view.php')
        ->add(__m('Edit SEPA'));

    $gibbonSEPAID = htmlspecialchars($_GET['gibbonSEPAID'] ?? '', ENT_QUOTES, 'UTF-8');

    if ($gibbonSEPAID == '') {
        $page->addError(__m('You have not specified one or more required parameters.'));
        return;
    } else {

        $data = array('gibbonSEPAID' => $gibbonSEPAID);
        $sql = 'SELECT * FROM gibbonSEPA WHERE gibbonSEPAID=:gibbonSEPAID';
        $result = $connection2->prepare($sql);
        $result->execute($data);

        if ($result->rowCount() != 1) {
            $page->addError(__m('The specified record cannot be found.'));
            return;
        } else {
            $SEPA = $result->fetch(); // get the data

            $form = Form::create('addSEPA', $session->get('absoluteURL') . '/modules/' . $session->get('module') . '/sepa_family_editProcess.php');

            $form->addHiddenValue('address', $session->get('address'));
            $form->addHiddenValue('gibbonSEPAID', $SEPA['gibbonSEPAID']);
            foreach (getSepaCheckReturnParams($_GET) as $name => $value) {
                $form->addHiddenValue($name, $value);
            }

            // BASIC INFORMATION
            $form->addRow()->addHeading('SEPA Information', __m('SEPA Information'));

            $row = $form->addRow();
            $row->addLabel('Account owner name', __m('Sepa owner name'))->description(__m('The name of SEPA account owner.'));
            $row->addTextField('payer')->required()->maxLength(255);

            $row = $form->addRow();
            $row->addLabel('SEPAIBAN', __m('SEPA IBAN'))->description(__m('IBAN of the bank account, The money will be withdrawn from this account.'));
            $row->addTextField('IBAN')->maxLength(40);

            $row = $form->addRow();
            $row->addLabel('SEPABIC', __m('BIC'))->description(__m('BIC of the bank.'));
            $row->addTextField('BIC')->maxLength(11);

            $row = $form->addRow();
            $row->addLabel('withdrawCadence', __m('Withdrawal cadence'))->description(__m('How often the money is withdrawn.'));
            $row->addSelect('withdrawCadence')->fromArray(SepaGateway::getWithdrawCadenceOptions())->required()->selected('Monthly');

            $row = $form->addRow();
            $row->addLabel('withdrawDay', __m('Preferred withdrawal day'))->description(__m('Preferred day of the month for the withdrawal.'));
            $row->addSelect('withdrawDay')->fromArray(SepaGateway::getWithdrawDayOptions())->placeholder();


            $FamiliesName = "SELECT gibbonFamily.gibbonFamilyID as value, name FROM gibbonFamily LEFT JOIN gibbonSEPA ON gibbonFamily.gibbonFamilyID = gibbonSEPA.gibbonFamilyID WHERE (gibbonSEPA.gibbonFamilyID is NULL || gibbonFamily.gibbonFamilyID = {$SEPA['gibbonFamilyID']} )";
            $row = $form->addRow();
            $row->addLabel('Family', __m('Family'))->description('Only families without a SEPA account can be selected.');
            $row->addSelect('gibbonFamilyID')
                ->fromQuery($pdo, $FamiliesName)
                ->required()
                ->placeholder();

            $row = $form->addRow();
            $row->addLabel('note', __m('Note'));
            $row->addTextArea('note')->setRow(4);

            $row = $form->addRow();
            $row->addLabel('SEPAsignedDate', __m('Date of SEPA signature'));
            $row->addDate('SEPA_signedDate');

            $customFieldsGateway = $container->get(customFieldsGateway::class);
            $customFieldsGateway->addCustomFieldsToForm($form, $SEPA['customData']);

            // SUBMIT
            $row = $form->addRow();
            $row->addFooter();
            $row->addSubmit();

            // The IBAN is stored encrypted; show the clear value in the form
            $storedIBAN = $SEPA['IBAN'];
            $SEPA['IBAN'] = IbanCipher::decrypt($storedIBAN);
            if ($SEPA['IBAN'] === null) {
                $SEPA['IBAN'] = '';
                $page->addError(__m('The stored IBAN can not be decrypted. Check the IBAN encryption key.'));
            }
            $form->loadAllValuesFrom($SEPA);

            echo $form->getOutput();
        }

    }

}
// For a form
// Check out https:// gist.github.com/SKuipers/3a4de3a323ab9d0969951894c29940ae for a cheatsheet / guide
// Don't forget to use the posted ID and a query to be able to $form->loadAllValuesFrom($values);

