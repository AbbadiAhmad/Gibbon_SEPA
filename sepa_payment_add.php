<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community
Copyright © 2010, Gibbon Foundation
*/

use Gibbon\Forms\Form;
use Gibbon\Domain\QueryCriteria;
use Gibbon\Module\Sepa\Domain\SepaGateway;
use Gibbon\Data\Validator;


require_once __DIR__ . '/moduleFunctions.php';
require_once __DIR__ . '/../../gibbon.php';

if (isActionAccessible($guid, $connection2, "/modules/Sepa/sepa_payment_add.php") == false) {
    // Access denied
    $page->addError(__m('You do not have access to this action.'));
} else {
    $_GET = $container->get(Validator::class)->sanitize($_GET);
    $_POST = $container->get(Validator::class)->sanitize($_POST);

    $gibbonSEPAID = $_GET['gibbonSEPAID'] ?? null;
    $lockFamily = $_GET['lockFamily'] ?? false;
    $family_details = $_GET['family_details'] ?? '';


    $page->breadcrumbs
        ->add(__m('Manage SEPA Payment Entries'), 'sepa_payment_manage.php')
        ->add(__m('Add Payment Entry'));

    $SepaGateway = $container->get(SepaGateway::class);

    $criteria = $SepaGateway->newQueryCriteria(false)
        ->sortBy(['payer'])
    ;
    $sepaList = $SepaGateway->getSEPAList($criteria, null);
    $schoolYears = $SepaGateway->getAllSchoolYears();


    $form = Form::create('paymentAdd', $session->get('absoluteURL') . '/modules/Sepa/sepa_payment_addProcess.php');

    $form->addHiddenValue('address', $_SESSION[$guid]['address']);

    $row = $form->addRow();
    $row->addLabel('booking_date', __m('Booking Date'));
    $row->addDate('booking_date')->required()->setValue(date('Y-m-d'));

    $row = $form->addRow();
    $row->addLabel('academicYear', __m('Academic Year'));
    $row->addSelect('academicYear')
        ->fromArray($schoolYears)
        ->required()
        ->selected($_SESSION[$guid]["gibbonSchoolYearID"])
        ->placeholder(__m('Please select...'));

    $form->addHiddenValue('family_details', $family_details);

    $row = $form->addRow();
    $row->addLabel('gibbonSEPAID', __m('SEPA Account'));
    $sepaOptions = array_column($sepaList->toArray(), 'payer', 'gibbonSEPAID');
    $select = $row->addSelect('gibbonSEPAID')->fromArray($sepaOptions)->placeholder('')->required();
    if ($gibbonSEPAID && array_key_exists($gibbonSEPAID, $sepaOptions)) {
        $select->selected($gibbonSEPAID);
        if ($lockFamily) {
            $select->disabled();
            $form->addHiddenValue('gibbonSEPAID', $gibbonSEPAID);
        }
    }

    $row = $form->addRow();
    $row->addLabel('payment_method', __m('Payment Method'));
    $row->addSelect('payment_method')->fromArray(['card' => 'Card', 'cash' => 'Cash', 'bank_transfer' => 'Bank Transfer', 'SEPA' => 'SEPA',])->placeholder('')->required();

    $row = $form->addRow();
    $row->addLabel('amount', __m('Amount'));
    $row->addNumber('amount')->required()->decimalPlaces(2);

    // A negative amount (e.g. a rejected/refunded SEPA order) increases what the payer owes.
    // The admin has to explicitly confirm it.
    $row = $form->addRow('negativeConfirmRow')->addClass('hidden');
    $row->addLabel('confirmNegative', __m('Confirm negative amount'))
        ->description(__m('A negative payment increases the amount owed by the payer instead of reducing it.'));
    $row->addCheckbox('confirmNegative')->description(__m('I understand this negative amount will increase the amount owed.'))->setValue('Y');
    $form->addRow()->addContent("<script>
        (function () {
            var amount = document.getElementById('amount');
            var row = document.getElementById('negativeConfirmRow');
            var box = document.getElementById('confirmNegative');
            function toggle() {
                var neg = parseFloat((amount.value || '').replace(',', '.')) < 0;
                row.classList.toggle('hidden', !neg);
                row.style.display = neg ? '' : 'none';
                if (!neg && box) box.checked = false;
            }
            amount.addEventListener('input', toggle);
            toggle();
        })();
    </script>");

    $row = $form->addRow();
    $row->addLabel('payer', __m('Payer'));
    $row->addTextField('payer')->required()->maxLength(100);

    $row = $form->addRow();
    $row->addLabel('IBAN', __m('IBAN'));
    $row->addTextField('IBAN')->maxLength(50);

    $row = $form->addRow();
    $row->addLabel('transaction_reference', __m('Transaction Reference'));
    $row->addTextField('transaction_reference')->maxLength(255);

    $row = $form->addRow();
    $row->addLabel('transaction_message', __m('Transaction Message from the bank'));
    $row->addTextField('transaction_message')->maxLength(255);

    $row = $form->addRow();
    $row->addLabel('note', __m('Note: additional information'));
    $row->addTextArea('note');

    $row = $form->addRow();
    $row->addFooter();
    $row->addSubmit();

    echo $form->getOutput();
}
