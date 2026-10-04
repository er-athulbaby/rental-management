<?php

namespace App\Models;

use App\Enums\AgreementStatus;
use App\Enums\ChargeType;
use App\Enums\PaymentFrequency;
use App\Enums\PermissionName;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Database\Factories\AgreementFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property string|null $number
 * @property string|null $import_ref
 * @property int $customer_id
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable $end_date
 * @property PaymentFrequency $frequency
 * @property int|null $billing_day
 * @property int $grace_days
 * @property int $notice_period_days
 * @property CarbonImmutable|null $notice_date
 * @property CarbonImmutable|null $planned_exit_date
 * @property AgreementStatus $status
 * @property int|null $previous_agreement_id
 * @property int|null $contract_template_id
 * @property string|null $verify_token
 * @property int $created_by
 * @property-read Customer $customer
 */
class Agreement extends Model
{
    /** @use HasFactory<AgreementFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'customer_id', 'start_date', 'end_date', 'frequency', 'billing_day', 'grace_days', 'notice_period_days',
        'previous_agreement_id', 'contract_template_id',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'notice_date' => 'immutable_date',
            'planned_exit_date' => 'immutable_date',
            'frequency' => PaymentFrequency::class,
            'status' => AgreementStatus::class,
            'billing_day' => 'integer',
            'grace_days' => 'integer',
            'notice_period_days' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logExcept(['verify_token'])->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<AgreementAmendment, $this> */
    public function amendments(): HasMany
    {
        return $this->hasMany(AgreementAmendment::class);
    }

    /** @return HasMany<AgreementUnit, $this> */
    public function agreementUnits(): HasMany
    {
        return $this->hasMany(AgreementUnit::class);
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** @return HasMany<AgreementClause, $this> */
    public function clauses(): HasMany
    {
        return $this->hasMany(AgreementClause::class)->orderBy('position');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<Agreement, $this> */
    public function previous(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_agreement_id');
    }

    /** @return BelongsTo<ContractTemplate, $this> */
    public function contractTemplate(): BelongsTo
    {
        return $this->belongsTo(ContractTemplate::class);
    }

    /** @return MorphMany<Approval, $this> */
    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable');
    }

    /** @return MorphMany<Document, $this> */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /**
     * Spec §8.2: visible when any unit is in an assigned building. A scoped user's own draft with no unit yet stays visible to them.
     *
     * @param  Builder<Agreement>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if ($user->can(PermissionName::BuildingsViewAll)) {
            return;
        }

        $query->where(fn (Builder $q) => $q
            ->whereHas('agreementUnits.unit', fn (Builder $unit) => $unit->visibleTo($user))
            ->orWhere(fn (Builder $own) => $own->where('created_by', $user->getKey())->whereDoesntHave('agreementUnits')));
    }

    public function label(): string
    {
        return $this->number ?? __('Draft #:id', ['id' => $this->id]);
    }

    /** Σ rent charges, monthly (spec §5.6 {total_monthly_rent}). Needs agreementUnits.charges loaded. */
    public function monthlyRentFils(): int
    {
        return $this->agreementUnits->sum(fn (AgreementUnit $au) => $au->charges
            ->where('type', ChargeType::Rent)->sum(fn (AgreementUnitCharge $c) => Fils::fromDecimal($c->monthly_amount)));
    }

    public function listRentFils(): int
    {
        return $this->agreementUnits->sum(fn (AgreementUnit $au) => Fils::fromDecimal($au->list_rent));
    }

    /** List rent − agreed rent (spec §5.3: shown on the approval screen). */
    public function discountFils(): int
    {
        return $this->listRentFils() - $this->monthlyRentFils();
    }

    public function depositFils(): int
    {
        return $this->agreementUnits->sum(fn (AgreementUnit $au) => Fils::fromDecimal($au->deposit_amount));
    }

    /** @param  Builder<Agreement>  $query */
    #[Scope]
    protected function inBuilding(Builder $query, int $buildingId): void
    {
        $query->whereHas('agreementUnits.unit', fn (Builder $unit) => $unit->where('building_id', $buildingId));
    }
}
