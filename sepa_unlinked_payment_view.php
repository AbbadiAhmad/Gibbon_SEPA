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
use Gibbon\Data\Validator;
$_GET = $container->get(Validator::class)->sanitize($_GET);
$_POST = $container->get(Validator::class)->sanitize($_POST);

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

    // Display auto-link results if available
    if (isset($_GET['return'])) {
        $return = $_GET['return'];
        if ($return == 'success0') {
            $linked = $_GET['linked'] ?? 0;
            $notLinked = $_GET['notLinked'] ?? 0;
            $multiple = $_GET['multiple'] ?? 0;

            $message = __('Auto-link completed:') . '<br/>';
            $message .= __('Successfully linked:') . ' ' . $linked . '<br/>';
            if ($multiple > 0) {
                $message .= __('Multiple matches found (not linked):') . ' ' . $multiple . '<br/>';
            }
            if ($notLinked > 0) {
                $message .= __('No match found:') . ' ' . $notLinked;
            }

            if ($linked > 0) {
                $page->addSuccess($message);
            } else {
                $page->addWarning($message);
            }

            // Clear the results from session
            unset($_SESSION[$guid]['autoLinkResults']);
        }
    }

    // QUERY to get unlinked payments
    $unlinkedPayments = $SepaGateway->getUnlinkedPayments($criteria);

    // DATA TABLE
    echo '<h2>' . __('View Unlinked Payment Entries') . '</h2>';

    // Add auto-link button if there are unlinked payments
    if (count($unlinkedPayments) > 0) {
        echo '<div class="linkTop">';
        echo '<a class="button" href="' . $_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/Sepa/sepa_unlinked_payment_autolink_process.php">';
        echo __('Auto-Link All Payments');
        echo '</a>';
        echo '</div>';
    }

    $table = DataTable::createPaginated('UnlinkedPaymentEntries', $criteria);

    $table->addColumn('payer', __('SEPA owner'));
    $table->addColumn('booking_date', __('Date'));
    $table->addColumn('amount', __('Amount'));
    $table->addColumn('transaction_message', __('Message'));
    
    $table->addActionColumn()
        ->addParam('payer')
        ->addParam('IBAN')
        ->format(function ($row, $actions) {
            $actions->addAction('Add', __(''))
                ->setURL('/modules/Sepa/sepa_family_add.php');
        });

    echo $table->render($unlinkedPayments);
}
