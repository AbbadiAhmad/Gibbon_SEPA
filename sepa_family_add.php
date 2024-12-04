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

// Module includes
require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, "/modules/Sepa/sepa_family_add.php")) {
   // Access denied
   $page->addError(__('You do not have access to this action.'));
} else {

   $page->breadcrumbs
      ->add(__('Manage Family SEBA'), 'sepa_family_view.php')
      ->add(__('Add SEBA'));

   // For a form
   // Check out https:// gist.github.com/SKuipers/3a4de3a323ab9d0969951894c29940ae for a cheatsheet / guide
   
   $editLink = '';
    if (isset($_GET['editID'])) {
        $editLink = $session->get('absoluteURL').'/index.php?q=/modules/' . $session->get('module') . '/sepa_family_edit.php&gibbonSEPAID='.$_GET['editID'];
    }
    $page->return->setEditLink($editLink);


   $form = Form::create('addSEPA', $session->get('absoluteURL') . '/modules/' . $session->get('module') . '/sepa_family_addProcess.php');

   $form->addHiddenValue('address', $session->get('address'));

   // BASIC INFORMATION
   $form->addRow()->addHeading('SEPA Information', __('SEPA Information'));

   $row = $form->addRow();
   $row->addLabel('Account owner name', __('Sepa owner name'))->description(__('The name of SEPA account owner.'));
   $row->addTextField('sepaOwnerName')->required()->maxLength(255);

   $row = $form->addRow();
   $row->addLabel('SEPA_IBAN', __('SEPA IBAN'))->description(__('IBAN of the bank account, The money will be withdrawn from this account.'));
   $row->addTextField('SEPAIBAN')->maxLength(22);

   $row = $form->addRow();
   $row->addLabel('SEPA_BIC', __('SEPA_BIC'))->description(__('IBAN of the bank account.'));
   $row->addTextField('SEPABIC')->maxLength(11);

   
   $FamiliesName = "SELECT gibbonFamily.gibbonFamilyID as value, name FROM gibbonFamily LEFT JOIN gibbonSEPA ON gibbonFamily.gibbonFamilyID = gibbonSEPA.gibbonFamilyID WHERE gibbonSEPA.gibbonFamilyID is NULL";
   $row= $form->addRow();
   $row->addLabel('Family', __('Family'))->description('Only families without a SEPA account can be selected.');
   $row->addSelect('FamilyID')
       ->fromQuery($pdo, $FamiliesName)
       ->required()
       ->placeholder();



   $row = $form->addRow();
   $row->addLabel('note', __('Note'));
   $row->addTextArea('note')->setRow(4);

   $row = $form->addRow();
   $row->addLabel('SEPASignedDate', __('Date of SEPA signniture'));
   $row->addDate('SEPASignedDate');

   // SUBMIT
   $row = $form->addRow();
   $row->addFooter();
   $row->addSubmit();
   echo $form->getOutput();
}
