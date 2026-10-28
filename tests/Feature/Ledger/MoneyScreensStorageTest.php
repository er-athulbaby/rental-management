<?php

use App\Actions\Ledger\SaveLedgerAccount;
use App\Enums\RoleName;
use App\Integrity\IntegrityCheck;
use App\Livewire\Ledger\Accounts;
use App\Models\Building;
use App\Models\Expense;
use App\Models\LedgerAccount;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('the two new accounts, their rules and the money flag are there, and the trigger count is 73', function () {
    $charges = LedgerAccount::query()->where('system_key', 'bank_charges')->firstOrFail();
    $interest = LedgerAccount::query()->where('system_key', 'bank_interest')->firstOrFail();

    expect([$charges->code, $charges->type->value, $charges->name_en, $charges->name_ar])->toBe(['5180', 'expense', 'Bank charges', 'الرسوم المصرفية'])
        ->and([$interest->code, $interest->type->value, $interest->name_en])->toBe(['4300', 'income', 'Bank interest income'])
        ->and(DB::table('ledger_rules')->where('key', 'bank_charges')->value('ledger_account_id'))->toBe($charges->id)
        ->and(DB::table('ledger_rules')->where('key', 'bank_interest')->value('ledger_account_id'))->toBe($interest->id)
        ->and(LedgerAccount::query()->where('is_money', true)->orderBy('code')->pluck('code')->all())->toBe(['1000', '1010', '1020'])
        ->and(IntegrityCheck::EXPECTED_TRIGGERS)->toBe(73)
        ->and((int) DB::selectOne('SELECT rms_trigger_count() AS n')->n)->toBe(73);
});

test('cash movements, VAT returns and the expense links are write-once', function () {
    $user = User::factory()->create();
    $cash = LedgerAccount::query()->where('code', '1000')->value('id');
    $bank = LedgerAccount::query()->where('code', '1010')->value('id');
    $stamp = ['created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()];
    $movement = DB::table('cash_movements')->insertGetId(['kind' => 'transfer', 'from_account_id' => $cash, 'to_account_id' => $bank, 'amount' => '5.000',
        'date' => '2026-10-20', 'reference' => 'T-1', ...$stamp]);
    $return = DB::table('vat_returns')->insertGetId(['period_from' => '2026-07-01', 'period_to' => '2026-09-30', 'direction' => 'payment', 'method' => 'bank_transfer',
        'amount' => '5.000', 'date' => '2026-10-20', 'reference' => 'NBR-1', ...$stamp]);
    $out = DB::table('disbursements')->insertGetId(['payee_type' => 'vendor', 'payee_id' => maintenanceVendor()->id, 'purpose' => 'vendor_bill', 'amount' => '5.000',
        'method' => 'cash', 'status' => 'pending_approval', ...$stamp]);
    DB::table('disbursement_expenses')->insert(['disbursement_id' => $out, 'expense_id' => Expense::factory()->create()->id]);
    $other = Expense::factory()->create()->id;

    foreach (['cash_movements' => $movement, 'vat_returns' => $return] as $table => $id) {
        expect(fn () => DB::table($table)->where('id', $id)->update(['reference' => 'X']))->toThrow(QueryException::class, 'write-once')
            ->and(fn () => DB::table($table)->where('id', $id)->delete())->toThrow(QueryException::class, 'cannot be deleted');
    }
    expect(fn () => DB::table('disbursement_expenses')->where('disbursement_id', $out)->update(['expense_id' => $other]))->toThrow(QueryException::class, 'write-once')
        ->and(fn () => DB::table('disbursement_expenses')->where('disbursement_id', $out)->delete())->toThrow(QueryException::class, 'cannot be deleted');
});

test('the database refuses a vendor payee off a vendor bill, a transfer to its own account and a money flag off an asset', function () {
    $user = User::factory()->create();
    $bank = LedgerAccount::query()->where('code', '1010')->value('id');
    $row = ['payee_type' => 'vendor', 'payee_id' => maintenanceVendor()->id, 'purpose' => 'other', 'amount' => '5.000', 'method' => 'cash', 'status' => 'pending_approval',
        'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()];

    expect(fn () => DB::table('disbursements')->insert($row))->toThrow(QueryException::class, 'disbursements_vendor_chk')
        ->and(fn () => DB::table('disbursements')->insert([...$row, 'payee_type' => 'owner', 'purpose' => 'vendor_bill']))->toThrow(QueryException::class, 'disbursements_vendor_chk')
        ->and(fn () => DB::table('cash_movements')->insert(['kind' => 'transfer', 'from_account_id' => $bank, 'to_account_id' => $bank, 'amount' => '5.000', 'date' => '2026-10-20',
            'reference' => 'T', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class, 'cash_movements_accounts_chk')
        ->and(fn () => DB::table('ledger_accounts')->where('code', '4000')->update(['is_money' => true]))->toThrow(QueryException::class, 'ledger_accounts_money_chk');
});

test('Admin flags an asset account as money; another type is refused; an edit without the flag keeps it', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $admin = matrixUser(RoleName::Admin, Building::factory()->create());

    Livewire::actingAs($admin)->test(Accounts::class)
        ->set('code', '1030')->set('name_en', 'Petty cash')->set('name_ar', 'النثرية')->set('type', 'asset')->set('is_money', true)
        ->call('save')->assertHasNoErrors()
        ->set('code', '5900')->set('name_en', 'Odd')->set('name_ar', 'غريب')->set('type', 'expense')->set('is_money', true)
        ->call('save')->assertHasErrors('is_money')
        ->set('is_money', ['x'])->call('save')->assertHasErrors('is_money');
    $petty = LedgerAccount::query()->where('code', '1030')->sole();
    $bank = LedgerAccount::query()->where('code', '1010')->sole();
    app(SaveLedgerAccount::class)->handle($admin, $bank, ['code' => '1010', 'name_en' => 'Main bank', 'name_ar' => $bank->name_ar, 'type' => 'asset']);

    expect($petty->is_money)->toBeTrue()
        ->and(LedgerAccount::query()->where('code', '5900')->exists())->toBeFalse()
        ->and($bank->fresh()->is_money)->toBeTrue()
        ->and($bank->fresh()->name_en)->toBe('Main bank');

    Livewire::actingAs($admin)->test(Accounts::class)->call('edit', $petty->id)->assertSet('is_money', true);
});
