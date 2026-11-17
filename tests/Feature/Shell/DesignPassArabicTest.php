<?php

/*
 * Design pass (spec 2026-11-15 §3): every string the pass adds has one Arabic translation. Each task appends its keys to
 * $keys; the second test reads every __() in the shared ui components.
 */

test('every string the design pass adds has an Arabic translation', function () {
    $ar = json_decode((string) file_get_contents(lang_path('ar.json')), true, flags: JSON_THROW_ON_ERROR);
    $keys = [
        // Task 1: shared components
        'Basis', 'My reports', 'Packages', 'Clear filters', 'Nothing to show here.', 'Try clearing the filters.', 'On this page',
        // Task 3: lists A
        'Owed', 'Overdue invoices', 'Drafts', 'Collected this month', 'Receipts this month', 'To deposit this week', 'Pending approval',
        'Expiring in 60 days', 'Waiting', 'For you to decide',
        // Task 4: lists B
        'Occupied', 'Blocked', 'Urgent', 'Open work orders', 'Overdue work orders',
        // Task 5: forms A
        'Location', 'Details', 'Money', 'Availability', 'Management and notes', 'Identity', 'Contact',
        // Task 7: key screens A
        'Hello, :name', 'Needs attention', 'Nothing needs attention.', 'Cheques to deposit this week', 'Bounced cheques', 'Approvals waiting',
        'Agreements expiring in 60 days', 'Urgent maintenance requests', 'Reminders', 'Reminders and notes', 'Amendments', 'Notice and move-out',
        'Approval history', 'ID',
        // Task 8: key screens B
        'Running total', 'Owed now', 'Open invoices', 'This payment', 'Allocated by hand', 'All monthly charges', 'Total deposit', 'Terms',
        'Owner and building', 'Remove unit', 'Remove charge', 'BHD',
        // Task 13: final pass
        'Facilities', 'Charge to', 'Reported by', 'Trades', 'Bank details', 'Urgent, not started', 'Approvals for you to decide', 'Rental management',
        // Polish pass
        'Report builder', 'Report name', 'Report package', 'Package name', '1. What to report', 'Name the report and choose what it is about.', '2. Columns', 'Tick the columns you want to see.', '3. Filters', 'Only rows that match every filter are shown.', 'No filters yet. Add one to narrow the report.', '4. Sort and group', 'Order the rows, group them and add totals.', '1. Name the package', '2. Reports in the package', 'Move a report up or down to change its sheet order.', '3. Add a report',
        // Polish pass
        'Lock a period', 'Checks with a difference', 'Transfers, bank charges and bank interest between your money accounts.', 'Record a movement', 'Record the VAT you paid to, or got back from, NBR for a period.', 'Record a return', 'Match your bank statement to the ledger, one money account at a time.', 'Tell us which column of your file holds what.', 'Reconciliation figures', 'The chart of accounts and where each event posts.', 'Edit account', 'Lines',
        // Polish pass
        'Locked up to', 'Last checked',
        // Rework batch 3
        'Your company details, contract layout, tax and billing defaults. They appear on documents and set the defaults for new records.', 'Name, contact details and logo shown on documents.', 'The letterhead and blank space for printed contracts.', 'Whether you charge VAT, and the default tax for new units.', 'Currency, date format and when invoices are raised.', 'The connection details from your email provider.', 'Who tenants see as the sender of each email.', 'Add a bank', 'Type the bank name as it should appear in lists.', 'Add a facility', 'Type the facility name as it should appear when a building is edited.', 'Add a unit type', 'Pick the usual use so the unit form can fill it in.', 'Label, type and where the field shows.', 'What this user is allowed to do.', 'Roles', 'Deactivating or resetting two-factor signs the user out everywhere.', 'Permissions', 'Tick what this role can do, area by area.', 'Template', 'The name, and whether agreements can use it.', 'Clause :number', '1. Download the templates', 'One template per kind of data. Fill them in a spreadsheet and save as .xlsx or .csv.', '2. Choose your files', 'Attach each filled template. Leave a kind out if you have nothing to import for it.', '3. Check with a dry run', 'Reads every row and reports problems. Nothing is saved.', '4. Import', 'Saves everything. Only possible when every row of every file passes.',
        // Rework batch 4
        ':n selected', 'Allocations', 'Which invoice lines this payment settled.', 'Pay the tenant back from the credit left on this receipt.', 'How and when the money came in.', 'The bank paid this cheque: a payment is recorded against its invoice.', 'The bank returned this cheque. Say when and why.', 'Enter the cheque the tenant gave instead of the bounced one.', 'Who is being paid.', 'Unpaid expenses', 'Say how and when this payment out was made.', 'The payment out is undone once Management approves.', 'Due now', 'Total due', ':n payments', 'Record the instalment you paid the owner for this head-lease.', 'Pays the owner from the ledger balance. Check the bank details before you pay.', 'Amounts kept from the deposit, for example unpaid rent or damage.', 'Pay the tenant the deposit left after the deductions.', 'Marks this expense as reversed. This cannot be undone.', 'Deductions',
    ];

    expect(array_values(array_diff($keys, array_keys($ar))))->toBe([]);
});

test('every __() string in the ui components has an Arabic translation', function () {
    $ar = json_decode((string) file_get_contents(lang_path('ar.json')), true, flags: JSON_THROW_ON_ERROR);
    $missing = [];
    foreach (glob(resource_path('views/components/ui/*.blade.php')) as $file) {
        preg_match_all('/__\(\s*([\'"])((?:(?!\1)[^\\\\]|\\\\.)*)\1/', (string) file_get_contents($file), $m);
        foreach ($m[2] as $key) {
            if (! array_key_exists(stripslashes($key), $ar)) {
                $missing[] = basename($file).': '.stripslashes($key);
            }
        }
    }

    expect($missing)->toBe([]);
});
