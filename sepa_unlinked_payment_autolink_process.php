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

use Gibbon\Module\Sepa\Domain\SepaGateway;
use Gibbon\Data\Validator;

require_once __DIR__ . '/moduleFunctions.php';
require_once __DIR__ . '/../../gibbon.php';

$_GET = $container->get(Validator::class)->sanitize($_GET);
$_POST = $container->get(Validator::class)->sanitize($_POST);

$URL = $_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/Sepa/sepa_unlinked_payment_view.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_unlinked_payment_view.php')) {
    $URL .= '&return=error0';
    header("Location: {$URL}");
    exit;
} else {
    $SepaGateway = $container->get(SepaGateway::class);

    // Get all unlinked payments
    $criteria = $SepaGateway->newQueryCriteria(false);
    $unlinkedPayments = $SepaGateway->getUnlinkedPayments($criteria);

    $linkedCount = 0;
    $notLinkedCount = 0;
    $multipleMatchesCount = 0;
    $results = [];

    foreach ($unlinkedPayments as $payment) {
        // Match by payer name only (IBANs are masked, so not reliable for matching)
        $matches = !empty($payment['payer'])
            ? $SepaGateway->getSEPAForPaymentEntry($payment)
            : [];

        if (count($matches) == 1) {
            // Exactly one match - link the payment
            $updateData = [
                'gibbonSEPAID' => $matches[0]['gibbonSEPAID'],
                'payer' => $payment['payer'],
                'IBAN' => $payment['IBAN'],
                'transaction_reference' => $payment['transaction_reference'],
                'transaction_message' => $payment['transaction_message'],
                'amount' => $payment['amount'],
                'note' => $payment['note'],
                'academicYear' => $payment['academicYear'],
                'payment_method' => $payment['payment_method'],
                'booking_date' => $payment['booking_date']
            ];

            if ($SepaGateway->updatePayment($payment['gibbonSEPAPaymentRecordID'], $updateData)) {
                $linkedCount++;
                $results[] = [
                    'payer' => $payment['payer'],
                    'amount' => $payment['amount'],
                    'status' => 'Successfully linked',
                    'method' => 'Payer Name'
                ];
            } else {
                $notLinkedCount++;
                $results[] = [
                    'payer' => $payment['payer'],
                    'amount' => $payment['amount'],
                    'status' => 'Update failed',
                    'method' => 'Error'
                ];
            }
        } elseif (count($matches) > 1) {
            // Multiple matches - cannot auto-link
            $multipleMatchesCount++;
            $notLinkedCount++;
            $results[] = [
                'payer' => $payment['payer'],
                'amount' => $payment['amount'],
                'status' => 'Multiple matches found',
                'method' => 'None'
            ];
        } else {
            // No match found
            $notLinkedCount++;
            $results[] = [
                'payer' => $payment['payer'],
                'amount' => $payment['amount'],
                'status' => 'No match found',
                'method' => 'None'
            ];
        }
    }

    // Store results in session for display
    $_SESSION[$guid]['autoLinkResults'] = [
        'linked' => $linkedCount,
        'notLinked' => $notLinkedCount,
        'multipleMatches' => $multipleMatchesCount,
        'details' => $results
    ];

    $URL .= "&return=success0&linked={$linkedCount}&notLinked={$notLinkedCount}&multiple={$multipleMatchesCount}";
    header("Location: {$URL}");
    exit;
}
