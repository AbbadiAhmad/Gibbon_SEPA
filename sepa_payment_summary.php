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
use Gibbon\Module\Sepa\Domain\SepaGateway;
use Gibbon\Module\Sepa\Domain\CustomFieldsGateway;
use Gibbon\Domain\DataSet;

use Gibbon\Data\Validator;
$_GET = $container->get(Validator::class)->sanitize($_GET);
$_POST = $container->get(Validator::class)->sanitize($_POST);

// Module includes
require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_payment_view.php')) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    $page->breadcrumbs->add(__('SEPA Payment Summay')); // show page navigation link

    $SepaGateway = $container->get(SepaGateway::class);

    #todo make the search works. 
    $search = isset($_GET['search']) ? $_GET['search'] : '';
    $criteria = $SepaGateway->newQueryCriteria(true)
        ->searchBy(['payer', 'customData'], $search)
        ->fromPOST();

    $form = Form::createSearch();
    $row = $form->addRow();
        $row->addLabel('search', __('Search For'))
            ->description(__(''));
        $row->addTextField('search')->setValue($criteria->getSearchText());

    $form->addRow()->addSearchSubmit('', __('Clear Search'));

    echo $form->getOutput(); 

    echo '<h2>';
    echo __('View Payment Summary');
    echo '</h2>';

    // QUERY

    $SEPAList = $SEPA = $SepaGateway->getSEPAData1($criteria);


    // DATA TABLE
    $table = DataTable::createPaginated('PaymentSummary', $criteria);

    $table->addColumn('Family', __('Family Name'))->sortable();
    $table->addColumn('Owner', __('SEPA Owner'))->sortable();
    $table->addColumn('total_amount', __('Paid'))->sortable();


    // ACTIONS
    // $table->addActionColumn()
    //     ->addParam('gibbonSEPAPaymentEntryID')
    //     ->format(function ($row, $actions) {
    //         $actions->addAction('view', __('View Details'))
    //             ->setURL('/modules/Sepa/sepa_payment_detail.php');
    //     });

    echo $table->render($SEPAList);
}
