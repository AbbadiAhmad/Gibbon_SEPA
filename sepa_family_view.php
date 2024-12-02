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
use Gibbon\Services\Format;

use Gibbon\Forms\Form;
use Gibbon\Tables\DataTable;
use Gibbon\Domain\DataSet;
use Gibbon\Module\Sepa\Domain\SepaGateway;

// Module includes
require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_family_view.php')) {
	// Access denied
	$page->addError(__('You do not have access to this action.'));
} else {
    $page->breadcrumbs->add(__('Family\'s SEPA')); // show page navigation link
    $search = isset($_GET['search'])? $_GET['search'] : '';
    $SepaGateway = $container->get(SepaGateway::class);
    $criteria = $SepaGateway->newQueryCriteria(true)
        ->searchBy(['name', 'adults'], $search)
        ->sortBy(['name'])
        ->fromPOST();


    // SQL or Gateway query, as a dataset
    // For a OO datatable, see https:// gist.github.com/SKuipers/e176454a2feb555126c2147865bd0626
    // Don't forget to put header and column actions if you're using add/edit/delete pages AND include the ID/primary key as a param

    echo '<h2>';
    echo __('Search');
    echo '</h2>';

    $form = Form::create('filter', $session->get('absoluteURL').'/index.php', 'get');
    $form->setClass('noIntBorder w-full');

    $form->addHiddenValue('q', '/modules/'.$session->get('module').'/sepa_family_view.php');

    $row = $form->addRow();
        $row->addLabel('search', __('Search For'))->description(__('Search text'));
        $row->addTextField('search')->setValue($criteria->getSearchText());

    $row = $form->addRow();
        $row->addSearchSubmit($session, __('Clear Search'));

    echo $form->getOutput();

    echo '<h2>';
    echo __('View');
    echo '</h2>';

    // QUERY
    $families = $SepaGateway->queryFamilies($criteria);

    $familyIDs = $families->getColumn('gibbonFamilyID');
    $adults = $SepaGateway->selectAdultsByFamily($familyIDs)->fetchGrouped();
    $families->joinColumn('gibbonFamilyID', 'adults', $adults);

    $children = $SepaGateway->selectChildrenByFamily($familyIDs)->fetchGrouped();
    $families->joinColumn('gibbonFamilyID', 'children', $children);

    // DATA TABLE
    $table = DataTable::createPaginated('familyData', $criteria);

    // $table->addHeaderAction('add', __('Add'))
    //     ->setURL('/modules/Sepa/sepa_family_add.php')
    //     ->addParam('search', $search)
    //     ->displayLabel();

    $table->addColumn('name', __('Name'));
    $table->addColumn('SEPA_cycle', __('SEPA Cycle'));
    $table->addColumn('SEPA_holder', __('SEPA Holder'));
    $table->addColumn('SEPA_date', __('SEPA Date'));

    $table->addColumn('adults', __('Adults'))
        ->notSortable()
        ->format(function($row) {
            array_walk($row['adults'], function(&$person) {
                if ($person['status'] == 'Left' || $person['status'] == 'Expected') {
                    $person['surname'] .= ' <i>('.__($person['status']).')</i>';
                }
            });
            return Format::nameList($row['adults'], 'Parent');
        });
    $table->addColumn('children', __('Children'))
        ->notSortable()
        ->format(function($row) {
            array_walk($row['children'], function(&$person) {
                if ($person['status'] == 'Left' || $person['status'] == 'Expected') {
                    $person['surname'] .= ' <i>('.__($person['status']).')</i>';
                }
            });
            return Format::nameList($row['children'], 'Student');
        });

    // ACTIONS
    $table->addActionColumn()
        ->addParam('gibbonFamilyID')
        ->addParam('search', $criteria->getSearchText(true))
        ->format(function ($family, $actions) {
            $actions->addAction('edit', __('Edit'))
                    ->setURL('/modules/Sepa/sepa_family_edit.php');


        });

    echo $table->render($families);
}
	
