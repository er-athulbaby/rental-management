<?php

use App\Actions\Ledger\MatchBankLine;
use App\Actions\Ledger\PostManualJournal;
use App\Enums\RoleName;
use App\Livewire\Ledger\BankReconciliations;
use App\Livewire\Ledger\BankReconciliationShow;
use App\Models\BankMatch;
use App\Models\BankReconciliation;
use App\Models\BankUpload;
use App\Models\CashMovement;
use App\Models\LedgerAccount;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->f = bankRecFixture();
});

test('Finance uploads a statement from the page; the saved mapping comes back with the account', function () {
    $f = $this->f;
    $csv = "Posted,Narrative,Out,In\n2026-10-16,ATM CASH,25.000,\n2026-10-16,BAD,x,\n";

    Livewire::actingAs($f['finance'])->test(BankReconciliations::class)
        ->set('account', (string) $f['bank'])
        ->assertSet('amount_column', '4') // bankRecFixture()'s upload saved the CSV layout
        ->set('date_format', 'Y-m-d')->set('reference_column', '')->set('amount_mode', 'split')->set('amount_column', '')->set('debit_column', '3')->set('credit_column', '4')
        ->set('file', UploadedFile::fake()->createWithContent('october.csv', $csv))
        ->call('upload')->assertHasNoErrors()
        ->assertSee('Imported 1, skipped as duplicates 0, failed 1.')
        ->assertSee('Row 3: The amount is not a number with up to 3 decimals.');

    expect(bankLine('ATM CASH')->amount)->toBe('-25.000');
    Livewire::actingAs($f['finance'])->test(BankReconciliations::class)
        ->set('account', (string) $f['cash'])->assertSet('amount_mode', 'signed')->assertSet('amount_column', '3') // no mapping for Cash yet: the defaults
        ->set('account', (string) $f['bank'])->assertSet('amount_mode', 'split')->assertSet('debit_column', '3')->assertSet('reference_column', '');
});

test('the page refuses a file over 5 MB or of another type', function () {
    Livewire::actingAs($this->f['finance'])->test(BankReconciliations::class)->set('account', (string) $this->f['bank'])
        ->set('file', UploadedFile::fake()->create('big.csv', 5121))->call('upload')->assertHasErrors('file')
        ->set('file', UploadedFile::fake()->createWithContent('statement.pdf', 'x'))->call('upload')->assertHasErrors('file');

    expect(BankUpload::query()->count())->toBe(1); // the fixture's
});

test('an unmatched upload is deleted from the page; a matched one shows no delete button', function () {
    $f = $this->f;
    $extra = bankStatement($f['finance'], '1010', [['18/10/2026', 'EXTRA', '', '9']]);
    $first = BankUpload::query()->orderBy('id')->value('id');
    $rec = bankRec($f['finance'], '2026-10-20', '1198.700', '1000');
    app(MatchBankLine::class)->handle($f['finance'], $rec, (string) bankLine('TRANSFER '.$f['headLease']->number)->id, ['disbursement:'.$f['headLease']->id]);

    Livewire::actingAs($f['finance'])->test(BankReconciliations::class)->set('account', (string) $f['bank'])
        ->assertSeeHtml('wire:click="deleteUpload('.$extra['upload']->id.')"')
        ->assertDontSeeHtml('wire:click="deleteUpload('.$first.')"')
        ->call('deleteUpload', (string) $extra['upload']->id)->assertHasNoErrors()
        ->call('deleteUpload', 'x')->assertNotFound();

    expect(BankUpload::query()->pluck('id')->all())->toBe([$first]);
});

test('Finance reconciles on the page: start, suggestions, a many-to-one match, bank-only lines, finish and the report', function () {
    $f = $this->f;
    Livewire::actingAs($f['finance'])->test(BankReconciliations::class)->set('account', (string) $f['bank'])
        ->assertSee('Statement opening balance') // the first reconciliation of Bank asks for it
        ->set('end_date', '2026-10-20')->set('closing_balance', '1198.700')->set('opening_balance', '1000')
        ->call('start')->assertHasNoErrors()->assertRedirect(route('ledger.bank-reconciliation.show', BankReconciliation::query()->sole()->id));
    $rec = BankReconciliation::query()->sole();

    Livewire::actingAs($f['finance'])->test(BankReconciliationShow::class, ['reconciliation' => (string) $rec->id])
        ->assertSee('198.700') // the difference before matching
        ->call('pick', bankLine('TRANSFER '.$f['headLease']->number)->id)
        ->assertSeeHtml('value="disbursement:'.$f['headLease']->id.'"')
        ->set('picked', ['disbursement:'.$f['headLease']->id])->call('match')->assertHasNoErrors()
        ->call('pick', bankLine('CASH DEPOSIT DEP-1 DEP-2')->id)
        ->set('picked', ['cash_movement:'.$f['dep1']->id])->call('match')->assertHasErrors('picked')
        ->set('picked', ['cash_movement:'.$f['dep1']->id, 'cash_movement:'.$f['dep2']->id])->call('match')->assertHasNoErrors()
        ->call('pick', bankLine('BANK CHARGES OCT')->id)->set('kind', 'bank_charge')->call('bankOnly')->assertHasNoErrors()
        ->call('pick', bankLine('CREDIT INTEREST')->id)->set('kind', 'bank_interest')->call('bankOnly')->assertHasNoErrors()
        ->call('finish')->assertHasNoErrors()
        ->assertSee('Finished')->assertDontSeeHtml('wire:click="finish"')
        ->call('download')->assertFileDownloaded('bank-reconciliation-1010-2026-10-20.xlsx');

    expect($rec->fresh()->status)->toBe('finished')
        ->and(BankMatch::query()->count())->toBe(5)
        ->and(CashMovement::query()->where('reference', 'CHG-OCT')->sole()->kind->value)->toBe('bank_charge');
});

test('matches come off from the page; Admin reopens a finished reconciliation and never sees Finance\'s buttons', function () {
    $f = $this->f;
    $rec = bankRec($f['finance'], '2026-10-20', '1198.700', '1000');
    $line = bankLine('TRANSFER '.$f['headLease']->number)->id;
    app(MatchBankLine::class)->handle($f['finance'], $rec, (string) $line, ['disbursement:'.$f['headLease']->id]);

    Livewire::actingAs($f['finance'])->test(BankReconciliationShow::class, ['reconciliation' => (string) $rec->id])
        ->assertSeeHtml('wire:click="unmatch('.$line.')"')->call('unmatch', (string) $line)->assertHasNoErrors();
    expect(BankMatch::query()->count())->toBe(0);

    Livewire::actingAs($f['admin'])->test(BankReconciliationShow::class, ['reconciliation' => (string) $rec->id])
        ->assertDontSeeHtml('wire:click="finish"')->assertDontSeeHtml('wire:click="pick(')->assertDontSeeHtml('wire:click="reopen"')
        ->call('pick', (string) $line)->assertForbidden();

    $rec->forceFill(['status' => 'finished', 'finished_by' => $f['finance']->id, 'finished_at' => now()])->save();
    Livewire::actingAs($f['admin'])->test(BankReconciliationShow::class, ['reconciliation' => (string) $rec->id])
        ->call('reopen')->assertHasNoErrors();
    expect($rec->fresh()->status)->toBe('open');
});

test('tampered page input gets a message or a 404, never a 500', function () {
    $f = $this->f;
    $rec = bankRec($f['finance'], '2026-10-20', '1198.700', '1000');

    Livewire::actingAs($f['finance'])->test(BankReconciliationShow::class, ['reconciliation' => (string) $rec->id])
        ->call('pick', 'abc')->assertNotFound();
    Livewire::actingAs($f['finance'])->test(BankReconciliationShow::class, ['reconciliation' => (string) $rec->id])
        ->call('pick', (string) bankLine('CREDIT INTEREST')->id)
        ->set('picked', 'x')->call('match')->assertHasErrors('picked')
        ->set('picked', ['x' => ['y']])->set('search', ['z'])->call('match')->assertHasErrors('picked')
        ->set('kind', ['x'])->call('bankOnly')->assertHasErrors('kind')
        ->set('kind', 'transfer')->set('otherAccount', ['x'])->call('bankOnly')->assertHasErrors('from_account_id')
        ->call('unmatch', 'abc')->assertHasErrors('line');
    Livewire::actingAs($f['finance'])->test(BankReconciliationShow::class, ['reconciliation' => 'abc'])->assertNotFound();
    Livewire::actingAs($f['finance'])->test(BankReconciliations::class)
        ->set('account', 'abc')->assertOk()->assertDontSee('Upload a statement')
        ->set('account', (string) $f['bank'])->set('header_row', ['x'])->set('file', UploadedFile::fake()->createWithContent('s.csv', "a\n"))
        ->call('upload')->assertHasErrors('header_row')
        ->set('header_row', '1')->set('closing_balance', ['x'])->call('start')->assertHasErrors('closing_balance');
});

test('the report: the figures and both lists, every cell text, never a formula', function () {
    $f = $this->f;
    app(PostManualJournal::class)->handle($f['finance'], ['date' => '2026-10-18', 'description' => '=1+2', 'lines' => [
        ['account_id' => (int) LedgerAccount::query()->where('code', '5100')->value('id'), 'debit' => '1'], ['account_id' => $f['bank'], 'credit' => '1']]]);
    $rec = bankRec($f['finance'], '2026-10-20', '1198.700', '1000');
    $path = BankReconciliationShow::reportFile($rec);
    $zip = new ZipArchive;
    $zip->open($path);
    $xml = '';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        if (str_starts_with((string) $zip->getNameIndex($i), 'xl/worksheets/')) {
            $xml .= $zip->getFromIndex($i);
        }
    }
    $zip->close();
    preg_match_all('#<t[^>]*>(.*?)</t>#', $xml, $texts);

    expect(preg_match_all('/<f[ >]/', $xml))->toBe(0)
        ->and(array_map(fn (string $s) => html_entity_decode($s, ENT_QUOTES), $texts[1]))->toContain('=1+2')
        ->and(BankReconciliationShow::reportRows($rec)[0])->toBe(['Bank reconciliation', '1010 Bank', '20/10/2026', 'Open'])
        ->and(collect(BankReconciliationShow::reportRows($rec))->firstWhere(0, '18/10/2026'))->toBe(['18/10/2026', 'Manual journal', '=1+2', '-1.000']);
    unlink($path);
});

test('Finance and Admin reach the bank reconciliation pages; nobody else; a bad id is a 404', function () {
    $f = $this->f;
    $rec = bankRec($f['finance'], '2026-10-20', '1198.700', '1000');
    $leasing = matrixUser(RoleName::Leasing, $f['tower']);

    foreach ([route('ledger.bank-reconciliation'), route('ledger.bank-reconciliation.show', $rec->id)] as $url) {
        $this->actingAs($f['management'])->get($url)->assertForbidden();
        $this->actingAs($leasing)->get($url)->assertForbidden();
        $this->actingAs($f['finance'])->get($url)->assertOk();
        $this->actingAs($f['admin'])->get($url)->assertOk();
    }
    $this->actingAs($f['finance'])->get('/ledger/bank-reconciliation/abc')->assertNotFound();
    $this->actingAs($f['finance'])->get('/ledger/bank-reconciliation/999999')->assertNotFound();
    $this->actingAs($f['admin'])->get(route('ledger.bank-reconciliation', ['account' => (string) $f['bank']]))->assertOk()->assertDontSee('Upload a statement');
    $this->actingAs($f['finance'])->get(route('ledger.index'))->assertSee('Bank reconciliation');
});
