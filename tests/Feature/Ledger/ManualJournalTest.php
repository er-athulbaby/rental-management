<?php

use App\Actions\Ledger\PostManualJournal;
use App\Actions\Ledger\ReverseManualJournal;
use App\Livewire\Ledger\JournalShow;
use App\Livewire\Ledger\NewJournal;
use App\Models\Journal;
use App\Models\LedgerAccount;
use App\Models\LedgerSetting;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->f = ledgerFixture();
});

afterEach(function () {
    DB::table('ledger_settings')->update(['start_date' => null, 'locked_until' => null]);
});

/** One manual journal line as the form sends it. */
function manualLine(string $code, string $debit = '', string $credit = '', string $memo = ''): array
{
    return ['account_id' => (string) LedgerAccount::query()->where('code', $code)->value('id'), 'debit' => $debit, 'credit' => $credit, 'memo' => $memo];
}

test('Finance posts a balanced manual journal on an open date; it is audited and never edited', function () {
    $f = $this->f;
    $journal = app(PostManualJournal::class)->handle($f['finance'], ['date' => '2026-10-19', 'description' => 'Bank charges October',
        'lines' => [manualLine('5190', '2.500', '', 'NBB fee'), manualLine('1010', '', '2.500')]]);

    expect($journal->kind)->toBe('manual')
        ->and($journal->created_by)->toBe($f['finance']->id)
        ->and(linesOf($journal))->toBe(['5190 2.500 0.000', '1010 0.000 2.500'])
        ->and($journal->lines()->orderBy('id')->value('memo'))->toBe('NBB fee')
        ->and(Activity::query()->where('event', 'ledger.journal_posted')->sole()->causer_id)->toBe($f['finance']->id)
        ->and(fn () => DB::table('journals')->where('id', $journal->id)->update(['description' => 'Changed']))->toThrow(QueryException::class)
        ->and(fn () => app(PostManualJournal::class)->handle($f['admin'], ['date' => '2026-10-19', 'description' => 'x', 'lines' => []]))->toThrow(AuthorizationException::class);
});

test('a manual journal must balance, have two lines and a description, and use an open date', function (array $lines, array $over, string $error) {
    LedgerSetting::current()->forceFill(['locked_until' => '2026-10-05'])->save();
    $data = ['date' => '2026-10-19', 'description' => 'Test', 'lines' => array_map(fn (array $l) => manualLine(...$l), $lines), ...$over];

    expect(fn () => app(PostManualJournal::class)->handle($this->f['finance'], $data))->toThrow(ValidationException::class, $error);
})->with([
    'unbalanced' => [[['5190', '1.000'], ['1010', '', '2.000']], [], 'does not balance'],
    'one line' => [[['5190', '1.000']], [], 'at least two lines'],
    'both sides on a line' => [[['5190', '1.000', '1.000'], ['1010', '', '1.000']], [], 'debit or a credit'],
    'no description' => [[['5190', '1.000'], ['1010', '', '1.000']], ['description' => ''], 'description'],
    'locked date' => [[['5190', '1.000'], ['1010', '', '1.000']], ['date' => '2026-10-05'], 'on or after 06/10/2026'],
    'future date' => [[['5190', '1.000'], ['1010', '', '1.000']], ['date' => '2026-10-21'], 'date'],
    'tampered lines' => [[], ['lines' => 'oops'], 'lines'],
    'four decimals' => [[['5190', '1.0001'], ['1010', '', '1.0001']], [], 'debit'],
    'huge amount' => [[['5190', '99999999999999999999'], ['1010', '', '99999999999999999999']], [], 'debit'],
    'array amount' => [[['5190', '1.000'], ['1010', '', '1.000']], ['lines' => [['account_id' => ['x'], 'debit' => ['1'], 'credit' => '', 'memo' => '']]], 'lines'],
]);

test('an inactive account takes no manual postings', function () {
    LedgerAccount::query()->where('code', '3100')->update(['active' => false]);

    expect(fn () => app(PostManualJournal::class)->handle($this->f['finance'], ['date' => '2026-10-19', 'description' => 'Test',
        'lines' => [manualLine('3100', '1.000'), manualLine('1010', '', '1.000')]]))->toThrow(ValidationException::class, 'active account');
});

test('a manual journal is reversed once, by its exact opposite; a reversal and an automatic journal never', function () {
    $f = $this->f;
    $journal = app(PostManualJournal::class)->handle($f['finance'], ['date' => '2026-10-19', 'description' => 'Bank charges',
        'lines' => [manualLine('5190', '2.500'), manualLine('1010', '', '2.500')]]);
    $reversal = app(ReverseManualJournal::class)->handle($f['finance'], $journal, '2026-10-20');

    expect(linesOf($reversal))->toBe(['5190 0.000 2.500', '1010 2.500 0.000'])
        ->and($reversal->reverses_journal_id)->toBe($journal->id)
        ->and($reversal->date->toDateString())->toBe('2026-10-20')
        ->and(Activity::query()->where('event', 'ledger.journal_reversed')->sole()->subject_id)->toBe($journal->id)
        ->and(fn () => app(ReverseManualJournal::class)->handle($f['finance'], $journal, '2026-10-20'))->toThrow(ValidationException::class, 'already reversed')
        ->and(fn () => app(ReverseManualJournal::class)->handle($f['finance'], $reversal, '2026-10-20'))->toThrow(ValidationException::class)
        ->and(fn () => app(ReverseManualJournal::class)->handle($f['finance'], journalFor('expense', $f['companyExpense']->id), '2026-10-20'))->toThrow(ValidationException::class);
});

test('only a manual journal is reversed by hand: automatic and opening journals are refused and no reversal row is written', function () {
    $f = $this->f;
    $auto = journalFor('invoice', $f['saraRent']->id);
    $opening = Journal::query()->where('kind', 'opening')->first() ?? Journal::query()->where('kind', 'auto')->orderByDesc('id')->first();
    $before = Journal::query()->count();

    foreach ([$auto, $opening] as $journal) {
        expect(fn () => app(ReverseManualJournal::class)->handle($f['finance'], $journal, '2026-10-20'))->toThrow(ValidationException::class, 'Only a manual journal');
    }
    expect(Journal::query()->count())->toBe($before)
        ->and(Journal::query()->whereNotNull('reverses_journal_id')->exists())->toBeFalse()
        ->and(fn () => app(ReverseManualJournal::class)->handle($f['finance'], Journal::query()->where('kind', 'auto')->first(), '2026-10-04'))->toThrow(ValidationException::class);
});

test('a reversal is dated in an open period', function () {
    $f = $this->f;
    $journal = app(PostManualJournal::class)->handle($f['finance'], ['date' => '2026-10-19', 'description' => 'Bank charges',
        'lines' => [manualLine('5190', '2.500'), manualLine('1010', '', '2.500')]]);
    LedgerSetting::current()->forceFill(['locked_until' => '2026-10-19'])->save();

    expect(fn () => app(ReverseManualJournal::class)->handle($f['finance'], $journal, '2026-10-19'))->toThrow(ValidationException::class, 'on or after 20/10/2026')
        ->and(fn () => app(ReverseManualJournal::class)->handle($f['finance'], $journal, '2026-10-21'))->toThrow(ValidationException::class)
        ->and(fn () => app(ReverseManualJournal::class)->handle($f['admin'], $journal, '2026-10-20'))->toThrow(AuthorizationException::class);
});

test('the new-journal page posts and opens the journal; the journal page links to its source and reverses', function () {
    $f = $this->f;
    $page = Livewire::actingAs($f['finance'])->test(NewJournal::class)
        ->set('description', 'Petty cash top-up')
        ->set('lines.0', manualLine('1000', '20.000'))
        ->set('lines.1', manualLine('1010', '', '20.000'))
        ->call('addLine')
        ->call('save')->assertHasNoErrors();
    $journal = Journal::query()->where('kind', 'manual')->sole();
    $page->assertRedirect(route('ledger.journals.show', $journal->id));

    Livewire::actingAs($f['finance'])->test(JournalShow::class, ['journal' => (string) $journal->id])
        ->assertSee('Petty cash top-up')->call('reverse')->assertHasNoErrors();
    expect(Journal::query()->where('reverses_journal_id', $journal->id)->exists())->toBeTrue();

    $expense = journalFor('expense', $f['companyExpense']->id);
    Livewire::actingAs($f['admin'])->test(JournalShow::class, ['journal' => (string) $expense->id])
        ->assertSeeHtml(route('expenses.show', $f['companyExpense']->id))->assertDontSee('Reversal date');
    Livewire::actingAs($f['finance'])->test(JournalShow::class, ['journal' => '999999999'])->assertNotFound();
    Livewire::actingAs($f['finance'])->test(NewJournal::class)->set('lines', 'oops')->call('save')->assertHasErrors('lines');
    Livewire::actingAs($f['finance'])->test(NewJournal::class)->call('removeLine', ['x'])->assertHasNoErrors();
    Livewire::actingAs($f['admin'])->test(NewJournal::class)->assertForbidden();
});

test('tampered input on the pages gives errors, never a 500, and long text is escaped', function () {
    $f = $this->f;
    Livewire::actingAs($f['finance'])->test(NewJournal::class)
        ->set('description', ['x'])->set('date', ['y'])->set('lines', [['account_id' => ['1'], 'debit' => str_repeat('9', 40), 'credit' => '1.0001', 'memo' => ['m']], 'junk'])
        ->call('save')->assertHasErrors();
    Livewire::actingAs($f['finance'])->test(NewJournal::class)->call('removeLine', 99999999999)->assertHasNoErrors();

    $journal = app(PostManualJournal::class)->handle($f['finance'], ['date' => '2026-10-19', 'description' => '<script>alert(1)</script>',
        'lines' => [manualLine('5190', '1.000', '', '<b>memo</b>'), manualLine('1010', '', '1.000')]]);
    Livewire::actingAs($f['finance'])->test(JournalShow::class, ['journal' => (string) $journal->id])
        ->set('reverseDate', ['z'])->call('reverse')->assertHasErrors('reverseDate')
        ->assertDontSeeHtml('<script>alert(1)</script>')->assertDontSeeHtml('<b>memo</b>')
        ->assertSee('Journal '.$journal->id);
    Livewire::actingAs($f['finance'])->test(JournalShow::class, ['journal' => 'abc'])->assertNotFound();
    Livewire::actingAs($f['management'])->test(JournalShow::class, ['journal' => (string) $journal->id])->assertForbidden();
});

test('an account deactivated after posting, or during posting, gives a message and writes nothing', function () {
    $f = $this->f;
    $journal = app(PostManualJournal::class)->handle($f['finance'], ['date' => '2026-10-19', 'description' => 'Bank charges',
        'lines' => [manualLine('5190', '2.500'), manualLine('1010', '', '2.500')]]);
    LedgerAccount::query()->where('code', '5190')->update(['active' => false]);
    $count = Journal::query()->count();

    expect(fn () => app(ReverseManualJournal::class)->handle($f['finance'], $journal, '2026-10-20'))->toThrow(ValidationException::class, 'no longer active')
        ->and(Journal::query()->count())->toBe($count);

    // The race: the account goes inactive after the first check, once the settings row is read under its lock.
    LedgerAccount::query()->where('code', '5190')->update(['active' => true]);
    $fired = false;
    LedgerSetting::retrieved(function () use (&$fired) {
        if (! $fired) {
            $fired = true;
            LedgerAccount::query()->where('code', '5190')->update(['active' => false]);
        }
    });
    expect(fn () => app(PostManualJournal::class)->handle($f['finance'], ['date' => '2026-10-19', 'description' => 'Race',
        'lines' => [manualLine('5190', '1.000'), manualLine('1010', '', '1.000')]]))->toThrow(ValidationException::class, 'no longer active')
        ->and(Journal::query()->count())->toBe($count);
});
