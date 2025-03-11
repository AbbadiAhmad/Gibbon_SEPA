<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community
Copyright © 2010, Gibbon Foundation
*/

use Gibbon\Forms\Form;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Gibbon\Services\Format;
use Gibbon\Module\Sepa\Domain\SepaGateway;

require_once __DIR__ . '/../../gibbon.php';

if (isActionAccessible($guid, $connection2, "/modules/Sepa/import_sepa_data.php") == false) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {

    $step = isset($_GET['step']) ? min(max(1, $_GET['step']), 4) : 1;

    $page->breadcrumbs
        ->add(__('Import SEPA Data'), 'import_sepa_data.php')
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
        'SEPA_ownerName',
        'SEPA_IBAN',
        'SEPA_BIC',
        'SEPA_signedDate',
        'note'
    ];
    $requiredField = ['SEPA_ownerName'];
    $availableDataFormat = ["d.m.Y", "d/m/Y", "d-m-Y", "Y-m-d"];

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
            // add date formate in the paymentlist file
            $row = $form->addRow();
            $row->addLabel("Date format", __("Date format"));
            $row->addSelect("dateFormat")
                ->fromArray($availableDataFormat);

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
        if (in_array($_POST['dateFormat'], $availableDataFormat)) {
            $dateFormat = $_POST['dateFormat'];
        } else {
            echo 'Unsupported date format';
            return;
        }

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
                $mappedRow = array_merge(['__RowNumberInExcelFile__' => $rowIndex + 2], $mappedRow);
                // convert date into mysql date format
                if (!empty($mappedRow['SEPA_signedDate']))
                    $mappedRow['SEPA_signedDate'] = DateTime::createFromFormat($dateFormat, $mappedRow['SEPA_signedDate'])->format('Y-m-d');

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
            $SepaGateway = $container->get(SepaGateway::class);
            $count = 0;
            $unprocessedRows = [];
            foreach ($data as $row) {
                // check the names without spaces and in lower cases
                
                $userID = $SepaGateway->getUserID($row['SEPA_ownerName']);

                // if only one person found
                if (count($userID) === 1 && $SepaGateway->insertSEPAByUserName($userID[0], $row)) {
                    $count++;
                } else {
                    $unprocessedRows[] = implode(' | ', $row);
                }
            }

            echo "<div class='success'>";
            echo sprintf(__('Successfully imported %d records'), $count);
            echo "</div>";
            echo "<div class='error'>";
            echo sprintf(__('%d records are already exists or the can not find a user with a similar name to SEPA owner.'), count($unprocessedRows));
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
