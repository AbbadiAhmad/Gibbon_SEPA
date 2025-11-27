<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community
Copyright © 2010, Gibbon Foundation
*/

use Gibbon\Forms\Form;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Gibbon\Module\Sepa\Domain\SepaGateway;
use Gibbon\Tables\DataTable;
use Gibbon\Data\Validator;

require_once __DIR__ . '/moduleFunctions.php';
require_once __DIR__ . '/../../gibbon.php';

if (isActionAccessible($guid, $connection2, "/modules/Sepa/import_sepa_payment.php") == false) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {

    $step = isset($_GET['step']) ? min(max(1, $_GET['step']), 5) : 1;

    $page->breadcrumbs
        ->add(__('Import SEPA Payments'), 'import_sepa_payment.php')
        ->add(__('Step {number}', ['number' => $step]));

    $steps = [
        1 => __('Select File'),
        2 => __('Confirm Data'),
        3 => __('Dry Run'),
        4 => __('Live Run'),
        5 => __('Final step'),
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
        'payer',
        'IBAN',
        'transaction_reference',
        'transaction_message',
        'note',
        'amount',
        'payment_method',
    ];
    $requiredField = ['booking_date', 'payer', 'amount','academicYear'];
    $availableDataFormat = ["d.m.Y", "d/m/Y", "d-m-Y", "Y-m-d"];
    $DecSepFormat = [',', '.'];

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
            $row->addLabel("Academic Year", __("Academic Year"));
            $row->addTextField("academicYear")
                ->placeholder($_SESSION[$guid]["gibbonSchoolYearName"])
                ->disabled();
            $form->addHiddenValue('academicYearID', $_SESSION[$guid]["gibbonSchoolYearID"]);
            $form->addHiddenValue('academicYear', $_SESSION[$guid]["gibbonSchoolYearName"]);  
            // add date formate in the paymentlist file
            $row = $form->addRow();
            $row->addLabel("Date format", __("Date format"));
            $row->addSelect("dateFormat")
                ->fromArray($availableDataFormat);
            
            // add decimal separator
            $row = $form->addRow();
            $row->addLabel("decimalSeparator", __("Decimal Separator"));
            $row->addSelect("decimalSeparator")
                ->fromArray($DecSepFormat);

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
        $mapping = $_POST['map'] ?? null;
        $headers = $_SESSION[$guid]['sepaImportDataHeaders'] ?? [];
        if (empty($data) || empty($mapping)) {
            $page->addError(__('Invalid data'));
            return;
        }
        
        $academicYear = $_POST['academicYear'] ?? null;
        $academicYearID = $_POST['academicYearID'] ?? null;
 
        if (in_array($_POST['dateFormat'], $availableDataFormat)) {
            $dateFormat = $_POST['dateFormat'];
        } else {
            echo 'Unsupported date format';
            return;
        }
        
        if (in_array($_POST['decimalSeparator'], $DecSepFormat)) {
            $decimalSeparator = $_POST['decimalSeparator'];
        } else {
            echo 'Unsupported decimal Separator format';
            return;
        }

        // Perform dry run validation
        $processedData = [];
        $validData = [];
        $otherData = [];
        $validDataCount = 0;

        foreach ($data as $rowIndex => $row) {

            $mappedRow = [];
            $mappedRow['gibbonSEPAID'] = null;
            $errorMessage = '';
            foreach ($mapping as $field => $colIndex) {
                $mappedRow[$field] = isset($headers[$colIndex]) ? $row[$headers[$colIndex]] : '';
                // Validate required fields
                if (empty($mappedRow[$field]) && in_array($field, $requiredField)) {
                    $errorMessage = 'Step3. missing required field: ' . $field;
                    //break;
                }
            }
            $mappedRow['academicYear'] = $academicYearID;
            $mappedRow['academicYearName'] = $academicYear;
            $mappedRow['payment_method'] = $mappedRow['payment_method']? $mappedRow['payment_method'] : 'SEPA';

            $mappedRow['__RowCanBeImported__'] = true;
            $mappedRow['__RowStatusInImportProcess__'] = 'Step3. In process';
            // record the row number in excel
            $mappedRow['__RowNumberInExcelFile__'] = $rowIndex + 2;

            if ($errorMessage) {
                $mappedRow['__RowCanBeImported__'] = false;
                $mappedRow['__RowStatusInImportProcess__'] = $errorMessage;

            } else {
                try {
                    $SepaGateway = $container->get(SepaGateway::class);

                    // covert date to mysql format
                    $mappedRow['booking_date'] = DateTime::createFromFormat($dateFormat, $mappedRow['booking_date'])->format('Y-m-d');

                    // convert to mysql decimal format
                    $mappedRow['amount'] = convertToFloat($mappedRow['amount'], $decimalSeparator);

                    // search of already exist

                    if ($SepaGateway->paymentRecordExist($mappedRow)) {
                        $mappedRow['__RowStatusInImportProcess__'] = 'Step3. Already exist. Will not be imported.';
                        $mappedRow['__RowCanBeImported__'] = false;

                    } else {
                        $mappedRow['__RowStatusInImportProcess__'] = 'Step3. ValidData';
                        $mappedRow['__RowCanBeImported__'] = true;
                        // try to match the entry with a family
                        $result = $SepaGateway->getSEPAForPaymentEntry($mappedRow);
                        if ($result && count($result)==1) {
                            $mappedRow['gibbonSEPAID'] = $result[0]['gibbonSEPAID'];
                        }

                        $validDataCount++;
                    }

                } catch (Exception $e) {
                    $mappedRow['__RowStatusInImportProcess__'] = 'Step3. Error: ' . $e->getMessage();
                    $mappedRow['__RowCanBeImported__'] = false;
                }
            }
            if ($mappedRow['__RowStatusInImportProcess__'] == 'Step3. ValidData') {
                $validData[] = $mappedRow;
            } else {
                $otherData[] = $mappedRow;
            }
        }

        $processedData = array_merge($validData, $otherData);

        // Display validation results
        if (!empty($validDataCount)) {
            echo "<div class='success'>";
            echo __($validDataCount . ' Rows are ready for import.');
            echo "</div>";
        }
        $table = DataTable::create('PaymentEntries');
        $table->addColumn('__RowNumberInExcelFile__', __('Row No.'));
        $table->addColumn('booking_date', __('Date'));
        $table->addColumn('payer', __('SEPA Owner Name'));
        $table->addColumn('amount', __('Amount'));
        $table->addColumn('academicYearName', __('Academic Year'));
        $table->addColumn('__RowStatusInImportProcess__', __('Process Message'));

        $table->modifyRows(function ($values, $row) {
            if (!$values['__RowCanBeImported__'])
                $row->addClass('warning');
            else
                $row->addClass('success');
            return $row;
        });

        echo $table->render($processedData);


        // Store valid data for final step
        $_SESSION[$guid]['sepaProcessedData'] = $processedData;

        $form = Form::create('importStep3', $StepLink . '4');
        $form->addHiddenValue('address', $_SESSION[$guid]['address']);

        $row = $form->addRow();
        $row->addFooter();
        $row->addSubmit(__('Proceed to Import'));

        echo $form->getOutput();
    }
    // STEP 4: LIVE RUN
    else if ($step == 4) {
        $data = $_SESSION[$guid]['sepaProcessedData'] ?? null;

        if (empty($data)) {
            $page->addError(__('Invalid data'));
            return;
        }

        $SepaGateway = $container->get(SepaGateway::class);
        $count = 0;
        $unprocessedRows = [];
        foreach ($data as $index => $row) {
            if ($row['__RowCanBeImported__']) {
                try {
                    $row = $container->get(Validator::class)->sanitize($row);
                    $result = $SepaGateway->insertPayment($row, $_SESSION[$guid]["username"]);
                    if (!$result) {
                        $data[$index]['__RowCanBeImported__'] = false;
                        $data[$index]['__RowStatusInImportProcess__'] = 'Step4. Error: Can not insert this record';
                        $unprocessedRows[] = $data[$index];

                    } else {
                        $data[$index]['__RowStatusInImportProcess__'] = 'Step4. Success';
                        $count++;
                    }
                } catch (Exception $e) {
                    $data[$index]['__RowCanBeImported__'] = false;
                    $data[$index]['__RowStatusInImportProcess__'] = 'Step4. Error: ' . $e->getMessage();
                    $unprocessedRows[] = $data[$index];
                }
            }

        }

        echo "<div class=success> (". $count. __(") Rows inserted sucessfully.")."</div>";
        echo "Download the the excel export for more details.";

        $criteria = $SepaGateway->newQueryCriteria(true);
        $table = DataTable::create('PaymentEntries');
        $table->addHeaderAction('export', __('Export'))
            ->setIcon('download')
            ->setURL('/modules/Sepa/import_sepa_payment_export.php')
            ->directLink()
            ->displayLabel();

        $table->addColumn('__RowNumberInExcelFile__', __('Row No.'));
        $table->addColumn('booking_date', __('Date'));
        $table->addColumn('payer', __('SEPA Owner Name'));
        $table->addColumn('amount', __('Amount'));
        $table->addColumn('academicYearName', __('Academic Year'));
        $table->addColumn('__RowStatusInImportProcess__', __('Process Message'));

        $table->modifyRows(function ($values, $row) {
            if (!$values['__RowCanBeImported__'])
                $row->addClass('warning');
            else
                $row->addClass('success');
            return $row;
        });

        echo $table->render($unprocessedRows);

        $_SESSION[$guid]['sepaProcessedData'] = $data;
        

        $form = Form::create('importStep4', $StepLink . '5');
        $form->addHiddenValue('address', $_SESSION[$guid]['address']);
        $row = $form->addRow();
        $row->addFooter();
        $row->addSubmit(__('Final Step'));

        echo $form->getOutput();



    } else if ($step == 5) {
        // Clear session data
        if (isset($_SESSION[$guid]['sepaProcessedData'])) {
            unset($_SESSION[$guid]['sepaImportData']);
            unset($_SESSION[$guid]['sepaProcessedData']);
            unset($_SESSION[$guid]['sepaImportDataHeaders']);
            echo __('The process is finished successfully!');
        } else {
            echo __(__('No Data to process'));
        }
    }
}
