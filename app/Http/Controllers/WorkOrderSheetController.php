<?php

namespace App\Http\Controllers;

use App\Models\CompanySetting;
use App\Models\WorkOrder;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** Spec 2026-10-17 maintenance §4 step 2: the printable work order sheet. No amounts (plan ruling 12). */
final class WorkOrderSheetController
{
    public function __invoke(Request $request, WorkOrder $workOrder): View
    {
        abort_unless($request->user('web')?->can('view', $workOrder) === true, 403);
        $workOrder->load(['vendor', 'maintenanceRequest.building', 'maintenanceRequest.unit', 'maintenanceRequest.customer']);

        return view('maintenance.work-order-sheet', ['workOrder' => $workOrder, 'lines' => $workOrder->sheetLines(), 'company' => CompanySetting::current()]);
    }
}
