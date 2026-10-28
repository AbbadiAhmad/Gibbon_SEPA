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
use Gibbon\Services\Format;
use Gibbon\Tables\DataTable;
use Gibbon\Module\Sepa\Domain\SepaGateway;
use Gibbon\Data\Validator;

// Module includes
require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Sepa/sepa_family_due.php')) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    $page->breadcrumbs->add(__('Family Totals'), 'sepa_family_totals.php');
    $page->breadcrumbs->add(__('Family Due in Period'));

    $_GET = $container->get(Validator::class)->sanitize($_GET);

    $SepaGateway = $container->get(SepaGateway::class);

    $schoolYearID = !empty($_GET['schoolYearID']) ? $_GET['schoolYearID'] : $_SESSION[$guid]['gibbonSchoolYearID'];
    $search = $_GET['search'] ?? '';

    // Period: defaults to the current month. Whole months are used (any day inside a month selects it).
    $periodFrom = !empty($_GET['periodFrom']) ? Format::dateConvert($_GET['periodFrom']) : date('Y-m-01');
    $periodTo = !empty($_GET['periodTo']) ? Format::dateConvert($_GET['periodTo']) : date('Y-m-t');
    if (strtotime($periodTo) < strtotime($periodFrom)) {
        $periodTo = date('Y-m-t', strtotime($periodFrom));
    }
    $periodFrom = date('Y-m-01', strtotime($periodFrom));
    $periodTo = date('Y-m-t', strtotime($periodTo));

    $form = Form::create('dueFilter', $session->get('absoluteURL') . '/index.php', 'get');
    $form->setClass('noIntBorder fullWidth');
    $form->addHiddenValue('q', '/modules/Sepa/sepa_family_due.php');

    $row = $form->addRow();
    $row->addLabel('schoolYearID', __('Academic Year'));
    $row->addSelect('schoolYearID')->fromArray($SepaGateway->getAllSchoolYears())->selected($schoolYearID);

    $row = $form->addRow();
    $row->addLabel('periodFrom', __('Period from'))->description(__('The whole month of this date is included.'));
    $row->addDate('periodFrom')->setValue(Format::date($periodFrom))->required();

    $row = $form->addRow();
    $row->addLabel('periodTo', __('Period to'))->description(__('The whole month of this date is included.'));
    $row->addDate('periodTo')->setValue(Format::date($periodTo))->required();

    $row = $form->addRow();
    $row->addLabel('search', __('Search For'))->description(__('Family Name, Payer'));
    $row->addTextField('search')->setValue($search);

    $row = $form->addRow();
    $row->addSearchSubmit($session, __('Clear Filters'));

    echo $form->getOutput();

    echo '<h2>' . __('Family Balance and Due in Period') . '</h2>';
    echo '<p>' . sprintf(
        __('Due in period: fees of the billable months from %1$s to %2$s. Balance: payments and adjustments minus all fees of the academic year (negative means the family owes money).'),
        Format::date($periodFrom),
        Format::date($periodTo)
    ) . '</p>';

    $criteria = $SepaGateway->newQueryCriteria(true)
        ->sortBy(['balance'])
        ->fromPOST();

    $criteria->addFilterRules([
        'search' => function ($query, $search) {
            if (!empty($search)) {
                return $query->where('(gibbonFamily.name LIKE :search OR gibbonSEPA.payer LIKE :search)')
                    ->bindValue('search', '%' . $search . '%');
            }
            return $query;
        }
    ]);
    if (!empty($search)) {
        $criteria->filterBy('search', $search);
    }

    $familyTotals = $SepaGateway->getFamilyTotals($schoolYearID, $criteria, '', $periodFrom, $periodTo);
    $cadences = SepaGateway::getWithdrawCadenceOptions();

    $table = DataTable::createPaginated('familyDue', $criteria);

    $table->addColumn('familyName', __('Family Name'));
    $table->addColumn('payer', __('Payer'));
    $table->addColumn('withdrawCadence', __('Withdrawal'))->notSortable()->format(function ($row) use ($cadences) {
        if (empty($row['gibbonSEPAID'])) {
            return '<span style="color: red;">' . __('No SEPA') . '</span>';
        }
        $text = $cadences[$row['withdrawCadence']] ?? '';
        return $row['withdrawDay'] ? $text . ' (' . __('day') . ' ' . (int) $row['withdrawDay'] . ')' : $text;
    });
    $table->addColumn('totalDept', __('Total Dept'))->format(function ($row) {
        return number_format($row['totalDept'], 2);
    });
    $table->addColumn('payments', __('Payments'))->format(function ($row) {
        return number_format($row['payments'], 2);
    });
    $table->addColumn('paymentsAdjustment', __('Payments Adjustment'))->format(function ($row) {
        return number_format($row['paymentsAdjustment'], 2);
    });
    $table->addColumn('balance', __('Balance'))->format(function ($row) {
        return number_format($row['balance'], 2);
    });
    $table->addColumn('dueInPeriod', __('Due in Period'))->format(function ($row) {
        return '<strong>' . number_format($row['dueInPeriod'], 2) . '</strong>';
    });

    $table->addActionColumn()
        ->addParam('schoolYearID', $schoolYearID)
        ->format(function ($row, $actions) {
            $actions->addAction('view', __('View Details'))
                ->setURL('/modules/Sepa/sepa_family_details.php')
                ->addParam('gibbonFamilyID', $row['gibbonFamilyID']);
        });

    echo $table->render($familyTotals);
}
