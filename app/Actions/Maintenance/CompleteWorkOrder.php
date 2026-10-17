<?php

namespace App\Actions\Maintenance;

use App\Actions\Expenses\RecordExpense;
use App\Billing\Tax;
use App\Enums\TaxCategory;
use App\Enums\WorkOrderStatus;
use App\Models\CompanySetting;
use App\Models\Expense;
use App\Models\MaintenanceEvent;
use App\Models\MaintenanceRequest;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Spec 2026-10-17 maintenance §4 step 4. A cost is recorded by RecordExpense inside this transaction, so every expense
 * rule applies and a refusal leaves the work order not completed (plan ruling 5). No cost: no expense.
 */
final class CompleteWorkOrder
{
    /** RecordExpense's field => the completion form's field, so a refusal points at what the user typed. */
    private const array ERROR_KEYS = [
        'net' => 'actual_net', 'tax_amount' => 'actual_tax', 'expense_date' => 'completed_on', 'unit_id' => 'charge_to', 'building_id' => 'charge_to',
    ];

    public function __construct(private RecordExpense $record, private ResolveMaintenanceRequest $resolve) {}

    /** @param  array<array-key, mixed>  $data */
    public function handle(User $actor, WorkOrder $workOrder, array $data, ?UploadedFile $ownerApproval = null): WorkOrder
    {
        if (! $actor->can('update', $workOrder)) {
            throw new AuthorizationException;
        }

        $data = array_map(fn (mixed $v) => $v === '' ? null : $v, $data);
        $v = Validator::make($data, [
            'completion_note' => ['required', 'string', 'max:2000'],
            'completed_on' => ['bail', 'required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'actual_net' => ['nullable', Fils::rule()],
            'actual_tax' => ['nullable', Fils::rule()],
        ])->validate();

        if ($v['completed_on'] < $workOrder->maintenanceRequest->created_at->timezone('Asia/Bahrain')->toDateString()) {
            throw ValidationException::withMessages(['completed_on' => __('The work cannot be completed before the request was logged.')]);
        }

        $net = Fils::fromDecimal((string) ($v['actual_net'] ?? '0'));
        $tax = Fils::fromDecimal((string) ($v['actual_tax'] ?? '0'));
        if ($net === 0 && $tax > 0) {
            throw ValidationException::withMessages(['actual_net' => __('Enter the amount before VAT.')]);
        }
        $hasCost = $net + $tax > 0;
        if ($hasCost && ! $actor->can('create', Expense::class)) {
            throw new AuthorizationException(__('Recording the cost needs expenses.manage.'));
        }

        return DB::transaction(function () use ($actor, $workOrder, $data, $v, $net, $tax, $hasCost, $ownerApproval) {
            $request = MaintenanceRequest::query()->lockForUpdate()->findOrFail($workOrder->maintenance_request_id);
            $locked = WorkOrder::query()->with('vendor')->lockForUpdate()->findOrFail($workOrder->id);
            if (! $locked->status->isOpen()) {
                throw ValidationException::withMessages(['completion_note' => __('This work order is :status.', ['status' => mb_strtolower($locked->status->label())])]);
            }

            $expense = $hasCost ? $this->expense($actor, $request, $data, (string) $v['completed_on'], $net, $tax, $ownerApproval) : null;

            $from = $locked->status;
            $locked->forceFill([
                'status' => WorkOrderStatus::Completed,
                'completed_on' => $v['completed_on'],
                'completion_note' => $v['completion_note'],
                'actual_net' => $hasCost ? Fils::toDecimal($net) : null,
                'actual_tax' => $hasCost ? Fils::toDecimal($tax) : null,
                'expense_id' => $expense?->id,
            ])->save();
            MaintenanceEvent::record($request, $actor, $locked, $from->value, WorkOrderStatus::Completed->value);
            $this->resolve->ifDone($actor, $request);

            return $locked;
        });
    }

    /**
     * Plan ruling 6: building, unit, date, net and VAT from the work order; category, description and payer from the form.
     *
     * @param  array<array-key, mixed>  $data
     */
    private function expense(User $actor, MaintenanceRequest $request, array $data, string $date, int $net, int $tax, ?UploadedFile $ownerApproval): Expense
    {
        try {
            return $this->record->handle($actor, [
                'building_id' => $request->building_id,
                'unit_id' => $request->unit_id,
                'category' => $data['category'] ?? null,
                'description' => $data['description'] ?? null,
                'expense_date' => $date,
                'net' => Fils::toDecimal($net),
                'tax_amount' => Fils::toDecimal($tax),
                'charge_to' => $data['charge_to'] ?? null,
                'owner_approval_note' => $data['owner_approval_note'] ?? null,
            ], $ownerApproval);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn (array $m, string $k) => [self::ERROR_KEYS[$k] ?? $k => $m])->all());
        }
    }

    /** Spec §4 step 4: VAT at the company rate when VAT-registered; '0.000' otherwise, or while $net is not a valid amount. */
    public static function defaultVat(mixed $net): string
    {
        $settings = CompanySetting::current();

        return Fils::toDecimal(Tax::amount(self::fils($net), TaxCategory::Standard, $settings->vat_registered, (string) $settings->vat_rate));
    }

    /** Net + VAT in fils, counting an amount that is not valid yet as 0. */
    public static function costFils(mixed $net, mixed $tax): int
    {
        return self::fils($net) + self::fils($tax);
    }

    /** "WO-2026-000001 · Ali Plumbing · Kitchen sink leaking" (spec §4 step 4), within the expense's 500 characters. */
    public static function expenseDescription(WorkOrder $workOrder): string
    {
        return Str::limit($workOrder->number.' · '.$workOrder->vendor->name.' · '.$workOrder->maintenanceRequest->summary(), 500, '');
    }

    private static function fils(mixed $amount): int
    {
        return is_string($amount) && preg_match(Str::after(Fils::rule(), 'regex:'), trim($amount)) === 1 ? Fils::fromDecimal(trim($amount)) : 0;
    }
}
