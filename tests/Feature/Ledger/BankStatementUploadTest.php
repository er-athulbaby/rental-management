<?php

use App\Actions\Ledger\DeleteBankUpload;
use App\Actions\Ledger\ImportBankStatement;
use App\Enums\RoleName;
use App\Models\BankMapping;
use App\Models\BankStatementLine;
use App\Models\BankUpload;
use App\Models\Building;
use App\Models\LedgerAccount;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use Spatie\SimpleExcel\SimpleExcelWriter;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $building = Building::factory()->create();
    $this->finance = matrixUser(RoleName::Finance, $building);
    $this->admin = matrixUser(RoleName::Admin, $building);
    $this->bank = (string) LedgerAccount::query()->where('code', '1010')->value('id');
    $this->lines = fn () => BankStatementLine::query()->orderBy('id')->get()
        ->map(fn (BankStatementLine $l) => [$l->row_number, $l->date->toDateString(), $l->description, $l->reference, $l->amount])->all();
});

test('a signed CSV becomes statement lines, and the mapping is saved for the account', function () {
    $out = bankStatement($this->finance, '1010', [
        ['05/10/2026', 'CASH DEPOSIT', 'D-1', '1,250.500'],
        ['6/10/2026', '  TRANSFER   OUT ', '', '(300)'],
        ['07/10/2026', 'CHARGES', '', '-2.5'],
    ]);

    expect([$out['upload']->imported, $out['upload']->skipped, $out['upload']->failed, $out['failures']])->toBe([3, 0, 0, []])
        ->and(($this->lines)())->toBe([
            [2, '2026-10-05', 'CASH DEPOSIT', 'D-1', '1250.500'],
            [3, '2026-10-06', 'TRANSFER OUT', null, '-300.000'],
            [4, '2026-10-07', 'CHARGES', null, '-2.500'],
        ])
        ->and(BankMapping::query()->sole()->only(['ledger_account_id', 'header_row', 'date_column', 'date_format', 'description_column', 'reference_column',
            'amount_mode', 'amount_column', 'debit_column', 'credit_column', 'balance_column']))
        ->toBe(['ledger_account_id' => (int) $this->bank, 'header_row' => 1, 'date_column' => 1, 'date_format' => 'd/m/Y', 'description_column' => 2,
            'reference_column' => 3, 'amount_mode' => 'signed', 'amount_column' => 4, 'debit_column' => null, 'credit_column' => null, 'balance_column' => null]);
});

test('debit and credit columns, the other date formats, and an XLSX with a real date and numbers', function () {
    $import = fn (string $path, string $type, array $mapping) => app(ImportBankStatement::class)->handle($this->finance, $this->bank, $path, $type, "s.{$type}", $mapping);
    $import(bankCsv([['2026-10-05', 'IN', '', '', '100.000'], ['2026-10-06', 'OUT', '', '40', '']], ['Date', 'Description', 'Ref', 'Debit', 'Credit']), 'csv',
        bankMapping(['date_format' => 'Y-m-d', 'amount_mode' => 'split', 'amount_column' => null, 'debit_column' => 4, 'credit_column' => 5]));
    $import(bankCsv([['07-Oct-2026', 'MON', '', '5']]), 'csv', bankMapping(['date_format' => 'd-M-Y']));
    $xlsx = sys_get_temp_dir().'/bank-'.Str::uuid().'.xlsx';
    $writer = SimpleExcelWriter::create($xlsx);
    $writer->addRow(new Row([Cell::fromValue('Statement of account')]));
    $writer->addRow(new Row([Cell::fromValue('Date'), Cell::fromValue('Description'), Cell::fromValue('Reference'), Cell::fromValue('Amount')]));
    $writer->addRow(new Row([Cell::fromValue(new DateTimeImmutable('2026-10-08'), (new Style)->setFormat('dd/mm/yyyy')), Cell::fromValue('XLSX IN'),
        Cell::fromValue(12345), Cell::fromValue(99.5)]));
    $writer->close();
    $import($xlsx, 'xlsx', bankMapping(['header_row' => 2]));

    expect(($this->lines)())->toBe([
        [2, '2026-10-05', 'IN', null, '100.000'],
        [3, '2026-10-06', 'OUT', null, '-40.000'],
        [2, '2026-10-07', 'MON', null, '5.000'],
        [3, '2026-10-08', 'XLSX IN', '12345', '99.500'],
    ])->and(BankMapping::query()->sole()->amount_mode)->toBe('signed');
});

test('a line already on the account is skipped; identical lines in one file are kept as separate occurrences', function () {
    $rows = [['05/10/2026', 'SMS FEE', '', '-0.500'], ['05/10/2026', 'SMS FEE', '', '-0.500'], ['06/10/2026', 'DEPOSIT', '', '20']];
    $first = bankStatement($this->finance, '1010', $rows);
    $again = bankStatement($this->finance, '1010', $rows);
    $overlap = bankStatement($this->finance, '1010', [...$rows, ['07/10/2026', 'DEPOSIT', '', '20'], ['05/10/2026', 'sms  fee', '', '-0.500']]);
    $cash = bankStatement($this->finance, '1000', $rows);

    expect([$first['upload']->imported, $first['upload']->skipped])->toBe([3, 0])
        ->and([$again['upload']->imported, $again['upload']->skipped])->toBe([0, 3])
        ->and([$overlap['upload']->imported, $overlap['upload']->skipped])->toBe([2, 3])
        ->and([$cash['upload']->imported, $cash['upload']->skipped])->toBe([3, 0])
        ->and(BankStatementLine::query()->where('ledger_account_id', (int) $this->bank)->count())->toBe(5);
});

test('bad rows are reported with their reason and the rest import', function () {
    $out = bankStatement($this->finance, '1010', [
        ['31/02/2026', 'BAD DATE', '', '5'],
        ['2026-10-05', 'WRONG FORMAT', '', '5'],
        ['05/10/2026', 'BAD AMOUNT', '', 'abc'],
        ['05/10/2026', 'FOUR DECIMALS', '', '1.2345'],
        ['05/10/2026', 'ZERO', '', '0.000'],
        ['', '', '', ''],
        ['05/10/2026', 'GOOD', '', '7'],
    ]);
    $split = bankStatement($this->finance, '1000', [['05/10/2026', 'BOTH', '3', '4']],
        ['amount_mode' => 'split', 'amount_column' => null, 'reference_column' => null, 'debit_column' => 3, 'credit_column' => 4]);

    expect($out['failures'])->toBe([
        ['row' => 2, 'reason' => 'The date is not a dd/mm/yyyy date.'],
        ['row' => 3, 'reason' => 'The date is not a dd/mm/yyyy date.'],
        ['row' => 4, 'reason' => 'The amount is not a number with up to 3 decimals.'],
        ['row' => 5, 'reason' => 'The amount is not a number with up to 3 decimals.'],
        ['row' => 6, 'reason' => 'The amount is empty or zero.'],
    ])->and([$out['upload']->imported, $out['upload']->failed])->toBe([1, 5])
        ->and(bankLine('GOOD')->row_number)->toBe(8)
        ->and($split['failures'])->toBe([['row' => 2, 'reason' => 'Fill either the debit or the credit, not both.']]);
});

test('over 5000 rows, an unreadable file, another type, a column used twice and a non-money account are refused, saving nothing', function () {
    $junk = sys_get_temp_dir().'/bank-'.Str::uuid().'.xlsx';
    file_put_contents($junk, 'not a spreadsheet');
    $import = fn (string $type) => app(ImportBankStatement::class)->handle($this->finance, $this->bank, $junk, $type, "junk.{$type}", bankMapping());

    expect(fn () => bankStatement($this->finance, '1010', array_fill(0, 5001, ['05/10/2026', 'X', '', '1'])))
        ->toThrow(ValidationException::class, 'The statement has more than 5000 rows. Split it into smaller files.')
        ->and(fn () => $import('xlsx'))->toThrow(ValidationException::class, 'This file cannot be read as .xlsx or .csv.')
        ->and(fn () => $import('pdf'))->toThrow(ValidationException::class, 'Upload the statement as a .csv or .xlsx file.')
        ->and(fn () => bankStatement($this->finance, '1010', [], ['description_column' => 1]))->toThrow(ValidationException::class, 'Use each column for one thing only.')
        ->and(fn () => bankStatement($this->finance, '1010', [], ['date_column' => ['x'], 'amount_mode' => 'other']))->toThrow(ValidationException::class)
        ->and(fn () => bankStatement($this->finance, '4000', []))->toThrow(ValidationException::class, 'Choose an active money account.')
        ->and(BankUpload::query()->count() + BankStatementLine::query()->count() + BankMapping::query()->count())->toBe(0);
    unlink($junk);
});

test('cells that look like formulas are stored as plain text', function () {
    bankStatement($this->finance, '1010', [['05/10/2026', '=HYPERLINK("http://x.test","pay")', '+973', '5'], ['05/10/2026', '@SUM(A1)', '-1', '6']]);

    expect(BankStatementLine::query()->orderBy('id')->get(['description', 'reference'])->toArray())->toBe([
        ['description' => '=HYPERLINK("http://x.test","pay")', 'reference' => '+973'],
        ['description' => '@SUM(A1)', 'reference' => '-1'],
    ]);
});

test('an upload is deleted with its lines only while none is matched; only Finance uploads and deletes', function () {
    $first = bankStatement($this->finance, '1010', [['05/10/2026', 'A', '', '5']]);
    $second = bankStatement($this->finance, '1010', [['06/10/2026', 'B', '', '6']]);
    $rec = DB::table('bank_reconciliations')->insertGetId(['ledger_account_id' => (int) $this->bank, 'end_date' => '2026-10-20', 'opening_balance' => '0.000',
        'closing_balance' => '5.000', 'status' => 'open', 'created_by' => $this->finance->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('bank_matches')->insert(['bank_reconciliation_id' => $rec, 'bank_statement_line_id' => bankLine('A')->id, 'ledger_account_id' => (int) $this->bank,
        'entry_type' => 'manual', 'entry_id' => 1, 'amount' => '5.000', 'created_by' => $this->finance->id]);

    expect(fn () => app(DeleteBankUpload::class)->handle($this->finance, $first['upload']))
        ->toThrow(ValidationException::class, 'Lines of this upload are matched. Remove those matches first.')
        ->and(fn () => app(DeleteBankUpload::class)->handle($this->admin, $second['upload']))->toThrow(AuthorizationException::class)
        ->and(fn () => bankStatement($this->admin, '1010', []))->toThrow(AuthorizationException::class);

    app(DeleteBankUpload::class)->handle($this->finance, $second['upload']);
    expect(BankUpload::query()->pluck('id')->all())->toBe([$first['upload']->id])
        ->and(BankStatementLine::query()->pluck('description')->all())->toBe(['A']);
});
