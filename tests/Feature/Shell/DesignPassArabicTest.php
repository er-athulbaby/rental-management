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
