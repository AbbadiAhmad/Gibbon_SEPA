<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community
Copyright © 2010, Gibbon Foundation
*/

use Gibbon\Module\Sepa\Domain\SepaPaymentAdjustmentGateway;
use Gibbon\Data\Validator;


require_once __DIR__ . '/moduleFunctions.php';
require_once __DIR__ . '/../../gibbon.php';

if (isActionAccessible($guid, $connection2, "/modules/Sepa/sepa_discount_add.php") == false) {
    // Access denied
    header("Location: {$session->get('absoluteURL')}/index.php?q=/modules/Sepa/sepa_discount_add.php&return=error0");
} else {
    $_GET = $container->get(Validator::class)->sanitize($_GET);
    $_POST = $container->get(Validator::class)->sanitize($_POST);
    $SepaDiscountGateway = $container->get(SepaPaymentAdjustmentGateway::class);
    $family_details = $_POST['family_details'] ?? '';

    $data = [
        'gibbonSEPAID' => $_POST['gibbonSEPAID'] ?? '',
        'discountAmount' => $_POST['discountAmount'] ?? '',
        'description' => $_POST['description'] ?? '',
        'note' => $_POST['note'] ?? '',
        'gibbonPersonID' => $_POST['gibbonPersonID'] ?? '',
    ];

    if (empty($data['gibbonSEPAID'])) {
        header("Location: {$session->get('absoluteURL')}/index.php?q=/modules/Sepa/sepa_discount_add.php&return=error2");
        return;
    } elseif (empty($data['discountAmount']) || empty($data['description'])) {
        header("Location: {$session->get('absoluteURL')}/index.php?q=/modules/Sepa/sepa_discount_add.php&return=error2&gibbonSEPAID={$data['gibbonSEPAID']}");
        return;
    }

    $inserted = $SepaDiscountGateway->insertDiscount($data);

    if ($inserted) {
        if (!empty($family_details)) {
            header("Location: {$session->get('absoluteURL')}/index.php?q=/modules/Sepa/sepa_family_details.php&gibbonFamilyID={$family_details}&return=success0");
        } else {
            header("Location: {$session->get('absoluteURL')}/index.php?q=/modules/Sepa/sepa_discount_manage.php&return=success0");
        }
    } else {
        header("Location: {$session->get('absoluteURL')}/index.php?q=/modules/Sepa/sepa_discount_add.php&return=error2");
    }
}
