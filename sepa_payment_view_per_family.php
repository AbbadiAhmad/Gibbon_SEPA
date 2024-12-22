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

// use Gibbon\Services\Format;
// use Gibbon\Forms\Form;
use Gibbon\Tables\DataTable;
use Gibbon\Module\Sepa\Domain\SepaGateway;
// use Gibbon\Module\Sepa\Domain\CustomFieldsGateway;

// Module includes
require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_payment_view_per_family.php')) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    //$page->breadcrumbs->add(__('SEPA Payment Entries')); // show page navigation link

    // $search = isset($_GET['search']) ? $_GET['search'] : '';

    $SepaGateway = $container->get(SepaGateway::class);


    echo '<h2>';
    echo __('View Payment Entries');
    echo '</h2>';

    // QUERY
    $SEPAList = $SepaGateway->getSEPAPerPerson($_SESSION[$guid]["gibbonPersonID"]);


    // DATA TABLE
    
    $table = DataTable::createDetails('PaymentEntries');

    $table->addColumn('SEPA_ownerName', __('SEPA Owner'))->width('50');
    $table->addColumn('Payments', __('Payments'))->width('600')
        ->format(
            function ($row) use ($SepaGateway) {
                $paymentEntries = $SepaGateway->getPaymentEntries($row);
                $Sum = 0;
                $output_text = '<table width=100%><tr>';
                $output_text .= '<th>Date</th>';
                $output_text .= '<th>Amount</th>';
                $output_text .= '<th>Message</th>';
                $output_text .= '</tr>';
                foreach ($paymentEntries as $paymentEntry) {
                    $output_text .= '<tr>';
                    $output_text .= "<td width=100>". htmlspecialchars($paymentEntry['booking_date'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>";
                    $output_text .= "<td width=100>" . htmlspecialchars($paymentEntry['amount'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>";
                    $output_text .= "<td >" . htmlspecialchars($paymentEntry['payment_message'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>";
                    $output_text .= '</tr>';
                    
                    $Sum += $paymentEntry['amount'];
                }
                $output_text .= '<tr>';
                    $output_text .= "<td></td>";
                    $output_text .= "<th>= $Sum €</th>";
                    $output_text .= "<td ></td>";
                    $output_text .= '</tr>';
                $output_text .= '</table>';

                return $output_text;
            }
        );


    echo $table->render($SEPAList);
}
