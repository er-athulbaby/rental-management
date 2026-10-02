<?php

namespace App\Reports;

use App\Enums\AgreementStatus;
use App\Models\Agreement;
use App\Models\AgreementUnit;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
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
