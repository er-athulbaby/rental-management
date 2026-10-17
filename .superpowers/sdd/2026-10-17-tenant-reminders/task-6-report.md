# Task 6 report
Status: DONE. RED: 3 tests failed (undefined visibleTo, no list). GREEN: Reminders 101 pass; Reminders+Customers+Invoices+Cheques+Agreements 241 pass (before the extra test). Pint, phpstan (0 errors), npm run build clean.
Built per brief verbatim: ReminderSend::visibleTo/inBuilding, list in RecordPanel (eager-loaded, limit 20, no new public methods), panel on tenant page, logReminder() helper.
Deviation: added a 4th test (finance.view user without buildings.view_all, building B). The brief's "Finance in building B" cannot be used because Finance has buildings.view_all and sees every building; I used Leasing + finance.view instead. Brief expectations unchanged.
Note: Pint reformatted the match in logReminder() across lines.
