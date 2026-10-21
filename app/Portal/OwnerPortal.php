<?php

namespace App\Portal;

use App\Billing\OwnerStatementCalculator;
use App\Enums\ChargeTo;
use App\Enums\DisbursementStatus;
use App\Enums\ExpenseStatus;
use App\Enums\OwnerContractStatus;
use App\Enums\OwnerContractType;
use App\Enums\OwnerPayableStatus;
use App\Enums\OwnerStatementStatus;
use App\Enums\PayeeType;
use App\Models\Building;
use App\Models\Disbursement;
use App\Models\Expense;
use App\Models\MaintenanceRequest;
use App\Models\OwnerContract;
use App\Models\OwnerPayable;
use App\Models\OwnerStatement;
use App\Models\Unit;
use App\Models\WorkOrder;
use App\Reports\Queries;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * Spec 2026-10-21 owner portal §4 and §6: every owner-portal query, each starting from the signed-in account's owner_id
 * and going through that owner's contracts (OwnerContract::portalFor). Pages and downloads read only these, so a record
 * that is not the owner's is never listed and its id is a 404 (owner portal plan rulings 8–10).
 */
final class OwnerPortal
{
    /** Ledger kinds the statement summary splits out (owner portal plan ruling 11); everything else is income. */
    private const array CHARGE_KINDS = ['expense', 'expense_reversal'];

    private const array PAYOUT_KINDS = ['remittance', 'remittance_reversal'];

    /** @return Builder<OwnerStatement> finalised statements of their contracts */
    public static function statements(int $ownerId): Builder
    {
        return OwnerStatement::query()->whereIn('owner_contract_id', OwnerContract::query()->portalFor($ownerId)->select('id'))
            ->where('status', OwnerStatementStatus::Finalised);
    }

    /** @return Builder<Disbursement> paid payments out to the owner; waiting, rejected and reversed ones are not payouts */
    public static function payouts(int $ownerId): Builder
    {
        return Disbursement::query()->where('payee_type', PayeeType::Owner)->where('payee_id', $ownerId)->where('status', DisbursementStatus::Paid);
    }

    /** @return Builder<OwnerPayable> the rent schedule of their leased contracts; cancelled (replaced) rows left out */
    public static function payables(int $ownerId): Builder
    {
        return OwnerPayable::query()
            ->whereIn('owner_contract_id', OwnerContract::query()->portalFor($ownerId)->where('type', OwnerContractType::Leased)->select('id'))
            ->where('status', '!=', OwnerPayableStatus::Cancelled);
    }

    /** @return Builder<Expense> expenses charged to the owner on their contracts, reversed ones included */
    public static function expenses(int $ownerId): Builder
    {
        return Expense::query()->where('charge_to', ChargeTo::Owner)
            ->whereIn('owner_contract_id', OwnerContract::query()->portalFor($ownerId)->select('id'));
    }

    /** @return Builder<MaintenanceRequest> requests in their buildings, logged on a day one of their contracts on that building covered */
    public static function requests(int $ownerId): Builder
    {
        return MaintenanceRequest::query()->whereExists(fn (QueryBuilder $q) => $q->from('owner_contracts as oc')
            ->whereColumn('oc.building_id', 'maintenance_requests.building_id')
            ->where('oc.owner_id', $ownerId)
            ->whereIn('oc.status', array_map(fn (OwnerContractStatus $s) => $s->value, OwnerContract::ATTRIBUTED))
            ->whereRaw('oc.start_date <= DATE(maintenance_requests.created_at)')
            ->whereRaw('COALESCE(oc.terminated_on, oc.end_date) >= DATE(maintenance_requests.created_at)'));
    }

    /** What a request cost the owner: its work orders' recorded expenses charged to the owner on their contracts. */
    public static function costFils(MaintenanceRequest $request, int $ownerId): int
    {
        return Fils::fromDecimal((string) (self::expenses($ownerId)->where('status', ExpenseStatus::Recorded)
            ->whereIn('id', WorkOrder::query()->where('maintenance_request_id', $request->id)->whereNotNull('expense_id')->select('expense_id'))
            ->sum('total') ?: '0'));
    }

    /**
     * A finalised statement's figures from its stored columns and its window's ledger entries, each net of its reversals:
     * opening + income − charges − fees − paid = closing (owner portal plan ruling 11).
     *
     * @return array{opening: int, income: int, charges: int, fees: int, paid: int, closing: int}
     */
    public static function summary(OwnerStatement $statement): array
    {
        $figures = OwnerStatementCalculator::stored($statement);
        $sum = fn (array $kinds, bool $in): int => (int) $figures['entries']->filter(fn (array $e) => in_array($e['kind'], $kinds, true) === $in)->sum('amount');

        return [
            'opening' => $figures['opening'],
            'income' => $sum([...self::CHARGE_KINDS, ...self::PAYOUT_KINDS], false),
            'charges' => -$sum(self::CHARGE_KINDS, true),
            'fees' => $figures['fee'] + $figures['fee_tax'],
            'paid' => -$sum(self::PAYOUT_KINDS, true),
            'closing' => $figures['closing'],
        ];
    }

    /**
     * Spec §4 "Buildings": one card per building of their contracts. The rent roll only while one of their managed
     * contracts on it is active today; otherwise the unit codes alone (owner portal plan ruling 9).
     *
     * @return Collection<int, array{building: Building, managed: bool, units: list<string>, roll: list<array{unit: string, tenant: ?string, start: ?string, end: ?string, rent: ?int, owed: ?int}>|null, occupied: int<0, max>, total: int<0, max>}>
     */
    public static function buildings(int $ownerId): Collection
    {
        $today = now('Asia/Bahrain')->toDateString();
        $date = fn (mixed $d) => $d === null ? null : CarbonImmutable::parse((string) $d)->format('d/m/Y');

        return OwnerContract::query()->portalFor($ownerId)
            ->with(['building' => fn ($q) => $q->withTrashed(), 'units' => fn ($q) => $q->withTrashed()])
            ->orderBy('id')->get()
            ->groupBy('building_id')
            ->map(function (Collection $group) use ($today, $date) {
                $live = $group->first(fn (OwnerContract $c) => $c->type === OwnerContractType::Managed && $c->status === OwnerContractStatus::Active
                    && $c->start_date->toDateString() <= $today && $c->end_date->toDateString() >= $today);
                $roll = null;
                if ($live !== null) {
                    $seen = [];
                    $roll = Queries::rentRollUnits(Unit::query()->whereIn('id', $live->units->modelKeys())->select('id'), $today)
                        ->map(function (\stdClass $u) use (&$seen, $date) {
                            $occupied = $u->au_id !== null;
                            $first = $occupied && ! isset($seen[$u->agreement_id]); // balance owed once per agreement, as the report
                            if ($occupied) {
                                $seen[$u->agreement_id] = true;
                            }

                            return [
                                'unit' => (string) $u->unit,
                                'tenant' => $occupied ? (string) $u->tenant : null,
                                'start' => $occupied ? $date($u->start_date) : null,
                                'end' => $occupied ? $date($u->end_date) : null,
                                'rent' => $occupied ? Fils::fromDecimal((string) ($u->rent ?? '0')) : null,
                                'owed' => $first ? Fils::fromDecimal((string) ($u->owed ?? '0')) : null,
                            ];
                        });
                    $roll = array_values($roll->all());
                }
                /** @var OwnerContract $sample */
                $sample = $group->first();

                return [
                    'building' => $sample->building ?? abort(404),
                    'managed' => $live !== null,
                    'units' => array_values($group->flatMap(fn (OwnerContract $c) => $c->units->map(fn (Unit $u) => (string) $u->code))->unique()->sort()->all()),
                    'roll' => $roll,
                    'occupied' => $roll === null ? 0 : count(array_filter($roll, fn (array $r) => $r['tenant'] !== null)),
                    'total' => $roll === null ? 0 : count($roll),
                ];
            })->values();
    }
}
