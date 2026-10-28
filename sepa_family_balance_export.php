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
use Gibbon\Tables\DataTable;
use Gibbon\Tables\Renderer\SpreadsheetRenderer;
use Gibbon\Module\Sepa\Domain\SepaGateway;
use Gibbon\Module\Sepa\Security\IbanCipher;

require_once __DIR__ . '/../../gibbon.php';

if (isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_family_totals.php') == false) {
    echo __('You do not have access to this action.');
    exit;
}

$SepaGateway = $container->get(SepaGateway::class);

$schoolYearID = !empty($_GET['schoolYearID']) ? $_GET['schoolYearID'] : $_SESSION[$guid]['gibbonSchoolYearID'];
$search = $_GET['search'] ?? '';

// Optional period: adds the "Due in Period" column (whole months, same rules as the Family Due page)
$periodFrom = $periodTo = null;
if (!empty($_GET['periodFrom']) && !empty($_GET['periodTo'])) {
    $periodFrom = date('Y-m-01', strtotime(Format::dateConvert($_GET['periodFrom'])));
    $periodTo = date('Y-m-t', strtotime(Format::dateConvert($_GET['periodTo'])));
}

$criteria = $SepaGateway->newQueryCriteria(false)->sortBy(['familyName']);
$familyTotals = $SepaGateway->getFamilyTotals($schoolYearID, $criteria, $search, $periodFrom, $periodTo);
$cadences = SepaGateway::getWithdrawCadenceOptions();

$onlyWithdraw = !empty($_GET['onlyWithdraw']);
$exportData = [];
foreach ($familyTotals as $row) {
    $line = [
        'Family' => $row['familyName'],
        'Payer' => $row['payer'] ?? '',
        'Total Dept' => number_format($row['totalDept'], 2, '.', ''),
        'Payments' => number_format($row['payments'], 2, '.', ''),
        'Payments Adjustment' => number_format($row['paymentsAdjustment'], 2, '.', ''),
        'Balance' => number_format($row['balance'], 2, '.', ''),
    ];
    if ($periodFrom !== null) {
        $withdrawAmount = SepaGateway::calculateWithdrawAmount($row['balance'], $row['dueInPeriod']);
        // Families without SEPA or IBAN are kept in the withdrawal export, flagged in the "Check" column
        $iban = IbanCipher::normalize(IbanCipher::decrypt($row['IBAN'] ?? '') ?? '');
        if (empty($row['gibbonSEPAID'])) {
            $check = 'No SEPA';
        } elseif ($iban === '') {
            $check = 'IBAN missing';
        } elseif (!IbanCipher::isValid($iban)) {
            $check = 'IBAN invalid';
        } else {
            $check = 'OK';
        }
        // withdrawals only: skip families with nothing to withdraw
        if ($onlyWithdraw && $withdrawAmount <= 0) {
            continue;
        }
        $line['Due in Period'] = number_format($row['dueInPeriod'], 2, '.', '');
        $line['Withdraw Amount'] = number_format($withdrawAmount, 2, '.', '');
        $line['Withdraw Cadence'] = $cadences[$row['withdrawCadence']] ?? '';
        $line['Withdraw Day'] = $row['withdrawDay'] ?? '';
        $line['IBAN'] = $iban;
        $line['BIC'] = $row['BIC'] ?? '';
        $line['Mandate Date'] = $row['SEPA_signedDate'] ?? '';
        $line['Mandate Reference'] = $row['gibbonSEPAID'] ?? '';
        $line['Check'] = $check;
    }
    $exportData[] = $line;
}

if (empty($exportData)) {
    $exportData = [['Error' => 'No data available for export']];
}

$renderer = new SpreadsheetRenderer();
$table = DataTable::create('familyBalanceExport', $renderer);
$table->setTitle('Family Balance');
$table->addMetaData('creator', 'Gibbon')
    ->addMetaData('filename', 'family_balance_' . date('Y-m-d_H-i-s') . '.xlsx');

foreach ($exportData[0] as $colName => $value) {
    $table->addColumn($colName, $colName);
}

echo $table->render($exportData);
