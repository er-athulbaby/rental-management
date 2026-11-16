<?php

/*
 * Design pass (spec 2026-11-15 §3): every string the pass adds has one Arabic translation. Each task appends its keys to
 * $keys; the second test reads every __() in the shared ui components.
 */

test('every string the design pass adds has an Arabic translation', function () {
    $ar = json_decode((string) file_get_contents(lang_path('ar.json')), true, flags: JSON_THROW_ON_ERROR);
    $keys = [
        // Task 1: shared components
        'Clear filters', 'Nothing to show here.', 'Try clearing the filters.', 'On this page',
        // Task 3: lists A
        'Owed', 'Overdue invoices', 'Drafts', 'Collected this month', 'Receipts this month', 'To deposit this week', 'Pending approval',
        'Expiring in 60 days', 'Waiting', 'For you to decide',
        // Task 4: lists B
        'Occupied', 'Blocked', 'Urgent', 'Open work orders', 'Overdue work orders',
        // Task 5: forms A
        'Location', 'Details', 'Money', 'Availability', 'Management and notes', 'Identity', 'Contact',
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
