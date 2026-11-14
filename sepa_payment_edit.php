<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community
Copyright © 2010, Gibbon Foundation
*/

use Gibbon\Forms\Form;
use Gibbon\Domain\QueryCriteria;
use Gibbon\Module\Sepa\Domain\SepaGateway;
use Gibbon\Module\Sepa\Security\IbanCipher;
use Gibbon\Data\Validator;

require_once __DIR__ . '/moduleFunctions.php';
require_once __DIR__ . '/../../gibbon.php';

if (isActionAccessible($guid, $connection2, "/modules/Sepa/sepa_payment_edit.php") == false) {
    // Access denied
    $page->addError(__m('You do not have access to this action.'));
} else {
    $_GET = $container->get(Validator::class)->sanitize($_GET);
    $_POST = $container->get(Validator::class)->sanitize($_POST);


    $gibbonSEPAPaymentRecordID = $_GET['gibbonSEPAPaymentRecordID'] ?? '';
    $family_details = $_GET['family_details'] ?? '';
    $LockFamily = $_GET['LockFamily'] ?? false;


    if (empty($gibbonSEPAPaymentRecordID)) {
        $page->addError(__m('Invalid payment entry.'));
        return;
    }

    $page->breadcrumbs
        ->add(__m('Manage SEPA Payment Entries'), 'sepa_payment_manage.php')
        ->add(__m('Edit Payment Entry'));

    $SepaGateway = $container->get(SepaGateway::class);
    $payment = $SepaGateway->getPaymentByID($gibbonSEPAPaymentRecordID);
    $criteria = $SepaGateway->newQueryCriteria(false)->sortBy(['payer']);

    $sepaList = $SepaGateway->getSEPAList($criteria, null);
    $schoolYears = $SepaGateway->getAllSchoolYears();

    if (!$payment) {
        $page->addError(__m('Payment entry not found.'));
        return;
    }

    // An unlinked payment (usually imported from a bank statement) is only assigned to a family:
    // its values are shown read-only and cannot be changed here.
    if (empty($payment['gibbonSEPAID'])) {
        $form = Form::create('paymentLink', $session->get('absoluteURL') . '/modules/Sepa/sepa_payment_editProcess.php');
        $form->addHiddenValue('address', $_SESSION[$guid]['address']);
        $form->addHiddenValue('gibbonSEPAPaymentRecordID', $gibbonSEPAPaymentRecordID);
        $form->addHiddenValue('family_details', $family_details);
        $form->addHiddenValue('linkOnly', 'Y');

        $readOnly = [
            __m('Booking Date') => $payment['booking_date'],
            __m('Academic Year') => $schoolYears[$payment['academicYear']] ?? $payment['academicYear'],
            __m('Payment Method') => $payment['payment_method'],
            __m('Amount') => $payment['amount'],
            __m('Payer') => $payment['payer'],
            __m('IBAN') => IbanCipher::format(IbanCipher::decrypt($payment['IBAN'])),
            __m('Transaction Reference') => $payment['transaction_reference'],
            __m('Transaction Message') => $payment['transaction_message'],
            __m('Note') => $payment['note'],
        ];
        foreach ($readOnly as $label => $value) {
            $row = $form->addRow();
            $row->addLabel('ro_' . md5($label), $label);
            $row->addContent(htmlspecialchars((string) $value))->addClass('flex-1 text-right');
        }

        $row = $form->addRow();
        $row->addLabel('gibbonSEPAID', __m('SEPA Account'));
        $sepaOptions = array_column($sepaList->toArray(), 'payer', 'gibbonSEPAID');
        $row->addSelect('gibbonSEPAID')->fromArray($sepaOptions)->placeholder('')->required();

        $row = $form->addRow();
        $row->addFooter();
        $row->addSubmit(__m('Assign to Family'));

        echo $form->getOutput();
        return;
    }

    $form = Form::create('paymentEdit', $session->get('absoluteURL') . '/modules/Sepa/sepa_payment_editProcess.php');

    $form->addHiddenValue('address', $_SESSION[$guid]['address']);
    $form->addHiddenValue('gibbonSEPAPaymentRecordID', $gibbonSEPAPaymentRecordID);
    $form->addHiddenValue('family_details', $family_details);


    $row = $form->addRow();
    $row->addLabel('booking_date', __m('Booking Date'));
    $row->addDate('booking_date')->required()->setValue($payment['booking_date']);

    $row = $form->addRow();
    $row->addLabel('academicYear', __m('Academic Year'));
    $row->addSelect('academicYear')
        ->fromArray($schoolYears)
        ->required()
        ->selected($payment['academicYear'])
        ->placeholder(__m('Please select...'));

    $row = $form->addRow();
    $row->addLabel('gibbonSEPAID', __m('SEPA Account'));

    $sepaOptions = array_column($sepaList->toArray(), 'payer', 'gibbonSEPAID');
    $sepaOptions = array_combine(
        array_map(fn($k) => (string) (int) $k, array_keys($sepaOptions)),
        $sepaOptions
    );

    $select = $row->addSelect('gibbonSEPAID')->fromArray($sepaOptions)->placeholder('');
    if ($payment['gibbonSEPAID'] && array_key_exists($payment['gibbonSEPAID'], $sepaOptions)) {
        $select->selected($payment['gibbonSEPAID']);
        if ($LockFamily) {
            $select->disabled();
            $form->addHiddenValue('gibbonSEPAID', $payment['gibbonSEPAID']);
        }
    }

    $row = $form->addRow();
    $row->addLabel('payment_method', __m('Payment Method'));
    $select = $row->addSelect('payment_method')->fromArray(['card' => 'Card', 'cash' => 'Cash', 'bank_transfer' => 'Bank Transfer', 'SEPA' => 'SEPA',])->required();
    $select->selected($payment['payment_method']);

    $row = $form->addRow();
    $row->addLabel('amount', __m('Amount'));
    $row->addNumber('amount')->required()->decimalPlaces(2)->setValue($payment['amount']);

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
    $row->addTextField('payer')->required()->maxLength(100)->setValue($payment['payer']);

    $row = $form->addRow();
    $row->addLabel('IBAN', __m('IBAN'));
    $row->addTextField('IBAN')->maxLength(50)->setValue(IbanCipher::format(IbanCipher::decrypt($payment['IBAN'])));

    $row = $form->addRow();
    $row->addLabel('transaction_reference', __m('Transaction Reference'));
    $row->addTextField('transaction_reference')->maxLength(255)->setValue($payment['transaction_reference']);

    $row = $form->addRow();
    $row->addLabel('transaction_message', __m('Transaction Message'));
    $row->addTextField('transaction_message')->maxLength(255)->setValue($payment['transaction_message']);

    $row = $form->addRow();
    $row->addLabel('note', __m('Note'));
    $row->addTextArea('note')->setValue($payment['note']);

    $row = $form->addRow();
    $row->addFooter();
    $row->addSubmit();

    echo $form->getOutput();
}
