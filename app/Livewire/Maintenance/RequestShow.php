<?php

namespace App\Livewire\Maintenance;

use App\Actions\Maintenance\AddMaintenanceNote;
use App\Actions\Maintenance\AddWorkOrder;
use App\Actions\Maintenance\CancelMaintenanceRequest;
use App\Actions\Maintenance\ChangeWorkOrderStatus;
use App\Actions\Maintenance\ResolveMaintenanceRequest;
use App\Enums\ExpenseStatus;
use App\Enums\WorkOrderStatus;
use App\Livewire\Concerns\WithActor;
use App\Models\Expense;
use App\Models\MaintenanceRequest;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Support\Fils;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Spec 2026-10-17 maintenance §5: a request with its photos, work orders, timeline and linked expenses. Every action
 * re-checks permission and building scope through its Action; ids from the browser are looked up inside this request.
 */
class RequestShow extends Component
{
    use WithActor;

    private const array NEW_WORK_ORDER = ['vendor_id' => '', 'scheduled_on' => '', 'instructions' => '', 'estimate' => ''];

    #[Locked]
    public int $requestId;

    // Untyped on purpose: a tampered payload reaches the Actions' validation instead of a TypeError (a 500).
    public mixed $workOrder = self::NEW_WORK_ORDER;

    public mixed $note = '';

    public mixed $cancelling = ['work_order_id' => null, 'reason' => ''];

    public mixed $cancelReason = '';

    public function mount(MaintenanceRequest $maintenanceRequest): void
    {
        abort_unless($this->actor()->can('view', $maintenanceRequest), 403);
        $this->requestId = $maintenanceRequest->id;
    }

    private function request(): MaintenanceRequest
    {
        $request = MaintenanceRequest::query()->findOrFail($this->requestId);
        abort_unless($this->actor()->can('view', $request), 403);

        return $request;
    }

    /** A work order of this request, or a validation message on $field: a tampered id is never a 500 (plan ruling 18). */
    private function workOrderOf(mixed $id, string $field): WorkOrder
    {
        $workOrder = is_int($id) || (is_string($id) && ctype_digit($id)) ? $this->request()->workOrders()->find((int) $id) : null;
        if ($workOrder === null) {
            throw ValidationException::withMessages([$field => __('Choose a work order of this request.')]);
        }

        return $workOrder;
    }

    /** Re-throws an Action's messages under the form field that shows them. */
    private static function fail(ValidationException $e, string $field): never
    {
        throw ValidationException::withMessages([$field => Arr::flatten($e->errors())]);
    }

    public function addWorkOrder(AddWorkOrder $add): void
    {
        $this->resetErrorBag();
        try {
            $workOrder = $add->handle($this->actor(), $this->request(), is_array($this->workOrder) ? $this->workOrder : []);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ["workOrder.{$k}" => $m])->all());
        }
        $this->workOrder = self::NEW_WORK_ORDER;
        Flux::modal('add-work-order')->close();
        Flux::toast(variant: 'success', text: __('Work order :number added.', ['number' => $workOrder->number]));
    }

    public function startWorkOrder(mixed $workOrderId, ChangeWorkOrderStatus $change): void
    {
        $this->resetErrorBag();
        try {
            $change->handle($this->actor(), $this->workOrderOf($workOrderId, 'workOrders'), WorkOrderStatus::InProgress->value);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            self::fail($e, 'workOrders');
        }
    }

    public function openCancel(mixed $workOrderId): void
    {
        $this->resetErrorBag();
        $this->cancelling = ['work_order_id' => $this->workOrderOf($workOrderId, 'workOrders')->id, 'reason' => ''];
        Flux::modal('cancel-work-order')->show();
    }

    public function cancelWorkOrder(ChangeWorkOrderStatus $change): void
    {
        $this->resetErrorBag();
        $input = is_array($this->cancelling) ? $this->cancelling : [];
        try {
            $change->handle($this->actor(), $this->workOrderOf($input['work_order_id'] ?? null, 'cancelling.reason'),
                WorkOrderStatus::Cancelled->value, is_string($input['reason'] ?? null) ? $input['reason'] : '');
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            self::fail($e, 'cancelling.reason');
        }
        Flux::modal('cancel-work-order')->close();
        Flux::toast(variant: 'success', text: __('Work order cancelled.'));
    }

    public function addNote(AddMaintenanceNote $add): void
    {
        $this->resetErrorBag();
        try {
            $add->handle($this->actor(), $this->request(), is_string($this->note) ? $this->note : '');
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            self::fail($e, 'note');
        }
        $this->note = '';
    }

    public function resolve(ResolveMaintenanceRequest $resolve): void
    {
        $this->resetErrorBag();
        try {
            $resolve->handle($this->actor(), $this->request());
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            self::fail($e, 'request');
        }
        Flux::toast(variant: 'success', text: __('Request resolved.'));
    }

    public function cancelRequest(CancelMaintenanceRequest $cancel): void
    {
        $this->resetErrorBag();
        try {
            $cancel->handle($this->actor(), $this->request(), is_string($this->cancelReason) ? $this->cancelReason : '');
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            self::fail($e, 'cancelReason');
        }
        $this->cancelReason = '';
        Flux::modal('cancel-request')->close();
        Flux::toast(variant: 'success', text: __('Request cancelled.'));
    }

    public function render(): View
    {
        $request = MaintenanceRequest::query()
            ->with(['building', 'unit', 'customer', 'creator:id,name', 'workOrders.vendor', 'workOrders.expense', 'events.user:id,name', 'events.workOrder:id,number'])
            ->findOrFail($this->requestId);
        abort_unless($this->actor()->can('view', $request), 403);
        $canManage = $this->actor()->can('update', $request);
        $open = ! $request->status->isClosed();
        $expenses = $request->workOrders->pluck('expense')->filter()->values();

        return view('livewire.maintenance.request-show', [
            'request' => $request,
            'canManage' => $canManage,
            'open' => $open,
            'vendors' => $canManage && $open ? Vendor::query()->availableFor($request->category)->orderBy('name')->get(['id', 'name']) : collect(),
            'canResolve' => $canManage && $open && $request->workOrders->every(fn (WorkOrder $w) => ! $w->status->isOpen()),
            'canSeeExpenses' => $this->actor()->can('viewAny', Expense::class),
            'expenses' => $expenses,
            'expenseTotal' => Fils::toDecimal($expenses->filter(fn (Expense $e) => $e->status === ExpenseStatus::Recorded)->sum(fn (Expense $e) => Fils::fromDecimal($e->total))),
        ])->title($request->number);
    }
}
