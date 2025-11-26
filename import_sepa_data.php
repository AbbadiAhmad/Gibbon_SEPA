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
use Gibbon\Data\Validator;

$_GET = $container->get(Validator::class)->sanitize($_GET);
$_POST = $container->get(Validator::class)->sanitize($_POST);
$URL = $gibbon->session->get('absoluteURL') . '/index.php?q=/modules/' . $gibbon->session->get('module') . '/import_sepa_data.php';

require_once __DIR__ . '/../../gibbon.php';

if (isActionAccessible($guid, $connection2, "/modules/Sepa/import_sepa_data.php") == false) {
    // Access denied
    $URL = $URL . '&return=error0';
    header("Location: {$URL}");

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
        'payer',
        'IBAN',
        'BIC',
        'SEPA_signedDate',
        'note'
    ];
    $requiredField = ['payer'];
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
        if (!isset($_SESSION[$guid]['sepaImportData'])) {
            $page->addError(__('Invalid data'));
            return;
        }
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
        $SepaGateway = $container->get(SepaGateway::class);

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

                // Check if record exists
                $userID = $SepaGateway->getUserID($mappedRow['payer']);
                $mappedRow['__UserID__'] = $userID;
                $mappedRow['__Status__'] = 'error';
                $mappedRow['__ExistingData__'] = null;

                if (count($userID) === 1) {
                    $existingSEPA = $SepaGateway->getSEPAByUserID($userID[0]);
                    if (!empty($existingSEPA)) {
                        $mappedRow['__Status__'] = 'existing';
                        $mappedRow['__ExistingData__'] = $existingSEPA[0];
                    } else {
                        $mappedRow['__Status__'] = 'new';
                        $mappedRow['__ExistingData__'] = ['payer'=>'User name: '.$mappedRow['payer']];
                    }
                } elseif (count($userID) === 0) {
                    $mappedRow['__Status__'] = 'user_not_found';
                } else {
                    $mappedRow['__Status__'] = 'multiple_users';
                    $mappedRow['__ExistingData__'] = ['payer'=>"User IDs: ".join(',', $userID) ];
                }

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

        // Sort data by status in the specified order
        $statusOrder = [
            'multiple_users' => 1,
            'new' => 2,
            'user_not_found' => 3,
            'existing' => 4
        ];

        usort($validData, function($a, $b) use ($statusOrder) {
            $orderA = $statusOrder[$a['__Status__']] ?? 999;
            $orderB = $statusOrder[$b['__Status__']] ?? 999;
            return $orderA - $orderB;
        });

        // Store valid data for final step
        $_SESSION[$guid]['sepaValidData'] = $validData;

        // Display preview table
        if (!empty($validData)) {
            echo "<h3>" . __('Import Preview') . "</h3>";
            echo "<p>" . __('Review the records below and select which existing records to update.') . "</p>";
            echo "<div class='warning' style='margin-bottom: 15px;'>";
            echo "<strong>" . __('Security Notice:') . "</strong> ";
            echo __('IBANs will be automatically masked before storage (format: XX****XXX). Full IBAN data shown below will NOT be saved to the database. BIC codes will NOT be stored.');
            echo "</div>";

            // Add export button
            echo "<div style='margin-bottom: 15px;'>";
            echo "<a href='" . $session->get('absoluteURL') . "/modules/Sepa/import_sepa_data_export.php' class='button' style='text-decoration: none;'>";
            echo "<img src='./themes/Default/img/download.png' title='" . __('Export') . "' style='margin-right: 5px;' />";
            echo __('Export to Excel');
            echo "</a>";
            echo "</div>";

            // Start form
            $form = Form::create('importStep3', $StepLink . '4');
            $form->addHiddenValue('address', $_SESSION[$guid]['address']);

            // Add raw HTML for the table
            $tableHTML = "<table class='fullWidth standardForm' cellspacing='0'>";
            $tableHTML .= "<thead>";
            $tableHTML .= "<tr>";
            $tableHTML .= "<th style='width: 5%; text-align: center;'>";
            $tableHTML .= "<input type='checkbox' id='selectAll' title='" . __('Select All') . "' />";
            $tableHTML .= "</th>";
            $tableHTML .= "<th style='width: 10%;'>" . __('Status') . "</th>";
            $tableHTML .= "<th style='width: 5%;'>" . __('Row') . "</th>";
            $tableHTML .= "<th style='width: 20%;'>" . __('Payer') . "</th>";
            $tableHTML .= "<th style='width: 15%;'>" . __('IBAN') . "</th>";
            $tableHTML .= "<th style='width: 10%;'>" . __('BIC') . "</th>";
            $tableHTML .= "<th style='width: 15%;'>" . __('Signed Date') . "</th>";
            $tableHTML .= "<th style='width: 20%;'>" . __('Note') . "</th>";
            $tableHTML .= "</tr>";
            $tableHTML .= "</thead>";
            $tableHTML .= "<tbody>";

            $newCount = 0;
            $existingCount = 0;
            $errorCount = 0;

            foreach ($validData as $index => $record) {
                $rowClass = '';
                $statusText = '';
                $statusColor = '';
                $showCheckbox = false;

                switch ($record['__Status__']) {
                    case 'new':
                        $statusText = __('New');
                        $statusColor = 'green';
                        $newCount++;
                        break;
                    case 'existing':
                        $statusText = __('Existing');
                        $statusColor = 'orange';
                        $showCheckbox = true;
                        $existingCount++;
                        break;
                    case 'user_not_found':
                        $statusText = __('User Not Found');
                        $statusColor = 'red';
                        $errorCount++;
                        break;
                    case 'multiple_users':
                        $statusText = __('Multiple Users');
                        $statusColor = 'red';
                        $errorCount++;
                        break;
                }

                $tableHTML .= "<tr>";
                $tableHTML .= "<td style='text-align: center;'>";
                if ($showCheckbox) {
                    $tableHTML .= "<input type='checkbox' name='updateRecords[]' value='{$index}' />";
                } else {
                    $tableHTML .= "-";
                }
                $tableHTML .= "</td>";
                $tableHTML .= "<td style='color: {$statusColor}; font-weight: bold;'>{$statusText}</td>";
                $tableHTML .= "<td>{$record['__RowNumberInExcelFile__']}</td>";
                $tableHTML .= "<td>{$record['payer']}</td>";
                $tableHTML .= "<td>{$record['IBAN']}</td>";
                $tableHTML .= "<td>{$record['BIC']}</td>";
                $tableHTML .= "<td>{$record['SEPA_signedDate']}</td>";
                $tableHTML .= "<td>{$record['note']}</td>";
                $tableHTML .= "</tr>";

                // Show existing data for comparison if status is existing
                //if ($record['__Status__'] === 'existing' && !empty($record['__ExistingData__'])) {
                if ( !empty($record['__ExistingData__'])) {
                    $existing = $record['__ExistingData__'];
                    $existing['payer'] = $existing['payer'] ?? '';
                    $existing['IBAN'] = $existing['IBAN'] ?? '';
                    $existing['BIC'] = $existing['BIC'] ?? '';
                    $existing['SEPA_signedDate'] = $existing['SEPA_signedDate'] ?? '';
                    $existing['note'] = $existing['note'] ?? '';

                    $tableHTML .= "<tr style='background-color: #f5f5f5; font-style: italic;'>";
                    $tableHTML .= "<td colspan='3' style='text-align: right; padding-right: 10px;'>" . __('Current Data:') . "</td>";
                    $tableHTML .= "<td>{$existing['payer']}</td>";
                    $tableHTML .= "<td>{$existing['IBAN']}</td>";
                    $tableHTML .= "<td>{$existing['BIC']}</td>";
                    $tableHTML .= "<td>{$existing['SEPA_signedDate']}</td>";
                    $tableHTML .= "<td>{$existing['note']}</td>";
                    $tableHTML .= "</tr>";
                }
            }

            $tableHTML .= "</tbody>";
            $tableHTML .= "</table>";

            // Add JavaScript for select all functionality
            $tableHTML .= "<script>
            document.getElementById('selectAll').addEventListener('change', function() {
                var checkboxes = document.getElementsByName('updateRecords[]');
                for (var i = 0; i < checkboxes.length; i++) {
                    checkboxes[i].checked = this.checked;
                }
            });
            </script>";

            $tableHTML .= "<div class='success' style='margin-top: 20px;'>";
            $tableHTML .= "<strong>" . __('Summary:') . "</strong><br/>";
            $tableHTML .= __('New records:') . " {$newCount}<br/>";
            $tableHTML .= __('Existing records:') . " {$existingCount}<br/>";
            $tableHTML .= __('Errors:') . " {$errorCount}<br/>";
            $tableHTML .= "</div>";

            // Add table as a custom row
            $row = $form->addRow();
            $row->addContent($tableHTML);

            $row = $form->addRow();
            $row->addFooter();
            $row->addSubmit(__('Proceed to Import'));

            echo $form->getOutput();
        }
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

            // Get selected records to update (convert to integers for comparison)
            $updateRecords = array_map('intval', $_POST['updateRecords'] ?? []);

            $insertedCount = 0;
            $updatedCount = 0;
            $skippedCount = 0;
            $errorCount = 0;

            $insertedRows = [];
            $updatedRows = [];
            $skippedRows = [];
            $errorRows = [];

            foreach ($data as $index => $row) {
                $status = $row['__Status__'];
                $userID = $row['__UserID__'];

                // Process based on status
                if ($status === 'new' && count($userID) === 1) {
                    // Insert new record
                    if ($SepaGateway->insertSEPAByUserName($userID[0], $row)) {
                        $insertedCount++;
                        $insertedRows[] = "Row {$row['__RowNumberInExcelFile__']}: {$row['payer']}";
                    } else {
                        $errorCount++;
                        $errorRows[] = "Row {$row['__RowNumberInExcelFile__']}: {$row['payer']} - Failed to insert";
                    }
                } elseif ($status === 'existing' && count($userID) === 1) {
                    // Check if user selected to update this record
                    if (in_array($index, $updateRecords)) {
                        // Update existing record
                        if ($SepaGateway->updateSEPAByUserID($userID[0], $row)) {
                            $updatedCount++;
                            $updatedRows[] = "Row {$row['__RowNumberInExcelFile__']}: {$row['payer']}";
                        } else {
                            $errorCount++;
                            $errorRows[] = "Row {$row['__RowNumberInExcelFile__']}: {$row['payer']} - Failed to update";
                        }
                    } else {
                        // Skip existing record (not selected for update)
                        $skippedCount++;
                        $skippedRows[] = "Row {$row['__RowNumberInExcelFile__']}: {$row['payer']}";
                    }
                } else {
                    // Error cases (user not found or multiple users)
                    $errorCount++;
                    if ($status === 'user_not_found') {
                        $errorRows[] = "Row {$row['__RowNumberInExcelFile__']}: {$row['payer']} - User not found";
                    } elseif ($status === 'multiple_users') {
                        $errorRows[] = "Row {$row['__RowNumberInExcelFile__']}: {$row['payer']} - Multiple users found";
                    } else {
                        $errorRows[] = "Row {$row['__RowNumberInExcelFile__']}: {$row['payer']} - Unknown error";
                    }
                }
            }

            // Display results
            echo "<h3>" . __('Import Results') . "</h3>";

            if ($insertedCount > 0) {
                echo "<div class='success'>";
                echo "<strong>" . sprintf(__('Successfully inserted %d new records'), $insertedCount) . "</strong>";
                if (!empty($insertedRows)) {
                    echo "<ul><li>" . implode("</li><li>", $insertedRows) . "</li></ul>";
                }
                echo "</div>";
            }

            if ($updatedCount > 0) {
                echo "<div class='success'>";
                echo "<strong>" . sprintf(__('Successfully updated %d existing records'), $updatedCount) . "</strong>";
                if (!empty($updatedRows)) {
                    echo "<ul><li>" . implode("</li><li>", $updatedRows) . "</li></ul>";
                }
                echo "</div>";
            }

            if ($skippedCount > 0) {
                echo "<div class='warning'>";
                echo "<strong>" . sprintf(__('Skipped %d existing records (not selected for update)'), $skippedCount) . "</strong>";
                if (!empty($skippedRows)) {
                    echo "<ul><li>" . implode("</li><li>", $skippedRows) . "</li></ul>";
                }
                echo "</div>";
            }

            if ($errorCount > 0) {
                echo "<div class='error'>";
                echo "<strong>" . sprintf(__('Failed to process %d records'), $errorCount) . "</strong>";
                if (!empty($errorRows)) {
                    echo "<ul><li>" . implode("</li><li>", $errorRows) . "</li></ul>";
                }
                echo "</div>";
            }

            // Display overall summary
            echo "<div class='success' style='margin-top: 20px;'>";
            echo "<strong>" . __('Summary:') . "</strong><br/>";
            echo __('Inserted:') . " {$insertedCount}<br/>";
            echo __('Updated:') . " {$updatedCount}<br/>";
            echo __('Skipped:') . " {$skippedCount}<br/>";
            echo __('Errors:') . " {$errorCount}<br/>";
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
