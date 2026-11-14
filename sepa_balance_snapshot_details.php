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

use Gibbon\Tables\DataTable;
use Gibbon\Module\Sepa\Domain\SepaGateway;
use Gibbon\Module\Sepa\Domain\SnapshotGateway;
use Gibbon\Module\Sepa\Domain\SepaPaymentAdjustmentGateway;
use Gibbon\Data\Validator;

$_GET = $container->get(Validator::class)->sanitize($_GET);
$_POST = $container->get(Validator::class)->sanitize($_POST);

// Module includes
require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_balance_snapshot.php')) {
    // Access denied
    $page->addError(__m('You do not have access to this action.'));
} else {
    $gibbonFamilyID = isset($_GET['gibbonFamilyID']) ? $_GET['gibbonFamilyID'] : '';
    $snapshotID = isset($_GET['snapshotID']) ? $_GET['snapshotID'] : '';
    $snapshotDate = isset($_GET['snapshotDate']) ? $_GET['snapshotDate'] : 'current';
    $schoolYearID = isset($_GET['schoolYearID']) ? $_GET['schoolYearID'] : $_SESSION[$guid]["gibbonSchoolYearID"];
    $search = isset($_GET['search']) ? $_GET['search'] : '';

    if (empty($gibbonFamilyID)) {
        $page->addError(__m('Family ID is required.'));
        return;
    }

    $page->breadcrumbs
        ->add(__m('Balance Snapshot'), 'sepa_balance_snapshot.php')
        ->add(__m('Snapshot Details'));

    $SepaGateway = $container->get(SepaGateway::class);
    $SnapshotGateway = $container->get(SnapshotGateway::class);
    $SepaPaymentAdjustmentGateway = $container->get(SepaPaymentAdjustmentGateway::class);

    // Get family info
    $familyInfo = $SepaGateway->getFamilyInfo($gibbonFamilyID);
    $familyName = !empty($familyInfo) ? $familyInfo[0]['name'] : 'Unknown Family';

    echo '<h2>' . __m('Snapshot Details: ') . htmlspecialchars($familyName, ENT_QUOTES, 'UTF-8') . '</h2>';

    // Determine what we're viewing
    if ($snapshotDate == 'current' || empty($snapshotID)) {
        // Viewing current status - compare with last snapshot
        $lastSnapshot = $SnapshotGateway->getLatestSnapshotByFamily($gibbonFamilyID, $schoolYearID);

        // Get current data
        $FamilySEPA = $SepaGateway->getFamilySEPA($gibbonFamilyID);
        $gibbonSEPAID = !empty($FamilySEPA) ? $FamilySEPA[0]['gibbonSEPAID'] : null;

        $feesSummary = $SepaGateway->getFamilyFeesSummary($gibbonFamilyID, $schoolYearID);
        $totalFees = $feesSummary[0]['totalFees'] ?? 0;
        $totalPayments = $SepaGateway->getFamilyTotalPayments($gibbonFamilyID, $schoolYearID);
        $totalAdjustments = $SepaPaymentAdjustmentGateway->getFamilyTotalAdjustments($gibbonFamilyID, $schoolYearID);
        $currentBalance = $totalPayments + $totalAdjustments - $totalFees;

        // Get fees, payments, and adjustments
        $fees = $SepaGateway->getFamilyEnrollmentFees($gibbonFamilyID, $schoolYearID);
        $feesArray = is_array($fees) ? $fees : $fees->toArray();

        $payments = $gibbonSEPAID ? $SepaGateway->getPaymentEntriesByFamily($gibbonSEPAID, $schoolYearID) : [];
        $adjustments = $gibbonSEPAID ? $SepaPaymentAdjustmentGateway->getFamilyAdjustments($gibbonSEPAID, $schoolYearID) : [];

        // Current snapshot data
        $currentData = [
            'balance' => [
                'totalFees' => $totalFees,
                'totalPayments' => $totalPayments,
                'totalAdjustments' => $totalAdjustments,
                'balance' => $currentBalance
            ],
            'fees' => $feesArray,
            'payments' => $payments,
            'adjustments' => $adjustments
        ];

        $previousData = $lastSnapshot ? json_decode($lastSnapshot['snapshotData'], true) : null;

        echo '<h3>' . __m('Current Status vs Last Snapshot') . '</h3>';
        if ($lastSnapshot) {
            echo '<p><strong>' . __m('Last Snapshot Date: ') . '</strong>' . date('Y-m-d H:i', strtotime($lastSnapshot['snapshotDate'])) . '</p>';
        } else {
            echo '<p><em>' . __m('No previous snapshot available for comparison.') . '</em></p>';
        }
    } else {
        // Viewing a specific snapshot - compare with previous snapshot
        $currentSnapshot = $SnapshotGateway->getSnapshotByID($snapshotID);

        if (empty($currentSnapshot)) {
            $page->addError(__m('Snapshot not found.'));
            return;
        }

        $currentData = json_decode($currentSnapshot['snapshotData'], true);

        // Get previous snapshot
        $allSnapshots = $SnapshotGateway->getSnapshotsByFamily($gibbonFamilyID, $schoolYearID);
        $previousSnapshot = null;
        $foundCurrent = false;

        foreach ($allSnapshots as $snap) {
            if ($foundCurrent) {
                $previousSnapshot = $snap;
                break;
            }
            if ($snap['gibbonSEPABalanceSnapshotID'] == $snapshotID) {
                $foundCurrent = true;
            }
        }

        $previousData = $previousSnapshot ? json_decode($previousSnapshot['snapshotData'], true) : null;

        echo '<h3>' . __m('Snapshot from ') . date('Y-m-d H:i', strtotime($currentSnapshot['snapshotDate'])) . '</h3>';
        if ($previousSnapshot) {
            echo '<p><strong>' . __m('Comparing with snapshot from: ') . '</strong>' . date('Y-m-d H:i', strtotime($previousSnapshot['snapshotDate'])) . '</p>';
        } else {
            echo '<p><em>' . __m('No previous snapshot available for comparison.') . '</em></p>';
        }
    }

    // Display balance comparison
    echo '<h4>' . __m('Balance Summary') . '</h4>';
    echo '<table class="fullWidth" cellspacing="0">';
    echo '<tr>';
    echo '<th>' . __m('Item') . '</th>';
    echo '<th style="text-align: right;">' . ($previousData ? __m('Previous') : __m('N/A')) . '</th>';
    echo '<th style="text-align: right;">' . __m('Current') . '</th>';
    echo '<th style="text-align: right;">' . __m('Change') . '</th>';
    echo '</tr>';

    $balanceItems = [
        'totalFees' => __m('Total Fees'),
        'totalPayments' => __m('Total Payments'),
        'totalAdjustments' => __m('Total Adjustments'),
        'balance' => __m('Balance')
    ];

    foreach ($balanceItems as $key => $label) {
        $previousValue = $previousData ? ($previousData['balance'][$key] ?? 0) : 0;
        $currentValue = $currentData['balance'][$key] ?? 0;
        $change = $currentValue - $previousValue;

        $changeColor = $change > 0 ? 'green' : ($change < 0 ? 'red' : 'black');
        $changePrefix = $change > 0 ? '+' : '';

        echo '<tr>';
        echo '<td><strong>' . $label . '</strong></td>';
        echo '<td style="text-align: right;">' . number_format($previousValue, 2) . ' €</td>';
        echo '<td style="text-align: right;">' . number_format($currentValue, 2) . ' €</td>';
        echo '<td style="text-align: right; color: ' . $changeColor . '; font-weight: bold;">' . $changePrefix . number_format($change, 2) . ' €</td>';
        echo '</tr>';
    }

    echo '</table>';

    // Display fees details - Current and Previous
    echo '<h4>' . __m('Fees Details') . '</h4>';

    // Current fees
    echo '<h5>' . ($snapshotDate == 'current' ? __m('Current Fees') : __m('Snapshot Fees')) . '</h5>';
    $feesData = new \Gibbon\Domain\DataSet($currentData['fees']);
    $feesTable = DataTable::create('feesDetails');

    $feesTable->addColumn('childName', __m('Child Name'))->format(function ($row) {
        $name = htmlspecialchars($row['childName'], ENT_QUOTES, 'UTF-8');
        if (!empty($row['personDateEnd'])) {
            $name .= ' <span style="color: red;">(Left on ' . htmlspecialchars($row['personDateEnd'], ENT_QUOTES, 'UTF-8') . ')</span>';
        }
        return $name;
    });
    $feesTable->addColumn('courseName', __m('Course'));
    $feesTable->addColumn('courseFee', __m('Fee'))->format(function ($row) {
        return number_format($row['courseFee'], 2) . ' €';
    });
    $feesTable->addColumn('monthsEnrolled', __m('Months Enrolled'));
    $feesTable->addColumn('totalCost', __m('Total Cost'))->format(function ($row) {
        return number_format($row['totalCost'], 2) . ' €';
    });

    echo $feesTable->render($feesData);

    // Previous fees
    if ($previousData && !empty($previousData['fees'])) {
        echo '<h5>' . __m('Previous Snapshot Fees') . '</h5>';
        $prevFeesData = new \Gibbon\Domain\DataSet($previousData['fees']);
        $prevFeesTable = DataTable::create('prevFeesDetails');

        $prevFeesTable->addColumn('childName', __m('Child Name'))->format(function ($row) {
            $name = htmlspecialchars($row['childName'], ENT_QUOTES, 'UTF-8');
            if (!empty($row['personDateEnd'])) {
                $name .= ' <span style="color: red;">(Left on ' . htmlspecialchars($row['personDateEnd'], ENT_QUOTES, 'UTF-8') . ')</span>';
            }
            return $name;
        });
        $prevFeesTable->addColumn('courseName', __m('Course'));
        $prevFeesTable->addColumn('courseFee', __m('Fee'))->format(function ($row) {
            return number_format($row['courseFee'], 2) . ' €';
        });
        $prevFeesTable->addColumn('monthsEnrolled', __m('Months Enrolled'));
        $prevFeesTable->addColumn('totalCost', __m('Total Cost'))->format(function ($row) {
            return number_format($row['totalCost'], 2) . ' €';
        });

        echo $prevFeesTable->render($prevFeesData);
    }

    // Display payments - Current and Previous
    echo '<h4>' . __m('Payment Entries') . '</h4>';

    // Current payments
    echo '<h5>' . ($snapshotDate == 'current' ? __m('Current Payments') : __m('Snapshot Payments')) . '</h5>';
    if (!empty($currentData['payments'])) {
        $paymentsData = new \Gibbon\Domain\DataSet($currentData['payments']);
        $paymentsTable = DataTable::create('paymentsDetails');

        $paymentsTable->addColumn('booking_date', __m('Booking Date'));
        $paymentsTable->addColumn('amount', __m('Amount'))->format(function ($row) {
            return number_format($row['amount'], 2) . ' €';
        });
        $paymentsTable->addColumn('payer', __m('Payer'));
        $paymentsTable->addColumn('transaction_message', __m('Transaction Message'));
        $paymentsTable->addColumn('payment_method', __m('Payment Method'));

        echo $paymentsTable->render($paymentsData);
    } else {
        echo '<p><em>' . __m('No payment entries recorded.') . '</em></p>';
    }

    // Previous payments
    if ($previousData && !empty($previousData['payments'])) {
        echo '<h5>' . __m('Previous Snapshot Payments') . '</h5>';
        $prevPaymentsData = new \Gibbon\Domain\DataSet($previousData['payments']);
        $prevPaymentsTable = DataTable::create('prevPaymentsDetails');

        $prevPaymentsTable->addColumn('booking_date', __m('Booking Date'));
        $prevPaymentsTable->addColumn('amount', __m('Amount'))->format(function ($row) {
            return number_format($row['amount'], 2) . ' €';
        });
        $prevPaymentsTable->addColumn('payer', __m('Payer'));
        $prevPaymentsTable->addColumn('transaction_message', __m('Transaction Message'));
        $prevPaymentsTable->addColumn('payment_method', __m('Payment Method'));

        echo $prevPaymentsTable->render($prevPaymentsData);
    }

    // Display adjustments - Current and Previous
    echo '<h4>' . __m('Adjustment Entries') . '</h4>';

    // Current adjustments
    echo '<h5>' . ($snapshotDate == 'current' ? __m('Current Adjustments') : __m('Snapshot Adjustments')) . '</h5>';
    if (!empty($currentData['adjustments'])) {
        $adjustmentsData = new \Gibbon\Domain\DataSet($currentData['adjustments']);
        $adjustmentsTable = DataTable::create('adjustmentsDetails');

        $adjustmentsTable->addColumn('amount', __m('Amount'))->format(function ($row) {
            return number_format($row['amount'], 2) . ' €';
        });
        $adjustmentsTable->addColumn('description', __m('Description'));
        $adjustmentsTable->addColumn('note', __m('Note'));
        $adjustmentsTable->addColumn('timestamp', __m('Timestamp'));

        echo $adjustmentsTable->render($adjustmentsData);
    } else {
        echo '<p><em>' . __m('No adjustment entries recorded.') . '</em></p>';
    }

    // Previous adjustments
    if ($previousData && !empty($previousData['adjustments'])) {
        echo '<h5>' . __m('Previous Snapshot Adjustments') . '</h5>';
        $prevAdjustmentsData = new \Gibbon\Domain\DataSet($previousData['adjustments']);
        $prevAdjustmentsTable = DataTable::create('prevAdjustmentsDetails');

        $prevAdjustmentsTable->addColumn('amount', __m('Amount'))->format(function ($row) {
            return number_format($row['amount'], 2) . ' €';
        });
        $prevAdjustmentsTable->addColumn('description', __m('Description'));
        $prevAdjustmentsTable->addColumn('note', __m('Note'));
        $prevAdjustmentsTable->addColumn('timestamp', __m('Timestamp'));

        echo $prevAdjustmentsTable->render($prevAdjustmentsData);
    }

    // Show detailed comparison if previous snapshot exists
    if ($previousData) {
        echo '<h4>' . __m('Detailed Changes') . '</h4>';

        // Compare fees
        $previousFees = $previousData['fees'] ?? [];
        $currentFees = $currentData['fees'] ?? [];

        $feeChanges = [];

        // Check for new or changed fees
        foreach ($currentFees as $currentFee) {
            $found = false;
            foreach ($previousFees as $previousFee) {
                // Match by ID if available, otherwise fall back to name matching
                $isMatch = false;
                if (isset($currentFee['gibbonPersonID']) && isset($previousFee['gibbonPersonID']) &&
                    isset($currentFee['gibbonCourseID']) && isset($previousFee['gibbonCourseID'])) {
                    $isMatch = ($currentFee['gibbonPersonID'] == $previousFee['gibbonPersonID'] &&
                                $currentFee['gibbonCourseID'] == $previousFee['gibbonCourseID']);
                } else {
                    $isMatch = ($currentFee['childName'] == $previousFee['childName'] &&
                                $currentFee['courseName'] == $previousFee['courseName']);
                }

                if ($isMatch) {
                    $found = true;
                    if ($currentFee['totalCost'] != $previousFee['totalCost'] ||
                        $currentFee['monthsEnrolled'] != $previousFee['monthsEnrolled']) {
                        $feeChanges[] = [
                            'type' => 'changed',
                            'childName' => $currentFee['childName'],
                            'courseName' => $currentFee['courseName'],
                            'previousCost' => $previousFee['totalCost'],
                            'currentCost' => $currentFee['totalCost']
                        ];
                    }
                    break;
                }
            }

            if (!$found) {
                $feeChanges[] = [
                    'type' => 'new',
                    'childName' => $currentFee['childName'],
                    'courseName' => $currentFee['courseName'],
                    'previousCost' => 0,
                    'currentCost' => $currentFee['totalCost']
                ];
            }
        }

        // Check for removed fees
        foreach ($previousFees as $previousFee) {
            $found = false;
            foreach ($currentFees as $currentFee) {
                // Match by ID if available, otherwise fall back to name matching
                $isMatch = false;
                if (isset($currentFee['gibbonPersonID']) && isset($previousFee['gibbonPersonID']) &&
                    isset($currentFee['gibbonCourseID']) && isset($previousFee['gibbonCourseID'])) {
                    $isMatch = ($currentFee['gibbonPersonID'] == $previousFee['gibbonPersonID'] &&
                                $currentFee['gibbonCourseID'] == $previousFee['gibbonCourseID']);
                } else {
                    $isMatch = ($currentFee['childName'] == $previousFee['childName'] &&
                                $currentFee['courseName'] == $previousFee['courseName']);
                }

                if ($isMatch) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $feeChanges[] = [
                    'type' => 'removed',
                    'childName' => $previousFee['childName'],
                    'courseName' => $previousFee['courseName'],
                    'previousCost' => $previousFee['totalCost'],
                    'currentCost' => 0
                ];
            }
        }

        if (!empty($feeChanges)) {
            echo '<h5>' . __m('Fee Changes') . '</h5>';
            echo '<ul>';
            foreach ($feeChanges as $change) {
                if ($change['type'] == 'new') {
                    echo '<li><strong style="color: green;">[NEW]</strong> ' . htmlspecialchars($change['childName']) . ' - ' . htmlspecialchars($change['courseName']) . ': ' . number_format($change['currentCost'], 2) . ' €</li>';
                } elseif ($change['type'] == 'removed') {
                    echo '<li><strong style="color: red;">[REMOVED]</strong> ' . htmlspecialchars($change['childName']) . ' - ' . htmlspecialchars($change['courseName']) . ': was ' . number_format($change['previousCost'], 2) . ' €</li>';
                } else {
                    $diff = $change['currentCost'] - $change['previousCost'];
                    $color = $diff > 0 ? 'orange' : 'blue';
                    echo '<li><strong style="color: ' . $color . ';">[CHANGED]</strong> ' . htmlspecialchars($change['childName']) . ' - ' . htmlspecialchars($change['courseName']) . ': ' . number_format($change['previousCost'], 2) . ' € → ' . number_format($change['currentCost'], 2) . ' €</li>';
                }
            }
            echo '</ul>';
        }

        // Compare payments
        $previousPayments = $previousData['payments'] ?? [];
        $currentPayments = $currentData['payments'] ?? [];

        $newPayments = [];
        foreach ($currentPayments as $currentPayment) {
            $found = false;
            foreach ($previousPayments as $previousPayment) {
                if ($currentPayment['gibbonSEPAPaymentRecordID'] == $previousPayment['gibbonSEPAPaymentRecordID']) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $newPayments[] = $currentPayment;
            }
        }

        if (!empty($newPayments)) {
            echo '<h5>' . __m('New Payments') . '</h5>';
            echo '<ul>';
            foreach ($newPayments as $payment) {
                echo '<li><strong style="color: green;">[NEW]</strong> ' . $payment['booking_date'] . ': ' . number_format($payment['amount'], 2) . ' € - ' . htmlspecialchars($payment['transaction_message']) . '</li>';
            }
            echo '</ul>';
        }

        // Compare adjustments
        $previousAdjustments = $previousData['adjustments'] ?? [];
        $currentAdjustments = $currentData['adjustments'] ?? [];

        $newAdjustments = [];
        foreach ($currentAdjustments as $currentAdjustment) {
            $found = false;
            foreach ($previousAdjustments as $previousAdjustment) {
                if ($currentAdjustment['gibbonSEPAPaymentAdjustmentID'] == $previousAdjustment['gibbonSEPAPaymentAdjustmentID']) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $newAdjustments[] = $currentAdjustment;
            }
        }

        if (!empty($newAdjustments)) {
            echo '<h5>' . __m('New Adjustments') . '</h5>';
            echo '<ul>';
            foreach ($newAdjustments as $adjustment) {
                echo '<li><strong style="color: green;">[NEW]</strong> ' . number_format($adjustment['amount'], 2) . ' € - ' . htmlspecialchars($adjustment['description']) . '</li>';
            }
            echo '</ul>';
        }

        if (empty($feeChanges) && empty($newPayments) && empty($newAdjustments)) {
            echo '<p><em>' . __m('No detailed changes found.') . '</em></p>';
        }
    }

    echo '<div class="linkTop">';
    echo '<a href="' . $_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/Sepa/sepa_balance_snapshot.php&schoolYearID=' . $schoolYearID . '&snapshotDate=' . $snapshotDate . '&search=' . urlencode($search) . '">' . __m('Back to Snapshots') . '</a>';
    echo '</div>';
}
