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
use Gibbon\Tables\Renderer\SpreadsheetRenderer;
use Gibbon\Module\Sepa\Domain\SepaGateway;
use Gibbon\Module\Sepa\Security\IbanCipher;

require_once __DIR__ . '/../../gibbon.php';

if (isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_check.php') == false) {
    echo __('You do not have access to this action.');
    exit;
}

$SepaGateway = $container->get(SepaGateway::class);
$schoolYearID = !empty($_GET['schoolYearID']) ? $_GET['schoolYearID'] : $_SESSION[$guid]['gibbonSchoolYearID'];
$onlyNonZero = ($_GET['balanceFilter'] ?? 'nonzero') !== 'all';

$cadences = SepaGateway::getWithdrawCadenceOptions();
$exportData = [];
foreach ($SepaGateway->getSEPAIssues($schoolYearID, $onlyNonZero) as $issue) {
    $sepa = $issue['sepa'];
    $exportData[] = [
        'Type' => $issue['severity'],
        'Problem' => $issue['problem'],
        'Family' => $issue['familyName'],
        'Balance' => number_format($issue['balance'], 2, '.', ''),
        'SEPA ID' => $sepa['gibbonSEPAID'] ?? '',
        'Payer' => $issue['payer'],
        // half hidden, the full IBAN is not exported here
        'IBAN' => $sepa ? IbanCipher::maskForDisplay(IbanCipher::decrypt($sepa['IBAN'] ?? '') ?? '') : '',
        'BIC' => $sepa['BIC'] ?? '',
        'Withdraw Cadence' => $sepa ? ($cadences[$sepa['withdrawCadence']] ?? '') : '',
        'Withdraw Day' => $sepa['withdrawDay'] ?? '',
        'SEPA Signed Date' => $sepa['SEPA_signedDate'] ?? '',
        'Note' => $sepa['note'] ?? '',
    ];
}

if (empty($exportData)) {
    $exportData = [['Result' => 'No problems found']];
}

$renderer = new SpreadsheetRenderer();
$table = DataTable::create('sepaCheckExport', $renderer);
$table->setTitle('SEPA Check');
$table->addMetaData('creator', 'Gibbon')
    ->addMetaData('filename', 'sepa_check_' . date('Y-m-d_H-i-s') . '.xlsx');

foreach ($exportData[0] as $colName => $value) {
    $table->addColumn($colName, $colName);
}

echo $table->render($exportData);
