<?php

namespace App\Reports;

use App\Enums\AgreementStatus;
use App\Enums\ChequeDirection;
use App\Enums\ChequeStatus;
use App\Enums\DocumentCategory;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\PaymentStatus;
use App\Enums\PermissionName;
use App\Models\Agreement;
use App\Models\AgreementAmendment;
use App\Models\AgreementUnit;
use App\Models\Approval;
use App\Models\Cheque;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Owner;
use App\Models\Payment;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Plan ruling 2: the building-scoped queries that reports, dashboard tiles and digest emails share, so a tile always
 * equals the report it links to.
 */
final class Queries
{
    /** @return Builder<Agreement> */
    public static function expiringAgreements(User $user, int $days, ?int $buildingId = null): Builder
    {
        $today = now('Asia/Bahrain')->toDateString();

        return self::inBuilding(Agreement::query()->visibleTo($user), $buildingId)
            ->where('status', AgreementStatus::Active)
            ->whereBetween('end_date', [$today, now('Asia/Bahrain')->addDays($days)->toDateString()]);
    }

    /**
     * Spec §10: expired agreements not closed — still holding a unit (no move-out recorded on it).
     *
     * @return Builder<Agreement>
     */
    public static function overstays(User $user, ?int $buildingId = null): Builder
    {
        return self::inBuilding(Agreement::query()->visibleTo($user), $buildingId)
            ->where('status', AgreementStatus::Expired)
            ->whereHas('agreementUnits', fn (Builder $q) => $q->whereNull('move_out_date'));
    }

    /** Units occupied on $on: an agreement unit of a non-draft agreement covers the date (effective end, §5.9). */
    public static function occupiedUnitIds(User $user, string $on): QueryBuilder
    {
        return DB::table('agreement_units as au')->join('agreements as a', 'a.id', '=', 'au.agreement_id')
            ->whereNull('a.deleted_at')
            ->whereNotIn('a.status', [AgreementStatus::Draft->value, AgreementStatus::PendingApproval->value])
            ->where('au.start_date', '<=', $on)
            ->whereRaw('('.AgreementUnit::effectiveEndSql('au').') >= ?', [$on, $on])
            ->whereIn('au.unit_id', Unit::query()->visibleTo($user)->select('id'))
            ->select('au.unit_id')->distinct();
    }

    /**
     * Spec §10: pending approvals whose record the viewer may view, plus the viewer's own requests (plan ruling 8).
     *
     * @return Collection<int, Approval>
     */
    public static function visiblePendingApprovals(User $user): Collection
    {
        return Approval::query()->pending()->with(['requester:id,name', 'approvable'])->orderBy('requested_at')->get()
            ->filter(fn (Approval $a) => $a->requested_by === $user->id || self::mayView($user, $a))
            ->values();
    }

    /**
     * Pending approvals the viewer may decide (approvals.decide, and not their own when require_different_approver).
     *
     * @return Collection<int, Approval>
     */
    public static function approvalsToDecide(User $user): Collection
    {
        if (! $user->can(PermissionName::ApprovalsDecide)) {
            return collect();
        }
        $different = CompanySetting::current()->require_different_approver;

        return Approval::query()->pending()->orderBy('requested_at')->get()
            ->reject(fn (Approval $a) => $different && in_array($user->id, [$a->requested_by, $a->handler()->creatorId($a)], true))
            ->values();
    }

    private static function mayView(User $user, Approval $approval): bool
    {
        $record = $approval->approvable;
        if ($record instanceof AgreementAmendment) {
            $record = $record->agreement; // amendments have no policy: their agreement decides
        }

        return $record !== null && $user->can('view', $record);
    }

    /**
     * Plan ruling 7: ID and CR copies expiring within $days, of visible customers and (with owners.view) owners.
     *
     * @return Collection<int, Document>
     */
    public static function expiringIdDocuments(User $user, int $days): Collection
    {
        $today = now('Asia/Bahrain')->toDateString();
        $until = now('Asia/Bahrain')->addDays($days)->toDateString();

        return Document::query()->with('documentable')
            ->whereIn('category', [DocumentCategory::IdCopy->value, DocumentCategory::CrCopy->value])
            ->whereBetween('expires_on', [$today, $until])
            ->where(function ($q) use ($user) {
                $q->where(fn ($c) => $c->where('documentable_type', (new Customer)->getMorphClass())
                    ->whereIn('documentable_id', Customer::query()->visibleTo($user)->select('id')));
                if ($user->can(PermissionName::OwnersView)) {
                    $q->orWhere('documentable_type', (new Owner)->getMorphClass());
                }
            })
            ->orderBy('expires_on')->orderBy('id')->get();
    }

    /** Plan ruling 3: the Sunday ending the Monday–Sunday week that contains today. */
    public static function weekEnd(): string
    {
        return now('Asia/Bahrain')->endOfWeek(CarbonInterface::SUNDAY)->toDateString();
    }

    /**
     * Held received cheques due for deposit by $until.
     *
     * @return Builder<Cheque>
     */
    public static function chequesToDeposit(User $user, string $until): Builder
    {
        return Cheque::query()->visibleTo($user)->where('direction', ChequeDirection::Received)
            ->where('status', ChequeStatus::Held)->where('cheque_date', '<=', $until);
    }

    /**
     * Plan ruling 4.
     *
     * @return Builder<Cheque>
     */
    public static function bouncedCheques(User $user): Builder
    {
        return Cheque::query()->visibleTo($user)->where('direction', ChequeDirection::Received)->where('status', ChequeStatus::Bounced);
    }

    /**
     * Plan ruling 5: held cheques re-billing left without an invoice.
     *
     * @return Builder<Cheque>
     */
    public static function chequesToReturn(User $user): Builder
    {
        return Cheque::query()->visibleTo($user)->where('direction', ChequeDirection::Received)
            ->where('status', ChequeStatus::Held)->where('to_return', true);
    }

    /**
     * Issued invoices with a balance (credit notes never carry one, spec §7.10).
     *
     * @return Builder<Invoice>
     */
    public static function openInvoices(User $user, ?int $buildingId = null): Builder
    {
        return Invoice::query()->visibleTo($user)->where('status', InvoiceStatus::Issued)->where('type', '!=', InvoiceType::CreditNote)
            ->where('balance', '>', 0)
            ->when($buildingId, fn (Builder $q, int $b) => $q->whereHas('lines', fn (Builder $l) => $l->whereIn('unit_id', Unit::query()->where('building_id', $b)->select('id'))));
    }

    /**
     * Open invoices past their grace date (spec §10 ageing; §2 overdue = due date + grace days).
     *
     * @return Builder<Invoice>
     */
    public static function overdueInvoices(User $user, ?int $buildingId = null): Builder
    {
        return self::openInvoices($user, $buildingId)->where('grace_until', '<', now('Asia/Bahrain')->toDateString());
    }

    /** @return Builder<Payment> */
    public static function collections(User $user, string $from, string $to, ?int $buildingId = null): Builder
    {
        return Payment::query()->where('status', PaymentStatus::Confirmed)->whereBetween('received_on', [$from, $to])
            ->whereIn('customer_id', Customer::query()->visibleTo($user)->select('id'))
            ->when($buildingId, fn (Builder $q, int $b) => $q->whereIn('customer_id', Agreement::query()
                ->whereHas('agreementUnits.unit', fn (Builder $u) => $u->where('building_id', $b))->select('customer_id')));
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function inBuilding(Builder $query, ?int $buildingId): Builder
    {
        return $query->when($buildingId, fn (Builder $q, int $b) => $q->whereHas('agreementUnits.unit', fn (Builder $u) => $u->where('building_id', $b)));
    }
}
