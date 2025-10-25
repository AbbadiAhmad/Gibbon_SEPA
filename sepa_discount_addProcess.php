<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community
Copyright © 2010, Gibbon Foundation
*/

use Gibbon\Module\Sepa\Domain\SepaDiscountGateway;
use Gibbon\Data\Validator;

$_GET = $container->get(Validator::class)->sanitize($_GET);
$_POST = $container->get(Validator::class)->sanitize($_POST);

require_once __DIR__ . '/moduleFunctions.php';
require_once __DIR__ . '/../../gibbon.php';

if (isActionAccessible($guid, $connection2, "/modules/Sepa/sepa_discount_add.php") == false) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    $SepaDiscountGateway = $container->get(SepaDiscountGateway::class);

    $data = [
        'gibbonSEPAID' => $_POST['gibbonSEPAID'] ?? '',
        'discountAmount' => $_POST['discountAmount'] ?? '',
        'description' => $_POST['description'] ?? '',
        'note' => $_POST['note'] ?? '',
        'gibbonPersonID' => $_POST['gibbonPersonID'] ?? '',
    ];

    if (empty($data['gibbonSEPAID']) || empty($data['discountAmount']) || empty($data['description'])) {
        $page->addError(__('You have not specified one or more required parameters.'));
        header("Location: {$session->get('absoluteURL')}/index.php?q=/modules/Sepa/sepa_discount_add.php");
        exit;
    }

    $inserted = $SepaDiscountGateway->insertDiscount($data);

    if ($inserted) {
        header("Location: {$session->get('absoluteURL')}/index.php?q=/modules/Sepa/sepa_discount_manage.php&return=success0");
    } else {
        header("Location: {$session->get('absoluteURL')}/index.php?q=/modules/Sepa/sepa_discount_manage.php&return=error1");
    }
}
