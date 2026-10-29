<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\DisbursementMethod;
use App\Enums\PermissionName;
use App\Ledger\Balances;
use App\Ledger\Sources;
use App\Models\LedgerSetting;
use App\Models\User;
use App\Models\VatReturn;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Ledger money screens spec §4 (plan rulings 9–10): Finance records the payment to NBR for a period, or the refund when
 * the period's VAT payable is a debit. Periods of standing returns never overlap. Poster posts it after commit.
 */
final class RecordVatReturn
{
    /** @param  mixed  $data  period_from, period_to, date, method, reference, amount, note */
    public function handle(User $actor, mixed $data): VatReturn
    {
        if (! $actor->can(PermissionName::LedgerManage)) {
            throw new AuthorizationException;
        }
        $data = is_array($data) ? $data : [];
        $today = now('Asia/Bahrain')->toDateString();
        $v = Validator::make($data, [
            'period_from' => ['required', 'string', 'date_format:Y-m-d'],
            'period_to' => ['required', 'string', 'date_format:Y-m-d', 'after_or_equal:period_from', 'before_or_equal:'.$today],
            'date' => ['required', 'string', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'method' => ['required', 'string', Rule::enum(DisbursementMethod::class)],
            'reference' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'string', Fils::rule(), 'not_regex:/^0+(\.0+)?$/'],
            'note' => ['nullable', 'string', 'max:1000'],
        ])->validate();
        $from = (string) $v['period_from'];
        $to = (string) $v['period_to'];

        return DB::transaction(function () use ($actor, $v, $from, $to) {
            // Every ledger writer takes this lock first, so the overlap check below cannot race another return.
            $settings = LedgerSetting::query()->lockForUpdate()->firstOrFail();
            PostManualJournal::openDate($settings, (string) $v['date'], 'date');
            $clash = VatReturn::query()->whereNull('reverses_id')->whereDoesntHave('reversal')
                ->where('period_from', '<=', $to)->where('period_to', '>=', $from)->orderBy('period_from')->first();
            if ($clash !== null) {
                throw ValidationException::withMessages(['period_from' => __('This period overlaps the return for :from – :to.', [
                    'from' => $clash->period_from->format('d/m/Y'), 'to' => $clash->period_to->format('d/m/Y')])]);
            }
            $figure = Balances::vatDue($from, $to);

            $return = (new VatReturn)->forceFill([
                'period_from' => $from,
                'period_to' => $to,
                'direction' => $figure < 0 ? 'refund' : 'payment',
                'method' => (string) $v['method'],
                'amount' => Fils::toDecimal(Fils::fromDecimal((string) $v['amount'])),
                'date' => (string) $v['date'],
                'reference' => trim((string) $v['reference']),
                'note' => filled($v['note'] ?? null) ? trim((string) $v['note']) : null,
                'created_by' => $actor->id,
            ]);
            $return->save();
            Sources::assertPostable('vat_return', $return->id, 'method');
            Audit::log('ledger.vat_return_recorded', $return, new: ['period_from' => $from, 'period_to' => $to, 'direction' => $return->direction,
                'amount' => $return->amount, 'figure' => Fils::toDecimal($figure)], causer: $actor);

            return $return;
        });
    }
}
