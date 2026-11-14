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

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_change_log.php')) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$page->breadcrumbs->add(__('SEPA Change Log'));

$_GET = $container->get(Validator::class)->sanitize($_GET);
$gateway = $container->get(SepaChangeRequestGateway::class);

if (!empty($_SESSION[$guid]['sepaLogNotice'])) {
    echo "<div class='success'>" . htmlspecialchars($_SESSION[$guid]['sepaLogNotice'], ENT_QUOTES, 'UTF-8') . '</div>';
    unset($_SESSION[$guid]['sepaLogNotice']);
}

// Filters
$search = trim($_GET['search'] ?? '');
$event = in_array($_GET['event'] ?? '', ['Submitted', 'Updated', 'Discarded', 'Approved', 'Rejected'], true) ? $_GET['event'] : '';
$sepaID = (int) ($_GET['sepa'] ?? 0);
$from = !empty($_GET['from']) ? Format::dateConvert($_GET['from']) : '';
$to = !empty($_GET['to']) ? Format::dateConvert($_GET['to']) : '';
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $from) ? $from : '';
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $to) ? $to : '';

$form = Form::create('logFilter', $session->get('absoluteURL') . '/index.php', 'get');
$form->setClass('noIntBorder fullWidth');
$form->addHiddenValue('q', '/modules/Sepa/sepa_change_log.php');
if ($sepaID) {
    $form->addHiddenValue('sepa', $sepaID);
}
$row = $form->addRow();
$row->addLabel('search', __('Search For'))->description(__('Family, payer, person'));
$row->addTextField('search')->setValue($search);
$row = $form->addRow();
$row->addLabel('event', __('What'));
$row->addSelect('event')->fromArray(['Submitted' => __('Entered'), 'Updated' => __('Entries changed'), 'Discarded' => __('Discarded'), 'Approved' => __('Approved'), 'Rejected' => __('Rejected')])->selected($event)->placeholder(__('All'));
$row = $form->addRow();
$row->addLabel('from', __('From'));
$row->addDate('from')->setValue($from ? Format::date($from) : '');
$row = $form->addRow();
$row->addLabel('to', __('To'));
$row->addDate('to')->setValue($to ? Format::date($to) : '');
$row = $form->addRow();
$row->addSubmit(__('Filter'));
echo $form->getOutput();

$criteria = $gateway->newQueryCriteria(true)
    ->sortBy(['timestamp'])
    ->fromPOST();
foreach (['search' => $search, 'event' => $event, 'from' => $from, 'to' => $to, 'sepa' => $sepaID ?: ''] as $name => $value) {
    if ($value !== '') {
        $criteria->filterBy($name, $value);
    }
}

echo '<h2>' . __('SEPA Change Log') . '</h2>';
echo '<p>' . __('Every entry, change, discard, approval and rejection of SEPA data by families: what, when and by whom. IBANs are stored encrypted. The family sees its own history on its SEPA page.') . '</p>';
if ($sepaID) {
    echo '<p><a href="' . $session->get('absoluteURL') . '/index.php?q=/modules/Sepa/sepa_change_log.php">' . __('Show the log of all families') . '</a></p>';
}

$absoluteURL = $session->get('absoluteURL');
$table = DataTable::createPaginated('sepaChangeLog', $criteria);
$table->addColumn('timestamp', __('When'))->sortable(['gibbonSEPAChangeLog.timestamp'])->format(function ($row) {
    return Format::dateTime($row['timestamp']);
});
$table->addColumn('personName', __('Who'));
$table->addColumn('familyName', __('Family'))->notSortable()->format(function ($row) use ($absoluteURL) {
    $name = htmlspecialchars((string) ($row['familyName'] ?? __('(family deleted)')), ENT_QUOTES, 'UTF-8');
    // the family's SEPA page, which shows the history as well
    return '<a href="' . $absoluteURL . '/index.php?q=/modules/Sepa/sepa_family_view.php&search=' . urlencode((string) $row['payer']) . '">' . $name . '</a>';
});
$table->addColumn('event', __('What'))->format(function ($row) {
    return sepaLogEventLabel($row['event']);
});
$table->addColumn('changes', __('Changes (old -> new)'))->notSortable()->format(function ($row) {
    return sepaFormatLogChanges($row['changes']);
});
$table->addActionColumn()
    ->addParam('gibbonSEPAChangeRequestID')
    ->format(function ($row, $actions) {
        if (!empty($row['gibbonSEPAChangeRequestID'])) {
            $actions->addAction('view', __('View request'))->setURL('/modules/Sepa/sepa_change_review.php');
        }
    });
echo $table->render($gateway->queryLog($criteria));

// Data retention: only data older than two years can be deleted
$expired = $gateway->countExpired();
echo '<h2>' . __('Data retention') . '</h2>';
if ($expired['log'] + $expired['requests'] === 0) {
    echo '<p>' . __('Nothing is older than two years, so nothing can be deleted yet.') . '</p>';
} else {
    $purge = Form::create('logPurge', $session->get('absoluteURL') . '/modules/Sepa/sepa_change_logProcess.php');
    $purge->addHiddenValue('address', $session->get('address'));
    $row = $purge->addRow();
    $row->addLabel('confirm', __('Delete data older than two years'))
        ->description(sprintf(__('%1$d log line(s) and %2$d decided request(s) are older than two years (before %3$s). Requests waiting for approval and newer data are never deleted.'), $expired['log'], $expired['requests'], Format::dateTime(SepaChangeRequestGateway::retentionCutoff())));
    $row->addCheckbox('confirm')->setValue('1')->description(__('Yes, delete this data. This can not be undone.'));
    $row = $purge->addRow();
    $row->addSubmit(__('Delete'));
    echo $purge->getOutput();
}
