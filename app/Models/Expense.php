<?php

namespace App\Models;

use App\Enums\ChargeTo;
use App\Enums\ExpenseCategory;
use App\Enums\ExpenseStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Written only by RecordExpense and ReverseExpense; amounts are set with forceFill.
 *
 * @property int $id
 * @property int $building_id
 * @property int|null $unit_id
 * @property string $description
 * @property ExpenseCategory $category
 * @property CarbonImmutable $expense_date
 * @property string $net
 * @property string $tax_amount
 * @property string $total
 * @property ChargeTo $charge_to
 * @property int|null $owner_contract_id
 * @property int|null $agreement_unit_id
 * @property int|null $invoice_id
 * @property ExpenseStatus $status
 * @property int|null $reversed_by
 * @property int $recorded_by
 */
class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = ['building_id', 'unit_id', 'category', 'description', 'expense_date', 'owner_approval_note'];

    protected function casts(): array
    {
        return [
            'category' => ExpenseCategory::class,
            'charge_to' => ChargeTo::class,
            'status' => ExpenseStatus::class,
            'expense_date' => 'immutable_date',
            'net' => 'decimal:3',
            'tax_amount' => 'decimal:3',
            'total' => 'decimal:3',
            'posted_at' => 'immutable_datetime',
            'reversed_at' => 'immutable_datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return BelongsTo<Building, $this> */
    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<OwnerContract, $this> */
    public function ownerContract(): BelongsTo
    {
        return $this->belongsTo(OwnerContract::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<AgreementUnit, $this> */
    public function agreementUnit(): BelongsTo
    {
        return $this->belongsTo(AgreementUnit::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return BelongsToMany<Disbursement, $this> ledger money screens plan ruling 2: the vendor bills it was ever on */
    public function disbursements(): BelongsToMany
    {
        return $this->belongsToMany(Disbursement::class, 'disbursement_expenses');
    }

    /** Ledger money screens plan ruling 3: the one vendor bill waiting, approved or paid that holds it, if any. */
    public function activePayment(): ?Disbursement
    {
        return $this->disbursements()->whereIn('disbursements.status', Disbursement::ACTIVE)->first();
    }

    /**
     * Ledger money screens plan ruling 2: recorded and on no vendor bill that is waiting, approved or paid; what a new
     * vendor bill may pay.
     *
     * @param  Builder<Expense>  $query
     */
    #[Scope]
    protected function unpaid(Builder $query): void
    {
        $query->where('expenses.status', ExpenseStatus::Recorded->value)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('disbursement_expenses as de')->join('disbursements as d', 'd.id', '=', 'de.disbursement_id')
                ->whereColumn('de.expense_id', 'expenses.id')->whereIn('d.status', Disbursement::ACTIVE));
    }

    /** @param  Builder<Expense>  $query */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $query->whereHas('building', fn (Builder $q) => $q->visibleTo($user));
    }
}
