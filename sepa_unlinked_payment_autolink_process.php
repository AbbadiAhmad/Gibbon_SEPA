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

        // IMPORTANT: Since IBANs are now masked (XX****XXX format), we prioritize payer name matching
        // Multiple different full IBANs can have the same masked format, making IBAN-only matching unreliable

        // Try to match by payer name first (most reliable for masked IBANs)
        if (!empty($payment['payer'])) {
            $payerMatches = $SepaGateway->getSEPAForPaymentEntry($payment);

            if (count($payerMatches) == 1) {
                // Exactly one payer match - use it
                $matchedSEPA = $payerMatches[0];
                $matchMethod = 'Payer Name';

                // Optional: Verify masked IBAN also matches if available (for extra confidence)
                if (!empty($payment['IBAN']) && !empty($matchedSEPA['IBAN'])) {
                    $paymentMaskedIBAN = $SepaGateway->maskIBAN($payment['IBAN']);
                    if ($paymentMaskedIBAN === $matchedSEPA['IBAN']) {
                        $matchMethod = 'Payer Name + IBAN';
                    } else {
                        // Payer matches but IBAN doesn't - add warning
                        $matchMethod = 'Payer Name (IBAN mismatch)';
                    }
                }
            } elseif (count($payerMatches) > 1) {
                // Multiple payer matches - try to narrow down by masked IBAN
                if (!empty($payment['IBAN'])) {
                    $paymentMaskedIBAN = $SepaGateway->maskIBAN($payment['IBAN']);
                    $ibanFilteredMatches = array_filter($payerMatches, function($match) use ($paymentMaskedIBAN) {
                        return $match['IBAN'] === $paymentMaskedIBAN;
                    });

                    if (count($ibanFilteredMatches) == 1) {
                        // Narrowed down to one match using both payer and IBAN
                        $matchedSEPA = reset($ibanFilteredMatches);
                        $matchMethod = 'Payer Name + IBAN';
                    } else {
                        // Still multiple matches even with IBAN filter
                        $multipleMatchesCount++;
                        $results[] = [
                            'payer' => $payment['payer'],
                            'amount' => $payment['amount'],
                            'status' => 'Multiple matches found (Payer + IBAN)',
                            'method' => 'Payer Name + IBAN'
                        ];
                        continue;
                    }
                } else {
                    // Multiple payer matches and no IBAN to filter
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
        }

        // Fallback: If no payer, try IBAN-only matching (less reliable with masking)
        if (!$matchedSEPA && !empty($payment['IBAN'])) {
            $paymentMaskedIBAN = $SepaGateway->maskIBAN($payment['IBAN']);
            $ibanMatches = $SepaGateway->getSEPAByIBAN($paymentMaskedIBAN);

            if (count($ibanMatches) == 1) {
                $matchedSEPA = $ibanMatches[0];
                $matchMethod = 'IBAN only (masked)';
            } elseif (count($ibanMatches) > 1) {
                $multipleMatchesCount++;
                $results[] = [
                    'payer' => $payment['payer'],
                    'amount' => $payment['amount'],
                    'status' => 'Multiple matches found (masked IBAN ambiguous)',
                    'method' => 'IBAN only'
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
