<?php

namespace App\Enums;

/** The 25 permissions of spec §8.1. The values are the names stored by spatie/laravel-permission. */
enum PermissionName: string
{
    case UsersManage = 'users.manage';
    case RolesManage = 'roles.manage';
    case SettingsManage = 'settings.manage';
    case TemplatesManage = 'templates.manage';
    case AuditView = 'audit.view';
    case BuildingsView = 'buildings.view';
    case BuildingsManage = 'buildings.manage';
    case BuildingsViewAll = 'buildings.view-all';
    case OwnersView = 'owners.view';
    case OwnersManage = 'owners.manage';
    case OwnersBankManage = 'owners.bank.manage';
    case CustomersView = 'customers.view';
    case CustomersManage = 'customers.manage';
    case AgreementsView = 'agreements.view';
    case AgreementsManage = 'agreements.manage';
    case FinanceView = 'finance.view';
    case InvoicesManage = 'invoices.manage';
    case PaymentsManage = 'payments.manage';
    case ChequesManage = 'cheques.manage';
    case DisbursementsManage = 'disbursements.manage';
    case ExpensesManage = 'expenses.manage';
    case ReportsOperational = 'reports.operational';
    case ReportsFinancial = 'reports.financial';
    case ApprovalsDecide = 'approvals.decide';
    case ImportRun = 'import.run';

    /**
     * "Finance *.manage" (spec §8.1).
     *
     * @return list<self>
     */
    public static function financeManage(): array
    {
        return [self::InvoicesManage, self::PaymentsManage, self::ChequesManage, self::DisbursementsManage, self::ExpensesManage];
    }

    /**
     * Holders must confirm 2FA and cannot disable it (spec §8.6).
     *
     * @return list<self>
     */
    public static function twoFactorRequired(): array
    {
        return [
            self::UsersManage, self::RolesManage, self::SettingsManage, self::AuditView, self::FinanceView,
            ...self::financeManage(), self::ApprovalsDecide,
        ];
    }

    /**
     * Granting any of these notifies every other approver (spec §8.1).
     *
     * @return list<self>
     */
    public static function sensitiveGrants(): array
    {
        return [self::ApprovalsDecide, ...self::financeManage()];
    }
}
