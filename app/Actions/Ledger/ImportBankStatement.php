<?php

namespace App\Actions\Ledger;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Models\BankMapping;
use App\Models\BankUpload;
use App\Models\LedgerAccount;
use App\Models\LedgerSetting;
use App\Models\User;
use App\Support\Fils;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Spatie\SimpleExcel\SimpleExcelReader;
use Throwable;

/**
 * Bank reconciliation spec §2 (plan ruling 7): Finance uploads a CSV or XLSX statement for a money account. The column
 * mapping is saved for the account. Each row becomes an immutable statement line; a row already on the account (same date,
 * amount, description and occurrence within its file) is skipped; a bad row is reported with its reason and the rest
 * import. Cells are read as values, never formulas, and stored as plain text.
 *
 * @phpstan-type Mapping array{header_row: int, date_column: int, date_format: string, description_column: int, reference_column: int|null,
 *     amount_mode: string, amount_column: int|null, debit_column: int|null, credit_column: int|null, balance_column: int|null}
 */
final class ImportBankStatement
{
    public const int MAX_ROWS = 5000;

    /** PHP format => what the page shows. "d/m/Y" reads 05/10/2026 and 5/10/2026 alike (plan ruling 7). */
    public const array DATE_FORMATS = ['d/m/Y' => 'dd/mm/yyyy', 'Y-m-d' => 'yyyy-mm-dd', 'd-M-Y' => 'dd-Mon-yyyy'];

    /** Raw rows read, blank ones included, before the file is refused. */
    private const int MAX_RAW_ROWS = 50_000;

    /** The largest |amount| decimal(13,3) holds, in fils. */
    private const int MAX_FILS = 9_999_999_999_999;

    /** The largest |balance| decimal(15,3) holds, in fils. */
    private const int MAX_BALANCE_FILS = 999_999_999_999_999;

    /**
     * @param  mixed  $mapping  header_row, date_column, date_format, description_column, reference_column, amount_mode, amount_column, debit_column, credit_column, balance_column
     * @return array{upload: BankUpload, failures: list<array{row: int, reason: string}>}
     */
    public function handle(User $actor, mixed $accountId, string $path, string $type, string $fileName, mixed $mapping): array
    {
        if (! $actor->can(PermissionName::LedgerManage)) {
            throw new AuthorizationException;
        }
        $id = is_int($accountId) || (is_string($accountId) && ctype_digit($accountId) && strlen($accountId) < 19) ? (int) $accountId : 0;
        $account = LedgerAccount::query()->whereKey($id)->where('is_money', true)->where('active', true)->first()
            ?? throw ValidationException::withMessages(['account' => __('Choose an active money account.')]);
        $map = self::mapping($mapping);
        if (! in_array($type, ['csv', 'xlsx'], true)) {
            throw ValidationException::withMessages(['file' => __('Upload the statement as a .csv or .xlsx file.')]);
        }

        $rows = self::read($path, $type, $map['header_row']);
        if ($rows === []) {
            throw ValidationException::withMessages(['file' => __('The statement has no rows.')]);
        }
        $parsed = [];
        $failures = [];
        $start = LedgerSetting::current()->start_date?->toDateString(); // read before the account lock: not a locked check
        foreach ($rows as $row => $cells) {
            try {
                $one = self::parse($cells, $map);
                if ($start !== null && $one['date'] < $start) {
                    throw new InvalidArgumentException(__('Dated before the ledger start date (:date); not imported.', ['date' => CarbonImmutable::parse($start)->format('d/m/Y')]));
                }
                $parsed[] = ['row' => $row, ...$one];
            } catch (InvalidArgumentException $e) {
                $failures[] = ['row' => $row, 'reason' => $e->getMessage()];
            }
        }

        return DB::transaction(function () use ($actor, $account, $map, $fileName, $parsed, $failures) {
            LedgerAccount::query()->lockForUpdate()->findOrFail($account->id); // plan ruling 10: duplicate checks of two uploads see each other
            BankMapping::query()->firstOrNew(['ledger_account_id' => $account->id])->forceFill([...$map, 'updated_by' => $actor->id])->save();
            $upload = (new BankUpload)->forceFill(['ledger_account_id' => $account->id, 'file_name' => mb_substr(mb_scrub($fileName, 'UTF-8'), 0, 255), 'imported' => 0, 'skipped' => 0,
                'failed' => count($failures), 'uploaded_by' => $actor->id]);
            $upload->save();

            $seen = [];
            $imported = 0;
            foreach ($parsed as $p) {
                $amount = Fils::toDecimal($p['amount']);
                $hash = hash('sha256', mb_strtolower($p['description']));
                $same = $p['date'].'|'.$amount.'|'.$hash;
                $seen[$same] = ($seen[$same] ?? 0) + 1;
                $key = ['ledger_account_id' => $account->id, 'date' => $p['date'], 'amount' => $amount, 'description_hash' => $hash, 'occurrence' => $seen[$same]];
                if (DB::table('bank_statement_lines')->where($key)->exists()) {
                    continue;
                }
                DB::table('bank_statement_lines')->insert([...$key, 'bank_upload_id' => $upload->id, 'row_number' => $p['row'], 'description' => $p['description'],
                    'reference' => $p['reference'], 'balance' => $p['balance'] === null ? null : Fils::toDecimal($p['balance']), 'created_at' => now()]);
                $imported++;
            }
            $upload->forceFill(['imported' => $imported, 'skipped' => count($parsed) - $imported])->save();
            Audit::log('bank.statement_uploaded', $upload, new: ['account' => $account->code, 'file' => $upload->file_name, 'imported' => $imported,
                'skipped' => count($parsed) - $imported, 'failed' => count($failures)], causer: $actor);

            return ['upload' => $upload, 'failures' => $failures];
        });
    }

    /** @return Mapping */
    private static function mapping(mixed $mapping): array
    {
        $m = is_array($mapping) ? $mapping : [];
        $split = ($m['amount_mode'] ?? null) === 'split';
        $column = ['nullable', 'integer', 'between:1,26'];
        $v = Validator::make($m, [
            'header_row' => ['required', 'integer', 'between:0,50'],
            'date_column' => ['required', 'integer', 'between:1,26'],
            'date_format' => ['required', 'string', Rule::in(array_keys(self::DATE_FORMATS))],
            'description_column' => ['required', 'integer', 'between:1,26'],
            'reference_column' => $column,
            'amount_mode' => ['required', 'string', Rule::in(['signed', 'split'])],
            'amount_column' => [Rule::requiredIf(! $split), ...$column],
            'debit_column' => [Rule::requiredIf($split), ...$column],
            'credit_column' => [Rule::requiredIf($split), ...$column],
            'balance_column' => $column,
        ])->validate();
        $int = fn (string $key): ?int => isset($v[$key]) && $v[$key] !== '' ? (int) $v[$key] : null;
        $map = [
            'header_row' => (int) $v['header_row'],
            'date_column' => (int) $v['date_column'],
            'date_format' => (string) $v['date_format'],
            'description_column' => (int) $v['description_column'],
            'reference_column' => $int('reference_column'),
            'amount_mode' => $split ? 'split' : 'signed',
            'amount_column' => $split ? null : $int('amount_column'),
            'debit_column' => $split ? $int('debit_column') : null,
            'credit_column' => $split ? $int('credit_column') : null,
            'balance_column' => $int('balance_column'),
        ];
        $used = array_values(array_filter([$map['date_column'], $map['description_column'], $map['reference_column'], $map['amount_column'],
            $map['debit_column'], $map['credit_column'], $map['balance_column']]));
        if (count($used) !== count(array_unique($used))) {
            throw ValidationException::withMessages(['date_column' => __('Use each column for one thing only.')]);
        }

        return $map;
    }

    /** @return array<int, list<mixed>> line number in the file => cells, after the header row; blank rows left out */
    private static function read(string $path, string $type, int $headerRow): array
    {
        $rows = [];
        $tooMany = false;
        $raw = 0;
        try {
            $reader = SimpleExcelReader::create($path, $type)->noHeaderRow()->preserveEmptyRows();
            if ($type === 'csv') {
                $reader->keepFormulas(); // OpenSpout reads a CSV cell starting with "=" as a formula with no computed value; keep its text. Never evaluated.
            }
            foreach ($reader->getRows() as $index => $cells) {
                $line = (int) $index + 1;
                if (++$raw > self::MAX_RAW_ROWS) {
                    $tooMany = true;
                    break;
                }
                $filled = array_filter($cells, fn (mixed $c) => ! ($c === null || (is_string($c) && trim($c) === '')));
                if ($line <= $headerRow || $filled === []) {
                    continue;
                }
                if (count($rows) === self::MAX_ROWS) {
                    $tooMany = true;
                    break;
                }
                $rows[$line] = array_values($cells);
            }
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => __('This file cannot be read as .xlsx or .csv.')]);
        }
        if ($tooMany) {
            throw ValidationException::withMessages(['file' => __('The statement has more than :n rows. Split it into smaller files.', ['n' => self::MAX_ROWS])]);
        }

        return $rows;
    }

    /**
     * @param  list<mixed>  $cells
     * @param  Mapping  $map
     * @return array{date: string, description: string, reference: string|null, amount: int, balance: int|null}
     *
     * @throws InvalidArgumentException with the reason shown for the row
     */
    private static function parse(array $cells, array $map): array
    {
        $cell = fn (?int $column): mixed => $column === null ? null : ($cells[$column - 1] ?? null);
        $date = self::date($cell($map['date_column']), $map['date_format'])
            ?? throw new InvalidArgumentException(__('The date is not a :format date.', ['format' => self::DATE_FORMATS[$map['date_format']]]));
        if ($map['amount_mode'] === 'split') {
            $debit = self::fils($cell($map['debit_column'])) ?? 0;
            $credit = self::fils($cell($map['credit_column'])) ?? 0;
            if ($debit !== 0 && $credit !== 0) {
                throw new InvalidArgumentException(__('Fill either the debit or the credit, not both.'));
            }
            $amount = abs($credit) - abs($debit);
        } else {
            $amount = self::fils($cell($map['amount_column'])) ?? 0;
        }
        if ($amount === 0) {
            throw new InvalidArgumentException(__('The amount is empty or zero.'));
        }

        return [
            'date' => $date,
            'description' => self::text($cell($map['description_column']), 500),
            'reference' => self::text($cell($map['reference_column']), 100) ?: null,
            'amount' => $amount,
            'balance' => self::fils($cell($map['balance_column']), __('The balance is not a number with up to 3 decimals.'), self::MAX_BALANCE_FILS),
        ];
    }

    /** Y-m-d, or null. An XLSX date cell is used as it is; text must match $format exactly (31/02 fails), year 2000–2099. */
    private static function date(mixed $value, string $format): ?string
    {
        if ($value instanceof DateTimeInterface) {
            $date = DateTimeImmutable::createFromInterface($value);
        } elseif (is_string($value) && trim($value) !== '') {
            $date = DateTimeImmutable::createFromFormat('!'.$format, trim($value));
            $errors = DateTimeImmutable::getLastErrors();
            if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                return null;
            }
        } else {
            return null;
        }
        $year = (int) $date->format('Y');

        return $year >= 2000 && $year <= 2099 ? $date->format('Y-m-d') : null;
    }

    /**
     * Fils from a cell, or null when it is empty. Takes 1,250.500, (300.000), +5, -2.5 and XLSX numbers.
     *
     * @throws InvalidArgumentException when the cell is not an amount with at most 3 decimals
     */
    private static function fils(mixed $value, ?string $reason = null, int $max = self::MAX_FILS): ?int
    {
        $reason ??= __('The amount is not a number with up to 3 decimals.');
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            // A float with binary noise (0.1 + 0.2) is accepted when within 1e-9 of its 3-decimal rounding; an exponent or huge value stays refused.
            if (is_float($value) && (! is_finite($value) || abs($value) > $max / 1000 + 1 || abs(round($value, 3) - $value) > 1e-9 * max(1, abs($value)))) {
                throw new InvalidArgumentException($reason);
            }
            $text = is_float($value) ? number_format(round($value, 3), 3, '.', '') : (string) $value;
            $negative = false;
        } elseif (is_string($value)) {
            $text = str_replace([',', ' ', "\u{00A0}"], '', trim($value));
            $negative = false;
            if (preg_match('/^\((.+)\)$/', $text, $m) === 1) {
                $negative = true;
                $text = $m[1];
                if (in_array($text[0], ['+', '-'], true)) {
                    throw new InvalidArgumentException($reason);
                }
            }
        } else {
            throw new InvalidArgumentException($reason);
        }
        try {
            $fils = Fils::fromDecimal(ltrim($text, '+')); // an exponent such as 1.0E+25 does not match, so it is refused here
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException($reason);
        }
        if (abs($fils) > $max) {
            throw new InvalidArgumentException($reason);
        }

        return $negative ? -$fils : $fils;
    }

    /** A cell as plain text, whitespace collapsed, at most $max characters. Never a formula: it is only ever stored and shown as text. */
    private static function text(mixed $value, int $max): string
    {
        $text = match (true) {
            $value instanceof DateTimeInterface => $value->format('d/m/Y'),
            is_scalar($value) => (string) $value,
            default => '',
        };

        $clean = preg_replace('/\s+/u', ' ', $text);
        if ($clean === null) {
            throw new InvalidArgumentException(__('The text is not valid UTF-8; save the file as UTF-8.'));
        }

        return mb_substr(trim($clean), 0, $max);
    }
}
