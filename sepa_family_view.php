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
//use Gibbon\Domain\DataSet;
use Gibbon\Module\Sepa\Domain\SepaGateway;
use Gibbon\Module\Sepa\Domain\CustomFieldsGateway;
use Gibbon\Data\Validator;


// Module includes
require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_family_view.php')) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    $_GET = $container->get(Validator::class)->sanitize($_GET);
    $_POST = $container->get(Validator::class)->sanitize($_POST);
    
    $page->breadcrumbs->add(__('Family\'s SEPA')); // show page navigation link
    
    $search = isset($_GET['search']) ? $_GET['search'] : '';
    $SepaGateway = $container->get(SepaGateway::class);
    
    $CustomFieldsGateway = $container->get(CustomFieldsGateway::class);
    
    $criteria = $SepaGateway->newQueryCriteria(true)
        ->searchBy(['payer', 'customData'], $search)
        ->sortBy(['payer'])
        ->fromPOST();


    // SQL or Gateway query, as a dataset
    // For a OO datatable, see https:// gist.github.com/SKuipers/e176454a2feb555126c2147865bd0626
    // Don't forget to put header and column actions if you're using add/edit/delete pages AND include the ID/primary key as a param


    $form = Form::createSearch();

    $row = $form->addRow();
        $row->addLabel('search', __('Search For'))
            ->description(__('payer, booking date, amount, transaction message, IBAN, transaction reference, note'));
        $row->addTextField('search')->setValue($criteria->getSearchText());

    $form->addRow()->addSearchSubmit('', __('Clear Search'));

    echo $form->getOutput();

    echo '<h2>';
    echo __('View');
    echo '</h2>';

    // QUERY
    $SEPA = $SepaGateway->getSEPAData($criteria);


    // DATA TABLE

    $table = DataTable::createPaginated('SEPAData', $criteria);

    $table->addExpandableColumn('gibbonSEPA')
        ->format(
            function ($row) use ($CustomFieldsGateway) {
                $customFields = $CustomFieldsGateway->getCustomFields();
                $output_text = '';
                $output_text .= "<p>SEPA IBAN: " . htmlspecialchars($row['IBAN'] ?? '', ENT_QUOTES, 'UTF-8') . "</p>";
                $output_text .= "<p>SEPA BIC: " . htmlspecialchars($row['BIC'] ?? '', ENT_QUOTES, 'UTF-8') . "</p>";
                $output_text .= "<p>Note: " . htmlspecialchars($row['note'] ?? '', ENT_QUOTES, 'UTF-8') . "</p>";

                $jsonData = [];
                if (isset($row['customData']) && trim($row['customData']) !== '') {
                    $jsonData = json_decode($row['customData'], true);
                }
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $output_text = 'Error while reading Custome fields';
                } else {
                    foreach ($customFields as $field) {
                        $id = $CustomFieldsGateway->getCustomeFieldPrefix() . intval($field["gibbonSEPACustomFieldID"]);
                        $value = $jsonData[$id] ?? '';
                        $output_text .= "<p>" . htmlspecialchars($field['title'], ENT_QUOTES, 'UTF-8') . ": " . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . "</p>";
                    }

                }
                return $output_text;
            }
        );


    $table->addHeaderAction('add', __('Add'))
        ->setURL('/modules/Sepa/sepa_family_add.php')
        ->addParam('search', $search)
        ->displayLabel();

    $table->addColumn('payer', __('SEPA Owner'));

    $table->addColumn('adults', __('Adults'))
        ->notSortable()
        ->format(function ($row) {
            array_walk($row['adults'], function (&$person) {
                if ($person['status'] == 'Left' || $person['status'] == 'Expected') {
                    $person['surname'] .= ' <i>(' . __($person['status']) . ')</i>';
                }
            });
            return Format::nameList($row['adults'], 'Parent');
        });

    $table->addColumn('children', __('Children'))
        ->notSortable()
        ->format(function ($row) {
            array_walk($row['children'], function (&$person) {
                if ($person['status'] == 'Left' || $person['status'] == 'Expected') {
                    $person['surname'] .= ' <i>(' . __($person['status']) . ')</i>';
                }
            });
            return Format::nameList($row['children'], 'Student');
        });

    $table->addColumn('SEPA_signedDate', __('Date'));

    // ACTIONS
    $table->addActionColumn()
        ->addParam('gibbonSEPAID')
        ->addParam('search', $criteria->getSearchText(true))
        ->format(function ($row, $actions) {
            $actions->addAction('edit', __(''))
                ->setURL('/modules/Sepa/sepa_family_edit.php');

            $actions->addAction('delete', __(''))
                ->setURL('/modules/Sepa/sepa_family_delete.php');

        });


    echo $table->render($SEPA);
}

