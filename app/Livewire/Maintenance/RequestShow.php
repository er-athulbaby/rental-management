<?php

namespace App\Livewire\Maintenance;

use App\Actions\Maintenance\AddMaintenanceNote;
use App\Actions\Maintenance\AddWorkOrder;
use App\Actions\Maintenance\CancelMaintenanceRequest;
use App\Actions\Maintenance\ChangeWorkOrderStatus;
use App\Actions\Maintenance\CompleteWorkOrder;
use App\Actions\Maintenance\ResolveMaintenanceRequest;
use App\Enums\ExpenseCategory;
use App\Enums\ExpenseStatus;
use App\Enums\PermissionName;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Spec 2026-10-17 maintenance §5: a request with its photos, work orders, timeline and linked expenses. Every action
 * re-checks permission and building scope through its Action; ids from the browser are looked up inside this request.
 */
class RequestShow extends Component
{
    use WithActor, WithFileUploads;

    private const array NEW_WORK_ORDER = ['vendor_id' => '', 'scheduled_on' => '', 'instructions' => '', 'estimate' => ''];

    #[Locked]
    public int $requestId;

    // Untyped on purpose: a tampered payload reaches the Actions' validation instead of a TypeError (a 500).
    public mixed $workOrder = self::NEW_WORK_ORDER;

    public mixed $note = '';

    public mixed $cancelling = ['work_order_id' => null, 'reason' => ''];

    public mixed $cancelReason = '';

    public mixed $completion = [];

    /** @var UploadedFile|null */
    public $ownerApproval = null;

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

    public function startWorkOrder(mixed $workOrderId = null): void
    {
        $this->resetErrorBag();
        try {
            app(ChangeWorkOrderStatus::class)->handle($this->actor(), $this->workOrderOf($workOrderId, 'workOrders'), WorkOrderStatus::InProgress->value);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            self::fail($e, 'workOrders');
        }
    }

    public function openCancel(mixed $workOrderId = null): void
    {
        $this->resetErrorBag();
        abort_unless($this->actor()->can('update', $this->request()), 403);
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

    /** Spec §4 step 4: the completion form, with the expense prefilled from the work order (plan ruling 6). */
    public function openCompletion(mixed $workOrderId = null): void
    {
        $this->resetErrorBag();
        abort_unless($this->actor()->can('update', $this->request()), 403);
        $workOrder = $this->workOrderOf($workOrderId, 'workOrders');
        $this->completion = [
            'work_order_id' => $workOrder->id,
            'completion_note' => '',
            'completed_on' => now('Asia/Bahrain')->toDateString(),
            'actual_net' => '',
            'actual_tax' => '0.000',
            'charge_to' => 'company',
            'category' => $this->request()->category->expenseCategory()->value,
            'description' => CompleteWorkOrder::expenseDescription($workOrder),
            'owner_approval_note' => '',
        ];
        $this->ownerApproval = null;
        Flux::modal('complete-work-order')->show();
    }

    /** VAT follows the net at the company rate and can still be changed. $key is null when the whole form is replaced. */
    public function updatedCompletion(mixed $value, ?string $key = null): void
    {
        if (! is_array($this->completion)) {
            $this->completion = []; // a tampered payload starts the form again

            return;
        }
        if ($key === 'actual_net') {
            $this->completion['actual_tax'] = CompleteWorkOrder::defaultVat($value);
        }
    }

    public function complete(CompleteWorkOrder $complete): void
    {
        $this->resetErrorBag();
        abort_unless($this->actor()->can('update', $this->request()), 403);
        $input = is_array($this->completion) ? $this->completion : [];
        $workOrder = $this->workOrderOf($input['work_order_id'] ?? null, 'completion.completion_note'); // outside the try: its key is final

        try {
            $complete->handle($this->actor(), $workOrder, $input, $this->ownerApproval instanceof UploadedFile ? $this->ownerApproval : null);
        } catch (AuthorizationException) {
            // The user may manage the request, so this is about the cost (plan ruling 7).
            throw ValidationException::withMessages(['completion.charge_to' => __('You cannot record this cost: it needs expenses.manage, and charging the tenant also needs invoices.manage.')]);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => [$k === 'owner_approval' ? 'ownerApproval' : "completion.{$k}" => $m])->all());
        }

        $this->completion = [];
        $this->ownerApproval = null;
        Flux::modal('complete-work-order')->close();
        Flux::toast(variant: 'success', text: __('Work order completed.'));
    }

    public function render(): View
    {
        $request = MaintenanceRequest::query()
            ->with(['building' => fn ($q) => $q->withTrashed(), 'unit' => fn ($q) => $q->withTrashed(), 'customer', 'creator:id,name', 'workOrders.vendor' => fn ($q) => $q->withTrashed(), 'workOrders.expense', 'events.user:id,name', 'events.workOrder:id,number'])
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
            'completion' => is_array($this->completion) ? $this->completion : [],
            'hasCost' => is_array($this->completion) && CompleteWorkOrder::costFils($this->completion['actual_net'] ?? null, $this->completion['actual_tax'] ?? null) > 0,
            'canRecordCost' => $this->actor()->can('create', Expense::class),
            'canChargeTenant' => $this->actor()->can(PermissionName::InvoicesManage),
            'expenseCategories' => ExpenseCategory::cases(),
            'expenses' => $expenses,
            'expenseTotal' => Fils::toDecimal($expenses->filter(fn (Expense $e) => $e->status === ExpenseStatus::Recorded)->sum(fn (Expense $e) => Fils::fromDecimal($e->total))),
        ])->title($request->number);
    }
}
