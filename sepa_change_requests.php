<?php
/*
Gibbon, Flexible & Open School System
Copyright (C) 2010, Ross Parker

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

use Gibbon\Forms\Form;
use Gibbon\Tables\DataTable;
use Gibbon\Data\Validator;
use Gibbon\Services\Format;
use Gibbon\Module\Sepa\Domain\SepaChangeRequestGateway;

// Module includes
require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_change_requests.php')) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$page->breadcrumbs->add(__('Approve SEPA Changes'));

$_GET = $container->get(Validator::class)->sanitize($_GET);
$gateway = $container->get(SepaChangeRequestGateway::class);

$status = in_array($_GET['status'] ?? '', ['Pending', 'Approved', 'Rejected', 'All'], true) ? $_GET['status'] : 'Pending';

$form = Form::create('statusFilter', $session->get('absoluteURL') . '/index.php', 'get');
$form->setClass('noIntBorder fullWidth');
$form->addHiddenValue('q', '/modules/Sepa/sepa_change_requests.php');
$row = $form->addRow();
$row->addLabel('status', __('Status'));
$row->addSelect('status')->fromArray(['Pending' => __('Waiting for approval'), 'Approved' => __('Approved'), 'Rejected' => __('Rejected'), 'All' => __('All')])->selected($status);
$form->addRow()->addSubmit(__('Go'));
echo $form->getOutput();

$requests = $gateway->selectRequests($status === 'All' ? '' : $status);
foreach ($requests as &$request) {
    $flags = $request['status'] === 'Pending' ? $gateway->getRequestFlags($request) : [];
    $request['errors'] = count(array_filter($flags, function ($flag) {
        return $flag['severity'] === 'Error';
    }));
    $request['warnings'] = count($flags) - $request['errors'];
}
unset($request);

echo '<h2>' . __('SEPA changes entered by families') . '</h2>';

$table = DataTable::create('sepaChangeRequests');
if (isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_change_log.php')) {
    $table->addHeaderAction('log', __('Log'))
        ->setURL('/modules/Sepa/sepa_change_log.php')
        ->displayLabel();
}
$table->addColumn('familyName', __('Family'));
$table->addColumn('payer', __('Account holder'));
$table->addColumn('submittedBy', __('Entered by'));
$table->addColumn('timestampSubmitted', __('Date'))->format(function ($row) {
    return Format::dateTime($row['timestampUpdated'] ?: $row['timestampSubmitted']);
});
$table->addColumn('status', __('Status'))->format(function ($row) {
    return ['Pending' => __('Waiting for approval'), 'Approved' => __('Approved'), 'Rejected' => __('Rejected')][$row['status']] ?? $row['status'];
});
$table->addColumn('check', __('Check'))->format(function ($row) {
    if ($row['status'] !== 'Pending') {
        return '';
    }
    $text = [];
    if ($row['errors']) {
        $text[] = '<span style="color: red; font-weight: bold;">' . sprintf(__('%d error(s)'), $row['errors']) . '</span>';
    }
    if ($row['warnings']) {
        $text[] = '<span style="color: #c60;">' . sprintf(__('%d warning(s)'), $row['warnings']) . '</span>';
    }
    return $text ? implode(', ', $text) : __('No problems');
});
$table->addActionColumn()
    ->addParam('gibbonSEPAChangeRequestID')
    ->format(function ($row, $actions) {
        $actions->addAction('view', $row['status'] === 'Pending' ? __('Review') : __('View'))
            ->setURL('/modules/Sepa/sepa_change_review.php');
    });

echo $table->render($requests);

