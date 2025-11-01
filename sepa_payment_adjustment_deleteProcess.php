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

if (isActionAccessible($guid, $connection2, "/modules/Sepa/sepa_discount_delete.php") == false) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    $_GET = $container->get(Validator::class)->sanitize($_GET);
    $_POST = $container->get(Validator::class)->sanitize($_POST);
    $family_details = $_POST['family_details'] ?? '';

    $gibbonSEPADiscountID = $_POST['gibbonSEPADiscountID'] ?? '';

    if (empty($gibbonSEPADiscountID)) {
        $page->addError(__('You have not specified one or more required parameters.'));
        return;
    }

    $SepaDiscountGateway = $container->get(SepaPaymentAdjustmentGateway::class);

    $deleted = $SepaDiscountGateway->deleteDiscount($gibbonSEPADiscountID);

    if ($deleted) {
        if (!empty($family_details)) {
            header("Location: {$session->get('absoluteURL')}/index.php?q=/modules/Sepa/sepa_family_details.php&gibbonFamilyID={$family_details}&return=success0");
        } else {
            header("Location: {$session->get('absoluteURL')}/index.php?q=/modules/Sepa/sepa_discount_manage.php&return=success1");
        }

    } else {
        header("Location: {$session->get('absoluteURL')}/index.php?q=/modules/Sepa/sepa_discount_manage.php&return=error2");
    }


}
