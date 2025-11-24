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
        $matchedSEPA = null;
        $matchMethod = '';

        // Try to match by IBAN first
        if (!empty($payment['IBAN'])) {
            $ibanMatches = $SepaGateway->getSEPAByIBAN($payment['IBAN']);

            if (count($ibanMatches) == 1) {
                // Exactly one IBAN match - use it
                $matchedSEPA = $ibanMatches[0];
                $matchMethod = 'IBAN';
            } elseif (count($ibanMatches) > 1) {
                // Multiple IBAN matches - cannot auto-link
                $multipleMatchesCount++;
                $results[] = [
                    'payer' => $payment['payer'],
                    'amount' => $payment['amount'],
                    'status' => 'Multiple matches found',
                    'method' => 'IBAN'
                ];
                continue;
            }
        }

        // If no IBAN match, try to match by payer name
        if (!$matchedSEPA && !empty($payment['payer'])) {
            $matches = $SepaGateway->getSEPAForPaymentEntry($payment);

            if (count($matches) == 1) {
                $matchedSEPA = $matches[0];
                $matchMethod = 'Payer Name';
            } elseif (count($matches) > 1) {
                // Multiple matches - cannot auto-link
                $multipleMatchesCount++;
                $results[] = [
                    'payer' => $payment['payer'],
                    'amount' => $payment['amount'],
                    'status' => 'Multiple matches found',
                    'method' => 'Payer Name'
                ];
                continue;
            }
        }

        // If we found exactly one match, link the payment
        if ($matchedSEPA) {
            $updateData = [
                'gibbonSEPAID' => $matchedSEPA['gibbonSEPAID'],
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

            $success = $SepaGateway->updatePayment($payment['gibbonSEPAPaymentRecordID'], $updateData);

            if ($success) {
                $linkedCount++;
                $results[] = [
                    'payer' => $payment['payer'],
                    'amount' => $payment['amount'],
                    'status' => 'Successfully linked',
                    'method' => $matchMethod
                ];
            } else {
                $notLinkedCount++;
                $results[] = [
                    'payer' => $payment['payer'],
                    'amount' => $payment['amount'],
                    'status' => 'Update failed',
                    'method' => $matchMethod
                ];
            }
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
