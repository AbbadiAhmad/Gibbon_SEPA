<?php

use Gibbon\Tables\DataTable;
use Gibbon\Tables\Renderer\SpreadsheetRenderer;

require_once __DIR__ . '/../../gibbon.php';

if (isActionAccessible($guid, $connection2, "/modules/Sepa/import_sepa_payment.php") == false) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {

    $data = $_SESSION[$guid]['sepaProcessedData'] ?? [0 => ['Error ' => 'No data available']];

    $renderer = new SpreadsheetRenderer();
    $table = DataTable::create('queryBuilderExport', $renderer);
    $table->setTitle('Payment Process results');

    $filename = 'processed_data_' . date('Y-m-d_H-i-s') . '.xlsx';

    // Set document properties
    $table->addMetaData('creator', 'Annur')
        ->addMetaData('filename', $filename);

    foreach ($data[0] as $colName => $value) {
        $table->addColumn($colName, $colName);
    }
    

    echo $table->render($data);
}
