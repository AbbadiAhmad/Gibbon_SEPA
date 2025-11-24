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

        // Strategy: Match by payer name first, use masked IBAN as secondary filter if needed
        // Note: IBANs are stored in masked format (XX****XXX), so IBAN-only matching may be ambiguous

        if (!empty($payment['payer'])) {
            // Primary: Match by payer name
            $matches = $SepaGateway->getSEPAForPaymentEntry($payment);

            if (count($matches) == 1) {
                // Single payer match found
                $matchedSEPA = $matches[0];
                $matchMethod = 'Payer Name';

                // Verify with IBAN if both payment and matched record have IBANs
                if (!empty($payment['IBAN']) && !empty($matchedSEPA['IBAN'])) {
                    $matchMethod = ($SepaGateway->maskIBAN($payment['IBAN']) === $matchedSEPA['IBAN'])
                        ? 'Payer Name + IBAN'
                        : 'Payer Name (IBAN mismatch)';
                }
            } elseif (count($matches) > 1 && !empty($payment['IBAN'])) {
                // Multiple payer matches - try to narrow down using masked IBAN
                $paymentMaskedIBAN = $SepaGateway->maskIBAN($payment['IBAN']);
                $filtered = array_filter($matches, function($m) use ($paymentMaskedIBAN) {
                    return !empty($m['IBAN']) && $m['IBAN'] === $paymentMaskedIBAN;
                });

                if (count($filtered) == 1) {
                    $matchedSEPA = reset($filtered);
                    $matchMethod = 'Payer Name + IBAN';
                }
            }
        } elseif (!empty($payment['IBAN'])) {
            // Fallback: No payer name, try IBAN-only matching (may be ambiguous with masking)
            $matches = $SepaGateway->getSEPAByIBAN($SepaGateway->maskIBAN($payment['IBAN']));

            if (count($matches) == 1) {
                $matchedSEPA = $matches[0];
                $matchMethod = 'IBAN only';
            }
        }

        // Process the match result
        if ($matchedSEPA) {
            // Attempt to link the payment
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

            if ($SepaGateway->updatePayment($payment['gibbonSEPAPaymentRecordID'], $updateData)) {
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
            // No unique match found - determine why
            $status = 'No match found';

            if (!empty($payment['payer'])) {
                $matchCount = count($SepaGateway->getSEPAForPaymentEntry($payment));
                if ($matchCount > 1) {
                    $status = 'Multiple matches found';
                    $multipleMatchesCount++;
                }
            }

            $notLinkedCount++;
            $results[] = [
                'payer' => $payment['payer'],
                'amount' => $payment['amount'],
                'status' => $status,
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
