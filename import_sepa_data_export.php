<?php
require_once __DIR__ . '/src/i18n.php';
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community
Copyright © 2010, Gibbon Foundation
*/

use Gibbon\Tables\DataTable;
use Gibbon\Tables\Renderer\SpreadsheetRenderer;

require_once __DIR__ . '/../../gibbon.php';

if (isActionAccessible($guid, $connection2, "/modules/Sepa/import_sepa_data.php") == false) {
    // Access denied
    $page->addError(__m('You do not have access to this action.'));
} else {

    $data = $_SESSION[$guid]['sepaValidData'] ?? [0 => ['Error' => 'No data available']];

    // Prepare data for export with status text
    $exportData = [];
    foreach ($data as $record) {
        $statusText = '';
        switch ($record['__Status__']) {
            case 'new':
                $statusText = 'New';
                break;
            case 'existing':
                $statusText = 'Existing';
                break;
            case 'user_not_found':
                $statusText = 'User Not Found';
                break;
            case 'multiple_users':
                $statusText = 'Multiple Users';
                break;
        }

        $exportData[] = [
            'Row' => $record['__RowNumberInExcelFile__'] ?? '',
            'Status' => $statusText,
            'Payer' => $record['payer'] ?? '',
            //'IBAN' => $record['IBAN'] ?? '',
            //'BIC' => $record['BIC'] ?? '',
            'Cadence' => $record['withdrawCadence'] ?? '',
            'Day' => $record['withdrawDay'] ?? '',
            'SEPA Signed Date' => $record['SEPA_signedDate'] ?? '',
            'Note' => $record['note'] ?? ''
        ];
    }

    $renderer = new SpreadsheetRenderer();
    $table = DataTable::create('sepaDataExport', $renderer);
    $table->setTitle('SEPA Data Import Preview');

    $filename = 'sepa_data_import_' . date('Y-m-d_H-i-s') . '.xlsx';

    // Set document properties
    $table->addMetaData('creator', 'Gibbon SEPA Module')
        ->addMetaData('filename', $filename);

    // Add columns
    $table->addColumn('Row', __m('Row'));
    $table->addColumn('Status', __m('Status'));
    $table->addColumn('Payer', __m('Payer'));
    //$table->addColumn('IBAN', __m('IBAN'));
    //$table->addColumn('BIC', __m('BIC'));
    $table->addColumn('Cadence', __m('Cadence'));
    $table->addColumn('Day', __m('Day'));
    $table->addColumn('SEPA Signed Date', __m('SEPA Signed Date'));
    $table->addColumn('Note', __m('Note'));

    echo $table->render($exportData);
}
