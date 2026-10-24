<?php

namespace App\Ledger;

use App\Models\Invoice;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\LedgerSetting;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Support\Fils;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * Plan ruling 1: a money record posts after its own transaction commits, in a transaction of its own. A failure is
 * reported and never undoes the money record; the nightly catch-up retries and Ledger health counts what is unposted.
 *
 * @phpstan-import-type Line from Sources
 */
final class Poster
{
    /** model => the source types its saves may post (plan ruling 1) */
    public const array WATCH = [
        Invoice::class => ['invoice'],
        Payment::class => ['payment', 'payment_reversal'],
        PaymentAllocation::class => ['allocation'],
    ];

    /** Called once from AppServiceProvider::boot(). */
    public static function observe(): void
    {
        foreach (self::WATCH as $model => $types) {
            $model::saved(fn (Model $record) => self::queue($types, (int) $record->getKey()));
        }
    }

    /** @param  list<string>  $types */
    public static function queue(array $types, int $id): void
    {
        DB::afterCommit(function () use ($types, $id) {
            foreach ($types as $type) {
                try {
                    app(self::class)->post($type, $id);
                } catch (Throwable $e) {
                    report($e); // spec §8: the money record stands; catch-up retries and Ledger health shows the gap
                }
            }
        });
    }

    /** Posts one source when it is eligible and has no journal yet. Idempotent. */
    public function post(string $type, int $id): ?Journal
    {
        $start = LedgerSetting::current()->start_date?->toDateString();
        $today = now('Asia/Bahrain')->toDateString();
        // Lock-free pre-check: most saves (drafts, allocated updates, a source already posted) can post nothing, so they never take the lock.
        // The same eligibility is re-checked under the lock below.
        if ($start === null || ! Sources::pending($type, $start, $today)->where('t.id', $id)->exists()) {
            return null;
        }

        return DB::transaction(function () use ($type, $id, $today) {
            // ponytail: one row lock serialises every posting, lock change and rebuild chunk; per-month locks if volume ever needs it.
            $settings = LedgerSetting::query()->lockForUpdate()->firstOrFail();
            $first = $settings->firstOpenDay();
            if ($settings->start_date === null || $first === null) {
                return null;
            }
            $row = Sources::pending($type, $settings->start_date->toDateString(), $today)->where('t.id', $id)->first();
            if ($row === null) {
                return null;
            }
            $own = (string) $row->own_date;
            $late = $own < $first->toDateString(); // spec §5: dated in a locked month, so it lands on the first open day
            $built = Sources::build($type, $id, Rules::load());

            return self::write('auto', $late ? $first->toDateString() : $own, $built['description'], $built['lines'], $type, $id, $late);
        }, attempts: 3);
    }

    /**
     * Posts every pending source: nightly, after a rebuild's deletions, after the start date moves. Returns how many it tried.
     *
     * @param  (callable(int): void)|null  $progress  called with the running count after each chunk
     */
    public function catchUp(?callable $progress = null): int
    {
        $start = LedgerSetting::current()->start_date?->toDateString();
        if ($start === null) {
            return 0;
        }
        $today = now('Asia/Bahrain')->toDateString();
        $done = 0;

        foreach (array_keys(Sources::TYPES) as $type) {
            $after = 0;
            while (($ids = Sources::pending($type, $start, $today)->where('t.id', '>', $after)->orderBy('t.id')->limit(200)->pluck('id'))->isNotEmpty()) {
                foreach ($ids as $id) {
                    $after = (int) $id;
                    try {
                        $this->post($type, $after);
                    } catch (Throwable $e) {
                        report($e); // still pending: Ledger health counts it
                    }
                    $done++;
                }
                if ($progress !== null) {
                    $progress($done);
                }
            }
        }

        return $done;
    }

    /** Sources due a journal that have none (Ledger health; a rebuild's progress total). */
    public static function pendingCount(): int
    {
        $start = LedgerSetting::current()->start_date?->toDateString();
        if ($start === null) {
            return 0;
        }
        $today = now('Asia/Bahrain')->toDateString();

        return array_sum(array_map(fn (string $type) => Sources::pending($type, $start, $today)->count(), array_keys(Sources::TYPES)));
    }

    /**
     * The only writer of journals. Refuses fewer than two non-zero lines, an unbalanced journal and an inactive account
     * (spec §3: a deactivated account takes no new postings; §4: every journal balances before it is saved).
     *
     * @param  list<Line>  $lines
     */
    public static function write(string $kind, string $date, string $description, array $lines, ?string $sourceType = null, ?int $sourceId = null,
        bool $late = false, ?int $reverses = null, ?int $createdBy = null): Journal
    {
        $lines = array_values(array_filter($lines, fn (array $l) => $l['amount'] !== 0));
        if (count($lines) < 2 || array_sum(array_column($lines, 'amount')) !== 0) {
            throw new LogicException("Journal \"{$description}\" does not balance.");
        }
        $accounts = array_values(array_unique(array_column($lines, 'account')));
        if (LedgerAccount::query()->whereKey($accounts)->where('active', true)->count() !== count($accounts)) {
            throw new LogicException("Journal \"{$description}\" uses an inactive account.");
        }

        $journal = (new Journal)->forceFill([
            'date' => $date, 'kind' => $kind, 'source_type' => $sourceType, 'source_id' => $sourceId,
            'description' => mb_substr($description, 0, 500), 'late_posting' => $late, 'reverses_journal_id' => $reverses, 'created_by' => $createdBy,
        ]);
        $journal->save();

        foreach ($lines as $l) {
            JournalLine::query()->create([
                'journal_id' => $journal->id,
                'ledger_account_id' => $l['account'],
                'debit' => Fils::toDecimal(max(0, $l['amount'])),
                'credit' => Fils::toDecimal(max(0, -$l['amount'])),
                'customer_id' => $l['customer_id'],
                'owner_id' => $l['owner_id'],
                'building_id' => $l['building_id'],
                'unit_id' => $l['unit_id'],
                'memo' => $l['memo'],
            ]);
        }

        return $journal;
    }
}
