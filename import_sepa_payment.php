<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community
Copyright © 2010, Gibbon Foundation
*/

use Gibbon\Forms\Form;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Gibbon\Services\Format;

require_once __DIR__ . '/../../gibbon.php';

if (isActionAccessible($guid, $connection2, "/modules/Sepa/import_sepa_payment.php") == false) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {

    $step = isset($_GET['step']) ? min(max(1, $_GET['step']), 4) : 1;

    $page->breadcrumbs
        ->add(__('Import SEPA Payments'), 'import_sepa_payment.php')
        ->add(__('Step {number}', ['number' => $step]));

    $steps = [
        1 => __('Select File'),
        2 => __('Confirm Data'),
        3 => __('Dry Run'),
        4 => __('Live Run'),
    ];
    $StepLink = $session->get('absoluteURL') . '/index.php?q=' . $_SESSION[$guid]['address'] . '&step=';

    // Display steps progress
    echo "<ul class='multiPartForm'>";
    foreach ($steps as $stepNumber => $stepName) {
        printf("<li class='step %s'>%s</li>", ($step >= $stepNumber) ? "active" : "", $stepName);
    }
    echo "</ul>";

    echo '<h2>';
    echo __('Step {number}', ['number' => $step]) . ' - ' . __($steps[$step]);
    echo '</h2>';

    // field data
    $fields = [
        'booking_date',
        'SEPA_ownerName',
        'SEPA_IBAN',
        'SEPA_transaction',
        'payment_message',
        'note',
        'amount'
    ];
    $requiredField = ['booking_date', 'SEPA_ownerName', 'amount'];


    // STEP 1: SELECT FILE
    if ($step == 1) {

        $form = Form::create('importStep1', $StepLink . '2');

        $form->addHiddenValue('address', $_SESSION[$guid]['address']);

        $row = $form->addRow();
        $row->addLabel('file', __('File'))->description(__('Excel file (.xlsx, .xls)'));
        $row->addFileUpload('file')->required()->accepts('.xlsx, .xls');

        $row = $form->addRow();
        $row->addFooter();
        $row->addSubmit();

        echo $form->getOutput();


    }
    // STEP 2: CONFIRM DATA
    else if ($step == 2) {
        if (!isset($_FILES['file'])) {
            $page->addError(__('No file was uploaded'));
            return;
        }

        try {
            $inputFileName = $_FILES['file']['tmp_name'];
            $spreadsheet = IOFactory::load($inputFileName);
            $worksheet = $spreadsheet->getActiveSheet();
            $data = $worksheet->toArray();
            $headers = array_shift($data); // Get headers from first row

            $reversedHeaderRowNum = [];

            foreach ($headers as $key => $value) {
                // Check if the value is not null and not an empty string
                if ($value !== null && $value !== '') {
                    $reversedHeaderRowNum[$value] = $key; // Set value as key and key as value
                }
            }
            // Store data in session for next steps
            $_SESSION[$guid]['sepaImportData'] = $data;
            $_SESSION[$guid]['sepaImportDataHeaders'] = $reversedHeaderRowNum;

            // Display column mapping
            $form = Form::create('importStep2', $StepLink . '3');

            $form->addHiddenValue('address', $_SESSION[$guid]['address']);

            $row = $form->addRow();
            $row->addHeading(__('Column Mapping'));

            foreach ($fields as $field) {
                $row = $form->addRow();
                $row->addLabel("map[$field]", __($field));
                $row->addSelect("map[$field]")
                    ->fromArray(array_combine(range(0, count($headers) - 1), $headers))
                    ->placeholder();
            }

            $row = $form->addRow();
            $row->addFooter();
            $row->addSubmit();

            echo $form->getOutput();

        } catch (Exception $e) {
            $page->addError($e->getMessage());
        }
    }
    // STEP 3: DRY RUN
    else if ($step == 3) {
        $data = $_SESSION[$guid]['sepaImportData'] ?? null;
        $headers = $_SESSION[$guid]['sepaImportDataHeaders'] ?? [];
        $mapping = $_POST['map'] ?? null;

        if (empty($data) || empty($mapping)) {
            $page->addError(__('Invalid data'));
            return;
        }

        // Perform dry run validation
        $errors = [];
        $validData = [];

        foreach ($data as $rowIndex => $row) {
            if ($rowIndex === 0)
                continue; // Skip header row

            $mappedRow = [];
            $error = [];
            foreach ($mapping as $field => $colIndex) {
                $mappedRow[$field] = isset($headers[$colIndex]) ? $row[$headers[$colIndex]] : '';

                // Validate required fields
                if (empty($mappedRow[$field]) && in_array($field, $requiredField)) {

                    $error = 'Row (' . $rowIndex + 2 . '): ' . implode(' ', $row);
                    break;
                }
            }
            if (!empty($error)) {
                $errors[] = $error;
            } else {
                $mappedRow = array_merge(['__RowNumberInExcelFile__' => $rowIndex+2], $mappedRow);
                $validData[] = $mappedRow;
            }

        }

        // Display validation results
        if (!empty($errors)) {
            echo "<div class='error'>";
            echo "<h3>" . __('Validation Errors') . "</h3>";
            echo "Missing one or more data of [ " . implode(', ', $requiredField) . " ] ";
            echo "<ul><li>" . implode("</li><li>", $errors) . "</li></ul>";
            echo "</div>";
        }
        if (!empty($validData)) {
            echo "<div class='success'>";
            echo __(count($validData) . ' Rows are ready for import.');
            echo "</div>";
        }

        // Store valid data for final step
        $_SESSION[$guid]['sepaValidData'] = $validData;

        $form = Form::create('importStep3', $StepLink . '4');
        $form->addHiddenValue('address', $_SESSION[$guid]['address']);

        $row = $form->addRow();
        $row->addFooter();
        $row->addSubmit(__('Proceed to Import'));

        echo $form->getOutput();
    }
    // STEP 4: LIVE RUN
    else if ($step == 4) {
        $data = $_SESSION[$guid]['sepaValidData'] ?? null;

        if (empty($data)) {
            $page->addError(__('Invalid data'));
            return;
        }

        try {
            $count = 0;
            $unprocessedRows = [];
            foreach ($data as $row) {
                $row['booking_date'] = Format::dateConvert(str_replace('.', '/', $row['booking_date']));
                $whereclause = [];
                foreach ($requiredField as $field) {
                    $whereclause[] = $field . ' = "' . $row[$field] . '"';
                }
                $sql_select = 'SELECT gibbonSEPAPaymentRecordID FROM gibbonSEPAPaymentEntry WHERE ' . implode(' AND ', $whereclause);
                $existsRow = $connection2->prepare($sql_select);
                $existsRow->execute();
                if ($existsRow->rowCount() === 0) {
                    // Insert data into database
                    $sql = "INSERT INTO gibbonSEPAPaymentEntry (
                        booking_date,
                        SEPA_ownerName,
                        SEPA_IBAN,
                        SEPA_transaction,
                        payment_message,
                        amount,
                        note,
                        user
                    ) VALUES (
                        :booking_date,
                        :SEPA_ownerName,
                        :SEPA_IBAN,
                        :SEPA_transaction,
                        :payment_message,
                        :amount,
                        :note,
                        :user
                    )";
                    $result = $connection2->prepare($sql);
                    $result->execute([
                        ':booking_date' => $row['booking_date'],
                        ':SEPA_ownerName' => $row['SEPA_ownerName'],
                        ':SEPA_IBAN' => $row['SEPA_IBAN'],
                        ':SEPA_transaction' => $row['SEPA_transaction'],
                        ':payment_message' => $row['payment_message'],
                        ':amount' => $row['amount'],
                        ':note' => $row['note'],
                        ':user' => $_SESSION[$guid]["username"]
                    ]);
                    $count++;
                } else {
                    $unprocessedRows[] = implode(' | ', $row);
                }
            }

            echo "<div class='success'>";
            echo sprintf(__('Successfully imported %d records'), $count);
            echo "</div>";
            echo "<div class='error'>";
            echo sprintf(__('%d records are already exists'), count($unprocessedRows));
            echo "<ul><li>" . implode("</li><li>", $unprocessedRows) . "</li></ul>";
            echo "</div>";

            // Clear session data
            unset($_SESSION[$guid]['sepaImportData']);
            unset($_SESSION[$guid]['sepaValidData']);
            unset($_SESSION[$guid]['sepaImportDataHeaders']);

        } catch (Exception $e) {
            $page->addError($e->getMessage());
        }
    }
}
