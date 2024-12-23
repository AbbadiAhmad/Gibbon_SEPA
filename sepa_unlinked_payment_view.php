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

use Gibbon\Services\Format;
use Gibbon\Forms\Form;
use Gibbon\Tables\DataTable;
use Gibbon\Module\Sepa\Domain\SepaGateway;

// Module includes
require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_unlinked_payment_view.php')) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    $page->breadcrumbs->add(__('Unlinked SEPA Payment Entries')); // show page navigation link

    $SepaGateway = $container->get(SepaGateway::class);
    $criteria = $SepaGateway->newQueryCriteria(true)
        ->fromPOST();

    // QUERY to get unlinked payments
    $unlinkedPayments = $SepaGateway->getUnlinkedPayments($criteria);

    // DATA TABLE
    echo '<h2>' . __('View Unlinked Payment Entries') . '</h2>';

    $table = DataTable::createPaginated('UnlinkedPaymentEntries', $criteria);

    $table->addColumn('SEPA_ownerName', __('SEPA owner'));
    $table->addColumn('booking_date', __('Date'));
    $table->addColumn('amount', __('Amount'));
    $table->addColumn('payment_message', __('Message'));

    echo $table->render($unlinkedPayments);
}
