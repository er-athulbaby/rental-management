<?php

namespace Database\Seeders;

use App\Enums\PermissionName as P;
use App\Enums\RoleName;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Spec §8.1 defaults. syncPermissions resets each role, so run this ONLY from rms:install and tests —
 * never on deploy, or it would undo Admin's audited role edits.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (P::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        foreach (self::matrix() as $role => $permissions) {
            Role::findOrCreate($role, 'web')->syncPermissions(array_map(fn (P $p) => $p->value, $permissions));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @return array<string, list<P>> */
    public static function matrix(): array
    {
        $view = [P::BuildingsView, P::OwnersView, P::CustomersView, P::AgreementsView, P::FinanceView];
        $reports = [P::ReportsOperational, P::ReportsFinancial];

        return [
            RoleName::Admin->value => [
                P::UsersManage, P::RolesManage, P::SettingsManage, P::TemplatesManage, P::AuditView,
                P::BuildingsView, P::BuildingsManage, P::BuildingsViewAll,
                P::OwnersView, P::OwnersManage, P::OwnersBankManage,
                P::CustomersView, P::CustomersManage,
                P::AgreementsView, P::AgreementsManage,
                P::FinanceView, // invoices, payments, payments out, expenses: view only
                ...$reports,
            ],
            RoleName::Management->value => [
                ...$view, P::BuildingsViewAll, P::ApprovalsDecide, P::AuditView, ...$reports,
            ],
            RoleName::Finance->value => [
                ...$view, P::BuildingsViewAll, P::OwnersManage, ...P::financeManage(), ...$reports,
            ],
            RoleName::PropertyManager->value => [
                P::BuildingsView, P::BuildingsManage, P::BuildingsViewAll, P::OwnersView,
                P::CustomersView, P::CustomersManage, P::AgreementsView, P::AgreementsManage,
                P::ExpensesManage, P::ReportsOperational,
            ],
            RoleName::Leasing->value => [ // assigned buildings only (spec §8.2)
                P::BuildingsView, P::CustomersView, P::CustomersManage,
                P::AgreementsView, P::AgreementsManage, P::ReportsOperational,
            ],
            RoleName::VendorSupport->value => array_values(array_filter(
                P::cases(),
                fn (P $p) => ! in_array($p, [P::ApprovalsDecide, P::OwnersBankManage, ...P::financeManage()], true),
            )), // includes import.run, which no other role gets
        ];
    }
}
