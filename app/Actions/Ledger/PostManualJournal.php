<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Ledger\Poster;
use App\Models\Journal;
use App\Models\LedgerAccount;
use App\Models\LedgerSetting;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Spec §4, §8 (plan ruling 5): Finance posts a balanced journal of at least two lines, with a description, on an open date. */
final class PostManualJournal
{
    /** @param  mixed  $data  date, description, lines: list of [account_id, debit, credit, memo] */
    public function handle(User $actor, mixed $data): Journal
    {
        if (! $actor->can(PermissionName::LedgerManage)) {
            throw new AuthorizationException;
        }
        $data = is_array($data) ? $data : [];
        $data['lines'] = array_values(array_filter(is_array($data['lines'] ?? null) ? $data['lines'] : [], fn ($l) => is_array($l)
            && (filled($l['account_id'] ?? null) || filled($l['debit'] ?? null) || filled($l['credit'] ?? null))));
        $v = Validator::make($data, [
            'date' => ['required', 'string', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Bahrain')->toDateString()],
            'description' => ['required', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:2', 'max:50'],
            'lines.*.account_id' => ['required', 'integer', Rule::exists('ledger_accounts', 'id')->where('active', true)],
            'lines.*.debit' => ['nullable', 'string', Fils::rule()],
            'lines.*.credit' => ['nullable', 'string', Fils::rule()],
            'lines.*.memo' => ['nullable', 'string', 'max:255'],
        ], [
            'lines.required' => __('A journal needs at least two lines.'),
            'lines.min' => __('A journal needs at least two lines.'),
            'lines.*.account_id.exists' => __('Choose an active account.'),
        ])->validate();

        $lines = [];
        $debits = 0;
        $credits = 0;
        foreach ($v['lines'] as $i => $l) {
            $debit = filled($l['debit'] ?? null) ? Fils::fromDecimal((string) $l['debit']) : 0;
            $credit = filled($l['credit'] ?? null) ? Fils::fromDecimal((string) $l['credit']) : 0;
            if (($debit > 0) === ($credit > 0)) {
                throw ValidationException::withMessages(["lines.{$i}.debit" => __('Enter a debit or a credit on each line.')]);
            }
            $lines[] = ['account' => (int) $l['account_id'], 'amount' => $debit - $credit, 'customer_id' => null, 'owner_id' => null, 'building_id' => null,
                'unit_id' => null, 'memo' => filled($l['memo'] ?? null) ? trim((string) $l['memo']) : null];
            $debits += $debit;
            $credits += $credit;
        }
        if ($debits !== $credits) {
            throw ValidationException::withMessages(['lines' => __('The journal does not balance: debits :debits, credits :credits.', [
                'debits' => Fils::toDecimal($debits), 'credits' => Fils::toDecimal($credits)])]);
        }

        return DB::transaction(function () use ($actor, $v, $lines, $debits) {
            $settings = LedgerSetting::query()->lockForUpdate()->firstOrFail();
            self::openDate($settings, (string) $v['date'], 'date');
            self::activeAccounts(array_column($lines, 'account'), 'lines');
            $journal = Poster::write('manual', (string) $v['date'], trim((string) $v['description']), $lines, createdBy: $actor->id);
            Audit::log('ledger.journal_posted', $journal, properties: ['date' => $v['date'], 'total' => Fils::toDecimal($debits)], causer: $actor);

            return $journal;
        });
    }

    /** Throws unless every account is still active: a deactivation can land after the first check. The caller holds the settings lock. @param list<int> $ids */
    public static function activeAccounts(array $ids, string $field): void
    {
        $inactive = LedgerAccount::query()->whereKey(array_unique($ids))->where('active', false)->orderBy('code')->first();
        if ($inactive !== null) {
            throw ValidationException::withMessages([$field => __('Account :account is no longer active.', ['account' => $inactive->label()])]);
        }
    }

    /** Throws unless $date is on or after the start date and after the lock (plan ruling 5). The caller holds the settings lock. */
    public static function openDate(LedgerSetting $settings, string $date, string $field): void
    {
        $first = $settings->firstOpenDay();
        if ($first === null) {
            throw ValidationException::withMessages([$field => __('Set the ledger start date first.')]);
        }
        if ($date < $first->toDateString()) {
            throw ValidationException::withMessages([$field => __('Choose a date on or after :date: earlier days are locked or before the start date.', ['date' => $first->format('d/m/Y')])]);
        }
    }
}
