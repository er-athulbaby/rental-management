# Custom Fields Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let Admin add their own fields to tenants, units, agreements, owners and buildings. Staff fill them in on the record, filter lists and build reports on them, and import them. Fields marked "Show in portal" appear read-only in the tenant and owner portals.

**Architecture:** There are three tables. `custom_fields` holds the definitions and `custom_field_choices` the choice lists. `custom_field_values` holds one typed row per record per field, with exactly one value column for each type. All five record kinds share one service, `App\CustomFields\CustomFields`, and two Blade components (`<x-custom-fields.section>` for input, `<x-custom-fields.display>` for the read-only view). Each record's existing save action merges `CustomFields::validation()` into its own validator and calls `CustomFields::save()` inside its own transaction. After approval, agreements get their own action, `SaveAgreementDetails`. List pages share `CustomFieldFilter`. Report subjects append `CustomColumns::fields()`: one correlated subquery per field and no joins. The import maps `cf_<key>` cells through `CustomFieldImport` into the same rules. Portal pages call `CustomFields::display($record, portal: true)`, and portal files download through scoped lookups in the portal's own controllers.

**Tech Stack:** Laravel 13, PHP 8.5, MySQL, Livewire 4 + Flux (free), Pest, spatie/laravel-activitylog, `App\Audit\Audit`, `App\Support\Fils`, `App\Actions\Documents\StoreDocument`, `App\Reports\Builder\*`, `App\Actions\Import\RunImport`, `App\Portal\OwnerPortal`, `App\Livewire\Concerns\InPortal`, `<x-date-input>`, `<x-file-button>`.

**Spec:** `docs/superpowers/specs/2026-10-21-custom-fields-design.md`. Read it first; this plan argues from it. Where the portals are concerned it builds on the executed plans `docs/superpowers/plans/2026-10-18-tenant-portal.md` and `docs/superpowers/plans/2026-10-21-owner-portal.md`. Their rulings still hold.

## Global Constraints

- Record kinds: `customer` (shown as "Tenant"), `unit`, `agreement`, `owner`, `building` (spec §2). Out of scope (spec §1): calculated fields, fields on money records, conditional fields, portal editing, the visual design pass.
- Field types: "text (≤255), long text (≤2000), number (up to 3 decimals), date (dd/mm/yyyy), yes/no, choice list, file; choices (choice list only: EN/AR label each, ≤50)" (spec §3).
- "At most 30 active fields per record kind" (spec §3). The key is "unique per kind, never changed" (spec §3).
- "File fields: jpg, png, webp, pdf, ≤10 MB, stored through the document store on the record with a new document category **Custom field**" (spec §4).
- Actions are the only write path, and they re-check permission.
- Money tables are immutable through triggers. `EXPECTED_TRIGGERS` stays 61 ("No money tables touched", spec §6).
- Migrations roll back in order. The dev DB is migrated with `php artisan migrate --database=migrator --force`. Never run `migrate:fresh` on the dev DB `rms`.
- Timezone is Asia/Bahrain. Dates show as dd/mm/yyyy (`format('d/m/Y')`). Numbers are exact decimals, never floats: values are handled as strings through `Fils::fromDecimal` / `Fils::toDecimal`, like the money code.
- Every string goes through `__()` and gets an `ar.json` entry. Say "tenant", never "customer", in UI copy.
- Tampered Livewire input gives a validation message or a 404, never a 500. Public non-static Livewire methods are browser-callable, so internals must be private or static. Use `#[Locked]` for ids.
- Long text is escaped and never rendered as HTML (`{{ }}` only, never `{!! !!}`).
- Building scope applies everywhere: lists, reports and downloads.
- Portals show only `in_portal` fields of the signed-in person's own records, scoped as the existing portal queries are.
- Tests run serially on `rms_test`, never with `--parallel`. Do not pass a folder and a file inside it in the same Pest command.
- Gates: Pint `--dirty`, `phpstan clear-result-cache` then phpstan `--memory-limit=2G` with 0 errors, and `npm run build`.
- PHP binary: `/c/Users/ababy/.config/herd/bin/php85/php.exe` (written `php` below).
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Never commit anything under `.superpowers/`.
- No visual polish. Keep pages consistent with the existing Flux pages; a design pass comes later.
- Blade: do not mix inline `@php(...)` with `@php … @endphp` blocks in one file. Portal views never use `@can`.
- `lang/ar.json`: every task inserts its new lines after the line `    "Credit refund reversal": "عكس استرداد رصيد",`. That line ends in a comma, so the JSON stays valid. No key may appear twice. Every key below was checked as absent from `ar.json` and from the other tasks.

## Rulings made while planning

1. **Value storage and typing.** Each type has exactly one column in `custom_field_values` (`CustomFieldType::column()`):

   | Type | Column | Stored as |
   |---|---|---|
   | text, long text | `value_text` (TEXT) | trimmed string; blank is NULL |
   | number | `value_number` (DECIMAL 15,3) | `Fils::toDecimal(Fils::fromDecimal($input))`, e.g. `"12.500"` |
   | date | `value_date` (DATE) | `Y-m-d` |
   | yes/no | `value_bool` (TINYINT) | 1 / 0; NULL is "not answered" |
   | choice | `choice_id` (FK `custom_field_choices`) | the choice id |
   | file | `document_id` (FK `documents`) | the current file's document |

   - CHECK `custom_field_values_one_value_chk` allows **at most** one column set: `(value_text IS NOT NULL) + (value_number IS NOT NULL) + … + (document_id IS NOT NULL) <= 1`. Zero is allowed: a cleared value keeps its row.
   - CHECKs `custom_fields_entity_chk` and `custom_fields_type_chk` hold the two lists.
   - **Number precision:**
     - Input must match `/^-?\d{1,12}(\.\d{1,3})?$/` (`CustomFields::NUMBER_PATTERN`). That is 12 integer digits, exactly what DECIMAL(15,3) holds.
     - The value is normalised through integer thousandths (`Fils`), and the model cast is `decimal:3`, so it is read back as a string.
     - Display trims trailing zeros (`CustomFields::number()`: `"12.500"` → `"12.5"`).
     - List filters compare against `CAST(? AS DECIMAL(15,3))`, never a double.
     - The report builder shows numbers through its existing `Formatter`, unchanged. That is the display ceiling the money columns already have.
   - **Rows exist only once a value has been saved.** A save that leaves a field empty creates no row. Clearing a value nulls its column but keeps the row. So "a value was ever saved" is simply "a row exists" (ruling 10).
   - Unique `(custom_field_id, entity_id)`; index `(entity, entity_id)`. `entity` repeats the field's kind (spec §6), so a record's values are read without a join.

2. **One shared implementation.** `App\CustomFields\CustomFields` (static) serves all five kinds:
   - `active(entity)`: the kind's active fields in order, with choices.
   - `validation(entity, ?record, $data, $enforceRequired = true)` returns `[rules, messages, attribute names]`. Each record save action spreads them into **its own** `Validator::make()`, so a form shows every error at once.
   - `save(actor, record, values)` writes and audits.
   - `formValues()`, `documents()` and `display()` feed the views.

   The form section is one anonymous component, `<x-custom-fields.section :fields :values :documents />`, bound to the component's `public array $custom` (`wire:model="custom.<key>"`). The read-only box is `<x-custom-fields.display :items />`.

   **How the save joins each transaction:**
   - The Livewire form passes `[...$this->form, 'custom' => $this->custom]`. `custom` comes last, so a tampered `form.custom` is overwritten.
   - The action validates, then runs `$custom = Arr::pull($validated, 'custom')`, so `custom` never reaches `fill()`.
   - Inside the action's existing `DB::transaction(...)`, right after the record is saved, it calls `CustomFields::save($actor, $record, $custom)`. A failure rolls back both together.
   - `SaveCustomer`, `SaveOwner` and `SaveAgreement` already retry (`attempts: 3`), and `save()` re-reads the values on each attempt, so a retry is safe. A retried file upload stores the file again (ponytail note in `save()`).

   **`custom` absent means "not touching custom fields":**
   - `validation()` returns no rules when `$data` has no `custom` key. Code callers such as the import (ruling 7) and existing tests therefore keep working unchanged.
   - Forms always send the key.
   - Only keys present in `custom` are written. A key for an archived or unknown field is ignored rather than refused, because a form opened before Admin archived a field must still save (ruling 14).

3. **Agreements.**
   - The section is on the agreement form (new and draft). It is saved by `SaveAgreement` in its transaction.
   - After that, `Agreements\Show` has a **More details** panel at any status. It is saved by `App\Actions\Agreements\SaveAgreementDetails::handle(User, Agreement, array $custom)`. The action:
     - throws `AuthorizationException` unless `$actor->can('update', $agreement)`, which is `agreements.manage` with every unit in scope, the same check as the draft form;
     - validates through `CustomFields::validation()`;
     - saves inside a transaction that locks the agreement row with `lockForUpdate` (a read lock; the agreement row is not updated, so the immutability triggers are not touched).
   - People who can view but not manage the agreement see the read-only box. `Show::saveDetails()` takes no arguments; the id is the component's `#[Locked] agreementId`.

4. **File fields.**
   - Validation: `['bail', required|nullable, 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240']` (`StoreDocument::MAX_KB`). A record that already has a file treats the field as `nullable`, so no new upload keeps the file.
   - An upload is stored by `StoreDocument::handle($actor, $record, $file, DocumentCategory::CustomField)`, which re-checks `can('update', $record)`. Its id becomes the value's `document_id`.
   - **Replacing** points the value at the new document. The old document is untouched and stays in the record's Documents panel (spec §4).
   - **Clearing** is done in the Documents panel: deleting the document soft-deletes it, and the value's `document` relation then reads null (SoftDeletes), so the field shows empty and, if required, asks for a file again. The FK keeps the row valid.
   - The panel cannot upload with the new category (`Rule::enum(...)->except(DocumentCategory::CustomField)`, and the category is left out of its picker).
   - **Staff download** is the existing `documents.view` / `documents.download` routes. `DocumentPolicy::view` is "can view the record", so building scope follows (spec §4 "download follows the record's permissions").
   - **Documentable types:** all five kinds are already in `Documents\Panel::ALLOWED` (units included) and `documents.documentable_*` is a free morph, so nothing is added. The migration extends `documents_category_chk` with `'custom_field'`.

5. **Report builder.**
   - Each subject's `define()` appends `CustomColumns::fields($entity, $prefix, $section, $idSql)`. They are read fresh on every `define()`, so a new, renamed or archived field shows at once.
   - **Keys:** `cf_<key>` for the subject's own kind, `cfb_<key>` for building fields on Units (section "Building") and `cft_<key>` for tenant fields on Agreements (section "Tenant"). Tenants, Units, Agreements and Owners take their own fields in a "More details" section. The prefixes use `_`, not `.`: the builder binds totals as `wire:model="def.totals.{key}"`, and a dot would nest the key. No existing field key starts with `cf`.
   - **No joins.** Each column is a correlated scalar subquery: `(SELECT cfv12.value_number FROM custom_field_values cfv12 WHERE cfv12.custom_field_id = 12 AND cfv12.entity_id = units.id)`. The alias is unique per field id, and the lookup uses the unique `(custom_field_id, entity_id)` index. The SQL embeds only integer ids from our own rows and a fixed id expression, never user input.
   - **No new column limit.** The existing `ReportDefinition::MAX_COLUMNS = 30` already applies, and subqueries do not count toward MySQL's 61-table join limit.
   - **Typing:**

     | Custom type | Builder field | Behaviour |
     |---|---|---|
     | text | `FieldType::Text` | groupable |
     | long text | `FieldType::Text` | not groupable |
     | number | `FieldType::Number` | sum / average / min / max |
     | date | `FieldType::Date` | groups by day, month, quarter or year |
     | yes/no | `FieldType::Boolean` | groups |
     | choice | `FieldType::Enum` | options are choice id → label (hidden choices included, since records keep them); groups |

     Files are never columns.
   - **Archived fields** stay in `define()` with SQL `NULL`, the label ":label (archived)" (the note) and `Field::$archived = true`. A saved report keeps running:
     - its column is empty;
     - `QueryCompiler` skips filters on archived fields;
     - the builder's column and filter pickers hide archived fields unless the report already uses them.
   - **Deleted fields** (possible only with no values ever saved) are unknown keys. `ReportDefinition::forViewer()` drops any unknown `cf_` / `cfb_` / `cft_` key the way it drops fields the viewer may not see. The dropped list names the key, so saved reports, packages and schedules all run with their existing "left out" note.
   - Renaming changes only the label; the key holds, so saved reports keep working.

6. **List filters ("More filters").**
   - One filter at a time: field, condition, value. `App\CustomFields\CustomFieldFilter::CONDITIONS`:

     | Type | Conditions |
     |---|---|
     | text, long text | contains, is empty |
     | number, date | from–to, is empty |
     | yes/no | yes, no, is empty |
     | choice | is one of, is empty |
     | file | is empty |

   - Each Index component holds `public mixed $more = []` with keys `field`, `op`, `value`, `from`, `to` and `choices`.
     - It is `mixed` so that a tampered scalar cannot cause a `TypeError` (a 500).
     - It is not `#[Url]`, so no query string is hydrated into it.
   - The render chain gets `->tap(fn ($q) => CustomFieldFilter::apply($q, Entity, $this->more))` **after** the existing `visibleTo()` / building / search clauses. `apply()` only adds a `whereIn` / `whereNotIn` of `custom_field_values.entity_id` against the list model's qualified key, so it always narrows the scoped set and never widens it.
   - Anything malformed is ignored, and the list shows unfiltered: an unknown or archived key, a condition not offered for the type, a non-string text, an invalid number or date, or choices not of that field.
   - The UI is `<x-custom-fields.filter :fields :more />` in each list's filter row. It reads `$more` with `data_get()`, so a scalar renders as "no filter".

7. **Import.** For Buildings, Units, Owners, Customers (tenants) and Agreements, `ImportKind::customFieldEntity()`.
   - **Header check:** after the existing missing-columns check, any header starting with `cf_` must be an active, non-file field of that kind. Otherwise the file fails at line 1 with "Unknown custom field column :column." or "File fields cannot be imported: :column.". Other kinds ignore extra columns, as today.
   - **Values:** `importRow()` saves the record through the normal action, without a `custom` key. It then calls `CustomFieldImport::save($actor, $record, $row)`, inside the same per-row savepoint (`DB::transaction` in `handle()`), so a bad cell fails the row and rolls the record back.
   - **Rules:** the same `CustomFields::validation(..., enforceRequired: false)`. Required is not enforced (spec §5), and an empty cell is skipped.
     - Choices match a **visible** choice by EN label (case-insensitive) or exact AR label. No match gives `:field: ":value" is not one of: :list.`.
     - Yes/no accepts yes/y/true/1/نعم and no/n/false/0/لا.
     - Dates accept `Y-m-d` (an Excel date cell is already normalised) or dd/mm/yyyy.
   - **Agreements** take `cf_` cells from the group's first row. The existing "rows … disagree on :column" check gains every `cf_` column.
   - `RunImport::agreement()` now returns the `Agreement`.

8. **Portal display.**

   | Portal page | Fields shown |
   |---|---|
   | Tenant Home | the tenant's fields |
   | Tenant Agreements | each agreement's fields, then each of its units' fields under "Unit :code" |
   | Owner Home | the owner's fields |
   | Owner Buildings | each card shows that building's fields |

   - Every page uses `CustomFields::display($record, portal: true)`: active, `in_portal`, filled, in the viewer's locale (label AR falling back to EN; choices the same; Yes/No through `__()`).
   - The records are exactly those the pages already list. Tenant: `customer()` and the agreements past approval. Owner: `owner()` and `OwnerPortal::buildings()`.
   - **File downloads** are `GET portal/custom-fields/{document}` (`portal.custom-fields.file`, tenant group) and `GET portal/owner/custom-fields/{document}` (`portal.owner.custom-fields.file`, owner group). Both use `whereNumber` and string parameters, inside the existing `PortalAccess` groups. They call `CustomFields::portalDocument($id, $scope)`, which needs a `custom_field_values` row with that `document_id`:
     - on an active, `in_portal`, file-type field;
     - whose `(entity, entity_id)` is in the scope. Tenant scope (`tenantScope`): customer = theirs; agreement = theirs past approval; unit = those agreements' units. Owner scope (`ownerScope`): owner = theirs; building = `OwnerContract::portalFor()` buildings.

     Anything else is a 404, including a replaced file the value no longer points to and a soft-deleted document. The account helpers already 404 a missing side. Downloads are audited `document.downloaded` with the account as causer.

9. **Key generation (`CustomField::keyFor()`).**
   - The key is `Str::slug($labelEn, '_')`, cut to 40 characters, with `_` trimmed from the ends.
   - An empty result becomes `field`. A key starting with a digit is prefixed `f_`.
   - If the kind already has the key (archived fields included), `_2`, `_3`, … is appended.
   - It is made once, when the field is created, inside `SaveCustomField`'s transaction after locking the kind's rows. The unique `(entity, key)` index is the backstop.
   - No code path ever writes `key` again; `SaveCustomField` fills only label, type, help, required and portal.

10. **The cap; archive, restore and delete.**
    - `CustomField::MAX_ACTIVE = 30` active fields per kind, checked under a `lockForUpdate` of the kind's fields on create and on restore: "At most :n active fields per record kind. Archive one first."
    - **Archive** sets `archived_at`, which hides the field from forms, lists, import and portals (they all start from `active()`). Values are kept and the report shows it as "(archived)".
    - **Restore** clears `archived_at`.
    - **Delete** is allowed only when no `custom_field_values` row exists ("This field has saved values: archive it instead."). Choices cascade. The FK from values is RESTRICT, which backstops a racing first save.
    - **Move up / down** renumbers the kind's active fields 1…n. Archived fields keep their position and are listed after the active ones.

11. **Choices.**
    - A choice list needs at least one choice and at most 50 rows. EN labels are distinct, ignoring case, because the import matches by label.
    - The admin form posts the whole list in order. Each row is `{id, label_en, label_ar, hidden}`, where `id` must be one of this field's choices (a tampered id gives a message).
    - An existing choice left out is **hidden** if any value holds it, and deleted otherwise (spec §3). The FK from values is RESTRICT, so a used choice can never be deleted.
    - A hidden choice is no longer offered, but a record holding it keeps it: the form still shows it, and validation allows it (spec §4).
    - The type is locked once any value exists ("The type cannot change once values are saved."). Before that it may change, and choices are deleted when the type leaves "choice list".

12. **Detail pages.**
    - **Tenants, units, buildings and owners:** the edit page is the detail page. Users who can update the record see the input section inside the form. Everyone else sees the read-only box.
    - **Agreements:** the box is on the Show page for people who cannot manage.
    - **What the box shows:** active fields, in order. Empty optional fields are left out. An empty **required** field shows "—" to staff; that can only happen on a record saved before the field became required. Portals hide every empty field.
    - Long text uses `whitespace-pre-line` with `{{ }}`, so line breaks show and HTML never renders.
    - A file is a link (staff: `documents.view`; portals: their own routes).

13. **Audits.**
    - **Value changes:** one `custom_fields.changed` entry per save that changed anything. Its subject is the record and its causer the staff member. `old` and `attributes` are keyed by the field's English label, with display text (a file shows its file name).
    - **Field definitions:** `custom_field.created`, `custom_field.updated` (only the changed attributes, choices as a list of labels), `custom_field.moved`, `custom_field.archived`, `custom_field.restored`, `custom_field.deleted`, each with the admin as causer, through `App\Audit\Audit`. The definition models do **not** use `LogsActivity`, to avoid double entries.
    - The file itself is audited by `StoreDocument` (`document.uploaded`).

14. **Tampering.**
    - **Admin page:**
      - Ids arrive as `mixed`, are checked with `ctype_digit` and are looked up inside the current kind: anything else is a 404.
      - The kind tab is checked by `CustomFieldEntity::tryFrom()`, and an invalid value is a 404.
      - `move()` accepts only -1 or 1.
      - `editingId` is `#[Locked]`.
    - **Values:** every rule starts with `bail`, so an array where a string belongs gives "must be a string" and not a 500.
      - Choice ids must be in the allowed list.
      - The section component casts held values with `is_scalar()` before comparing.
      - Unknown keys are ignored (ruling 2).

15. **Arabic and copy.**
    - Field labels show `label_ar` when the locale is `ar` and it is filled; choice labels the same.
    - Validation messages name the field by `label()`.
    - Admin UI tab names are "Tenants", "Units", "Agreements", "Owners" and "Buildings", never "Customers".
    - `ArabicTest` gains `components/custom-fields/display.blade.php`, the one new view the portals render.

16. **Migration and rollback.**
    - `2026_12_25_000100_create_custom_fields_tables.php` creates the three tables and their CHECKs, and widens `documents_category_chk` with `'custom_field'`.
    - Its `down()` restores the category CHECK **first**. With a custom-field document present that fails, safely, before any table is dropped. It then drops values, choices and fields.
    - `tests/Concurrency/VendorsMigrationTest.php` rolls it back before the owner portal migration and re-runs it last, so the real rollback order is exercised. The maintenance migration's `down()` rewrites the same category CHECK.

## File Structure

```
database/migrations/2026_12_25_000100_create_custom_fields_tables.php   NEW  (Task 1)
app/Enums/CustomFieldEntity.php, app/Enums/CustomFieldType.php          NEW  (Task 1)
app/Models/CustomField.php, CustomFieldChoice.php, CustomFieldValue.php NEW  (Task 1)
app/Enums/DocumentCategory.php                                          MOD  CustomField case (Task 1)
tests/Pest.php                                                          MOD  customField(), customValue() (Task 1)
tests/Concurrency/VendorsMigrationTest.php                              MOD  rollback order (Task 1)
app/Actions/CustomFields/SaveCustomField.php, SetCustomFieldArchived.php, DeleteCustomField.php, MoveCustomField.php  NEW (Task 2)
app/Livewire/Admin/CustomFieldSettings.php + resources/views/livewire/admin/custom-field-settings.blade.php          NEW (Task 2)
routes/admin.php, resources/views/layouts/app/sidebar.blade.php         MOD  (Task 2)
app/CustomFields/CustomFields.php                                       NEW  (Task 3); portal scope (Task 9)
resources/views/components/custom-fields/section.blade.php, display.blade.php  NEW (Task 3)
app/Actions/Customers/SaveCustomer.php, app/Livewire/Customers/Form.php + view MOD (Task 3)
app/Livewire/Documents/Panel.php                                        MOD  category excluded (Task 3)
app/Actions/Units/SaveUnit.php, app/Actions/Buildings/SaveBuilding.php, app/Actions/Owners/SaveOwner.php  MOD (Task 4)
app/Livewire/Units/Form.php, Buildings/Form.php, Owners/Form.php + views MOD (Task 4)
app/Actions/Agreements/SaveAgreement.php                                MOD  (Task 5)
app/Actions/Agreements/SaveAgreementDetails.php                         NEW  (Task 5)
app/Livewire/Agreements/Form.php, Show.php + views                      MOD  (Task 5)
app/CustomFields/CustomFieldFilter.php                                  NEW  (Task 6)
resources/views/components/custom-fields/filter.blade.php               NEW  (Task 6)
app/Livewire/{Customers,Units,Buildings,Owners,Agreements}/Index.php + views  MOD (Task 6)
app/Reports/Builder/CustomColumns.php                                   NEW  (Task 7)
app/Reports/Builder/Field.php, QueryCompiler.php, ReportDefinition.php  MOD  (Task 7)
app/Reports/Builder/Subjects/Tenants.php, Units.php, Agreements.php, Owners.php  MOD (Task 7)
resources/views/livewire/reports/builder.blade.php                      MOD  archived hidden (Task 7)
app/CustomFields/CustomFieldImport.php                                  NEW  (Task 8)
app/Enums/ImportKind.php, app/Actions/Import/RunImport.php              MOD  (Task 8)
app/Http/Controllers/Portal/DownloadController.php, OwnerDownloadController.php  MOD (Task 9)
routes/portal.php                                                       MOD  (Task 9)
app/Livewire/Portal/Home.php, Agreements.php, Owner/Home.php, Owner/Buildings.php + views  MOD (Task 9)
lang/ar.json                                                            MOD  (Tasks 1–3, 5–8)
tests/Feature/CustomFields/StorageTest.php                              NEW  (Task 1)
tests/Feature/CustomFields/AdminTest.php                                NEW  (Task 2)
tests/Feature/CustomFields/ValuesTest.php                               NEW  (Task 3)
tests/Feature/CustomFields/RecordsTest.php                              NEW  (Task 4)
tests/Feature/CustomFields/AgreementDetailsTest.php                     NEW  (Task 5)
tests/Feature/CustomFields/ListFiltersTest.php                          NEW  (Task 6)
tests/Feature/CustomFields/ReportBuilderTest.php                        NEW  (Task 7)
tests/Feature/CustomFields/ImportTest.php                               NEW  (Task 8)
tests/Feature/CustomFields/PortalTest.php                               NEW  (Task 9); tests/Feature/Portal/ArabicTest.php MOD (Task 9)
```

---

### Task 1: Storage: definitions, choices, typed values, and the "Custom field" document category

**Files:**
- Create: `database/migrations/2026_12_25_000100_create_custom_fields_tables.php`
- Create: `app/Enums/CustomFieldEntity.php`, `app/Enums/CustomFieldType.php`
- Create: `app/Models/CustomField.php`, `app/Models/CustomFieldChoice.php`, `app/Models/CustomFieldValue.php`
- Modify: `app/Enums/DocumentCategory.php`
- Modify: `tests/Pest.php` (two helpers), `tests/Concurrency/VendorsMigrationTest.php`
- Modify: `lang/ar.json`
- Test: `tests/Feature/CustomFields/StorageTest.php`

**Interfaces:**
- Consumes: `Customer`, `Unit`, `Agreement`, `Owner`, `Building`, `Document`, `User`.
- Produces:
  - Tables `custom_fields`, `custom_field_choices`, `custom_field_values` (ruling 1), `documents_category_chk` widened.
  - `enum CustomFieldEntity: string` (`Customer='customer'`, `Unit`, `Agreement`, `Owner`, `Building`) with `label(): string` and `static of(Model $record): self`.
  - `enum CustomFieldType: string` (`Text='text'`, `LongText='long_text'`, `Number='number'`, `Date='date'`, `Bool='bool'`, `Choice='choice'`, `File='file'`) with `label(): string`, `column(): string`.
  - `CustomField` with: `const MAX_ACTIVE = 30`; casts `entity` → `CustomFieldEntity`, `type` → `CustomFieldType`, `required` / `in_portal` → bool, `archived_at` → immutable datetime; `choices(): HasMany` (by position, id); `values(): HasMany`; `label(?string $locale = null): string`; `static keyFor(CustomFieldEntity, string $labelEn): string`.
  - `CustomFieldChoice` with: `label(?string $locale = null): string`; `hidden_at` immutable datetime.
  - `CustomFieldValue` with casts `entity` → enum, `value_number` → `decimal:3` (a string), `value_date` → immutable date, `value_bool` → bool; `field()`, `choice()`, `document()` (soft-deleted documents read as null).
  - `DocumentCategory::CustomField` (`'custom_field'`, label "Custom field").
  - Pest helpers `customField(string $entity, string $type, string $label, array $attributes = [], array $choices = []): CustomField` and `customValue(CustomField $field, Model $record, array $columns): CustomFieldValue`.

- [ ] **Step 1: Add the test helpers to `tests/Pest.php`.** Add to the imports:

```php
use App\Enums\CustomFieldEntity;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use Illuminate\Database\Eloquent\Model;
```

and append at the end of the file:

```php
/**
 * A custom field (custom field tests), written directly: active, optional, not in the portal, last in its kind, unless
 * $attributes say otherwise. Each choice is an English label or an [English, Arabic] pair, in order.
 */
function customField(string $entity, string $type, string $label, array $attributes = [], array $choices = []): CustomField
{
    $field = (new CustomField)->forceFill([
        'entity' => $entity, 'key' => CustomField::keyFor(CustomFieldEntity::from($entity), $label), 'label_en' => $label, 'type' => $type,
        'position' => (int) CustomField::query()->where('entity', $entity)->max('position') + 1, ...$attributes,
    ]);
    $field->save();
    foreach (array_values($choices) as $i => $choice) {
        [$en, $ar] = is_array($choice) ? $choice : [$choice, null];
        $field->choices()->create(['label_en' => $en, 'label_ar' => $ar, 'position' => $i + 1]);
    }

    return $field->load('choices');
}

/** A stored value of $field on $record (custom field tests), e.g. ['value_text' => 'Bapco'] or ['choice_id' => 3]. */
function customValue(CustomField $field, Model $record, array $columns): CustomFieldValue
{
    $value = (new CustomFieldValue)->forceFill(['custom_field_id' => $field->id, 'entity' => $field->entity, 'entity_id' => $record->getKey(), ...$columns]);
    $value->save();

    return $value;
}
```

- [ ] **Step 2: Write the failing test** `tests/Feature/CustomFields/StorageTest.php`:

```php
<?php

use App\Enums\CustomFieldEntity;
use App\Enums\DocumentCategory;
use App\Models\Customer;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('a value row holds at most one value, one row per record per field, and a cleared value keeps its row', function () {
    $field = customField('customer', 'text', 'Employer');
    $customer = Customer::factory()->create();

    customValue($field, $customer, ['value_text' => 'Bapco']);
    expect(fn () => customValue($field, $customer, ['value_text' => 'Alba']))->toThrow(QueryException::class) // unique (field, record)
        ->and(fn () => DB::table('custom_field_values')->where('custom_field_id', $field->id)->update(['value_number' => '1.000']))->toThrow(QueryException::class);

    DB::table('custom_field_values')->where('custom_field_id', $field->id)->update(['value_text' => null]);
    expect(CustomFieldValue::query()->sole()->value_text)->toBeNull();
});

test('kinds and types are fixed lists, and numbers keep all 15 digits exactly', function () {
    expect(fn () => customField('invoice', 'text', 'Nope'))->toThrow(ValueError::class) // the enum refuses it before SQL
        ->and(fn () => DB::table('custom_fields')->insert(['entity' => 'invoice', 'key' => 'x', 'label_en' => 'X', 'type' => 'text', 'position' => 1]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('custom_fields')->insert(['entity' => 'unit', 'key' => 'f', 'label_en' => 'F', 'type' => 'formula', 'position' => 1]))->toThrow(QueryException::class);

    $field = customField('customer', 'number', 'Monthly income');
    customValue($field, Customer::factory()->create(), ['value_number' => '-123456789012.345']);
    expect(CustomFieldValue::query()->sole()->value_number)->toBe('-123456789012.345');
});

test('the key is made from the English label once: snake case, unique per kind, starting with a letter', function () {
    expect(CustomField::keyFor(CustomFieldEntity::Unit, 'Meter number'))->toBe('meter_number');

    customField('unit', 'text', 'Meter number');
    customField('unit', 'text', 'Meter number', ['archived_at' => now()]); // archived fields keep their key
    expect(CustomField::query()->where('entity', 'unit')->orderBy('id')->pluck('key')->all())->toBe(['meter_number', 'meter_number_2'])
        ->and(CustomField::keyFor(CustomFieldEntity::Unit, 'Meter Number!'))->toBe('meter_number_3')
        ->and(CustomField::keyFor(CustomFieldEntity::Building, 'Meter number'))->toBe('meter_number')
        ->and(CustomField::keyFor(CustomFieldEntity::Unit, '2nd phone'))->toBe('f_2nd_phone')
        ->and(CustomField::keyFor(CustomFieldEntity::Unit, '???'))->toBe('field')
        ->and(strlen(CustomField::keyFor(CustomFieldEntity::Unit, str_repeat('Very long label ', 10))))->toBeLessThanOrEqual(40);
});

test('labels fall back to English, and documents take the "Custom field" category', function () {
    $plot = customField('owner', 'text', 'Plot number', ['label_ar' => 'رقم القسيمة']);
    $broker = customField('owner', 'choice', 'Broker', choices: [['Direct', 'مباشر'], 'Agency']);

    expect($plot->label('ar'))->toBe('رقم القسيمة')->and($plot->label('en'))->toBe('Plot number')
        ->and($broker->label('ar'))->toBe('Broker')
        ->and($broker->choices->map(fn ($c) => $c->label('ar'))->all())->toBe(['مباشر', 'Agency']);

    $document = new Document(['category' => DocumentCategory::CustomField, 'disk' => 'local', 'path' => 'documents/x.pdf',
        'original_name' => 'x.pdf', 'mime' => 'application/pdf', 'size' => 1, 'uploaded_by' => User::factory()->create()->id]);
    $document->documentable()->associate(Customer::factory()->create());
    $document->save();

    expect($document->fresh()?->category)->toBe(DocumentCategory::CustomField)
        ->and(DocumentCategory::CustomField->label())->toBe('Custom field');
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test tests/Feature/CustomFields/StorageTest.php`
Expected: FAIL with `Class "App\Models\CustomField" not found`.

- [ ] **Step 4: Write the migration** `database/migrations/2026_12_25_000100_create_custom_fields_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 2026-10-21 custom fields §6: field definitions, choice lists and one typed value row per record per field, plus
 * the "custom_field" document category. Not money tables: no triggers (EXPECTED_TRIGGERS unchanged).
 */
return new class extends Migration
{
    private const string CATEGORIES = "'photo', 'id_copy', 'cr_copy', 'signed_contract', 'cheque_image', 'move_out_photo', 'owner_approval', 'generated_pdf', 'other', 'maintenance'";

    public function up(): void
    {
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();
            $table->string('entity', 20);
            $table->string('key', 60);
            $table->string('label_en', 100);
            $table->string('label_ar', 100)->nullable();
            $table->string('type', 20);
            $table->string('help', 255)->nullable();
            $table->boolean('required')->default(false);
            $table->boolean('in_portal')->default(false);
            $table->unsignedSmallInteger('position');
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['entity', 'key']);
            $table->index(['entity', 'position']);
        });
        DB::statement("ALTER TABLE custom_fields
            ADD CONSTRAINT custom_fields_entity_chk CHECK (entity IN ('customer', 'unit', 'agreement', 'owner', 'building')),
            ADD CONSTRAINT custom_fields_type_chk CHECK (type IN ('text', 'long_text', 'number', 'date', 'bool', 'choice', 'file'))");

        Schema::create('custom_field_choices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_field_id')->constrained()->cascadeOnDelete(); // a field is deleted only with no values, so its choices are unused
            $table->string('label_en', 100);
            $table->string('label_ar', 100)->nullable();
            $table->unsignedSmallInteger('position');
            $table->timestamp('hidden_at')->nullable();
            $table->timestamps();
        });

        Schema::create('custom_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_field_id')->constrained(); // no cascade: a field with values is archived, never deleted
            $table->string('entity', 20);
            $table->unsignedBigInteger('entity_id');
            $table->text('value_text')->nullable();
            $table->decimal('value_number', 15, 3)->nullable();
            $table->date('value_date')->nullable();
            $table->boolean('value_bool')->nullable();
            $table->foreignId('choice_id')->nullable()->constrained('custom_field_choices'); // no cascade: a used choice is hidden, never deleted
            $table->foreignId('document_id')->nullable()->constrained('documents'); // documents are soft-deleted
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['custom_field_id', 'entity_id']);
            $table->index(['entity', 'entity_id']);
        });
        DB::statement('ALTER TABLE custom_field_values ADD CONSTRAINT custom_field_values_one_value_chk CHECK (
            (value_text IS NOT NULL) + (value_number IS NOT NULL) + (value_date IS NOT NULL) + (value_bool IS NOT NULL)
            + (choice_id IS NOT NULL) + (document_id IS NOT NULL) <= 1)');

        DB::statement('ALTER TABLE documents DROP CHECK documents_category_chk');
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_category_chk CHECK (category IN ('.self::CATEGORIES.", 'custom_field'))");
    }

    /** ponytail: the category CHECK goes back first, so a custom-field document makes down() fail before any table is dropped. */
    public function down(): void
    {
        DB::statement('ALTER TABLE documents DROP CHECK documents_category_chk');
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_category_chk CHECK (category IN ('.self::CATEGORIES.'))');
        Schema::dropIfExists('custom_field_values');
        Schema::dropIfExists('custom_field_choices');
        Schema::dropIfExists('custom_fields');
    }
};
```

- [ ] **Step 5: Create the two enums.** `app/Enums/CustomFieldEntity.php`:

```php
<?php

namespace App\Enums;

use App\Models\Agreement;
use App\Models\Building;
use App\Models\Customer;
use App\Models\Owner;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** Spec 2026-10-21 custom fields §2: the five record kinds that take custom fields. "customer" is shown as Tenants. */
enum CustomFieldEntity: string
{
    case Customer = 'customer';
    case Unit = 'unit';
    case Agreement = 'agreement';
    case Owner = 'owner';
    case Building = 'building';

    /** The Administration tab name. */
    public function label(): string
    {
        return match ($this) {
            self::Customer => __('Tenants'),
            self::Unit => __('Units'),
            self::Agreement => __('Agreements'),
            self::Owner => __('Owners'),
            self::Building => __('Buildings'),
        };
    }

    public static function of(Model $record): self
    {
        return match (true) {
            $record instanceof Customer => self::Customer,
            $record instanceof Unit => self::Unit,
            $record instanceof Agreement => self::Agreement,
            $record instanceof Owner => self::Owner,
            $record instanceof Building => self::Building,
            default => throw new InvalidArgumentException($record::class.' takes no custom fields.'),
        };
    }
}
```

`app/Enums/CustomFieldType.php`:

```php
<?php

namespace App\Enums;

/** Spec 2026-10-21 custom fields §3: the field types; each has one value column (plan ruling 1). */
enum CustomFieldType: string
{
    case Text = 'text';
    case LongText = 'long_text';
    case Number = 'number';
    case Date = 'date';
    case Bool = 'bool';
    case Choice = 'choice';
    case File = 'file';

    public function label(): string
    {
        return match ($this) {
            self::Text => __('Text'),
            self::LongText => __('Long text'),
            self::Number => __('Number'),
            self::Date => __('Date'),
            self::Bool => __('Yes / no'),
            self::Choice => __('Choice list'),
            self::File => __('File'),
        };
    }

    /** The custom_field_values column that holds this type. */
    public function column(): string
    {
        return match ($this) {
            self::Text, self::LongText => 'value_text',
            self::Number => 'value_number',
            self::Date => 'value_date',
            self::Bool => 'value_bool',
            self::Choice => 'choice_id',
            self::File => 'document_id',
        };
    }
}
```

- [ ] **Step 6: Create the three models.** `app/Models/CustomField.php`:

```php
<?php

namespace App\Models;

use App\Enums\CustomFieldEntity;
use App\Enums\CustomFieldType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Spec 2026-10-21 custom fields §3: an Admin-defined field on one record kind. Written only by App\Actions\CustomFields;
 * audited there (plan ruling 13), so no LogsActivity.
 *
 * @property int $id
 * @property CustomFieldEntity $entity
 * @property string $key fixed at creation (plan ruling 9)
 * @property string $label_en
 * @property string|null $label_ar
 * @property CustomFieldType $type
 * @property string|null $help
 * @property bool $required
 * @property bool $in_portal
 * @property int $position
 * @property CarbonImmutable|null $archived_at
 * @property int|null $created_by
 * @property-read Collection<int, CustomFieldChoice> $choices
 */
class CustomField extends Model
{
    /** Spec §3: at most 30 active fields per record kind (plan ruling 10). */
    public const int MAX_ACTIVE = 30;

    protected function casts(): array
    {
        return ['entity' => CustomFieldEntity::class, 'type' => CustomFieldType::class, 'required' => 'boolean', 'in_portal' => 'boolean',
            'position' => 'integer', 'archived_at' => 'immutable_datetime'];
    }

    /** @return HasMany<CustomFieldChoice, $this> */
    public function choices(): HasMany
    {
        return $this->hasMany(CustomFieldChoice::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<CustomFieldValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class);
    }

    /** Spec §3: AR optional, falls back to EN. */
    public function label(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'ar' && filled($this->label_ar) ? (string) $this->label_ar : $this->label_en;
    }

    /**
     * Plan ruling 9: the fixed key, made once from the English label: lower-case snake case, at most 40 characters,
     * starting with a letter, with _2, _3, … when the kind already has it (archived fields included).
     */
    public static function keyFor(CustomFieldEntity $entity, string $labelEn): string
    {
        $slug = trim(substr(Str::slug($labelEn, '_'), 0, 40), '_');
        $base = $slug === '' ? 'field' : (ctype_alpha($slug[0]) ? $slug : 'f_'.$slug);
        $taken = self::query()->where('entity', $entity)->pluck('key')->all();
        $key = $base;
        for ($n = 2; in_array($key, $taken, true); $n++) {
            $key = "{$base}_{$n}";
        }

        return $key;
    }
}
```

`app/Models/CustomFieldChoice.php`:

```php
<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Spec 2026-10-21 custom fields §3: one choice of a choice list. A used choice is hidden, never deleted (plan ruling 11).
 *
 * @property int $id
 * @property int $custom_field_id
 * @property string $label_en
 * @property string|null $label_ar
 * @property int $position
 * @property CarbonImmutable|null $hidden_at
 */
class CustomFieldChoice extends Model
{
    protected $fillable = ['label_en', 'label_ar', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'hidden_at' => 'immutable_datetime'];
    }

    public function label(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'ar' && filled($this->label_ar) ? (string) $this->label_ar : $this->label_en;
    }
}
```

`app/Models/CustomFieldValue.php`:

```php
<?php

namespace App\Models;

use App\Enums\CustomFieldEntity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Spec 2026-10-21 custom fields §6: one record's value of one field, in the column of the field's type (plan ruling 1).
 * Written only by App\CustomFields\CustomFields::save(); audited there.
 *
 * @property int $id
 * @property int $custom_field_id
 * @property CustomFieldEntity $entity
 * @property int $entity_id
 * @property string|null $value_text
 * @property string|null $value_number decimal:3, never a float
 * @property CarbonImmutable|null $value_date
 * @property bool|null $value_bool
 * @property int|null $choice_id
 * @property int|null $document_id
 * @property int|null $updated_by
 * @property-read CustomField $field
 * @property-read CustomFieldChoice|null $choice
 * @property-read Document|null $document null once the document is soft-deleted
 */
class CustomFieldValue extends Model
{
    protected function casts(): array
    {
        return ['entity' => CustomFieldEntity::class, 'entity_id' => 'integer', 'value_number' => 'decimal:3', 'value_date' => 'immutable_date',
            'value_bool' => 'boolean', 'choice_id' => 'integer', 'document_id' => 'integer'];
    }

    /** @return BelongsTo<CustomField, $this> */
    public function field(): BelongsTo
    {
        return $this->belongsTo(CustomField::class, 'custom_field_id');
    }

    /** @return BelongsTo<CustomFieldChoice, $this> */
    public function choice(): BelongsTo
    {
        return $this->belongsTo(CustomFieldChoice::class, 'choice_id');
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
```

- [ ] **Step 7: The document category.** In `app/Enums/DocumentCategory.php` add after `case Maintenance = 'maintenance';`:

```php
    case CustomField = 'custom_field'; // spec 2026-10-21 custom fields §4: written only through a file field
```

and in `label()` after `self::Maintenance => __('Maintenance photo'),`:

```php
            self::CustomField => __('Custom field'),
```

- [ ] **Step 8: Keep the real rollback order in `tests/Concurrency/VendorsMigrationTest.php`.**
  - In `beforeEach`, after the `$this->ownerPortal = require …` line, add:

```php
    $this->customFields = require database_path('migrations/2026_12_25_000100_create_custom_fields_tables.php');
```

  - In `afterEach`, after the `ownerPortal` block, add:

```php
    if (! Schema::hasTable('custom_fields')) { // the custom fields migration last, in up order
        $this->customFields->up();
    }
```

  - In the test, replace **both** occurrences (use `replace_all`) of

```php
    if (Schema::hasColumn('portal_accounts', 'owner_id')) {
        $this->ownerPortal->down();
```

with

```php
    if (Schema::hasTable('custom_fields')) {
        $this->customFields->down(); // real rollback order: the custom fields migration rewrites documents_category_chk, as the maintenance one does, so it goes back first
    }
    if (Schema::hasColumn('portal_accounts', 'owner_id')) {
        $this->ownerPortal->down();
```

  - After the test's last line, `expect(Schema::hasColumn('portal_accounts', 'owner_id'))->toBeTrue();`, add:

```php
    $this->customFields->up();
    expect(Schema::hasTable('custom_fields'))->toBeTrue();
```

- [ ] **Step 9: Arabic.** In `lang/ar.json`, after `    "Credit refund reversal": "عكس استرداد رصيد",` insert:

```json
    "Custom field": "حقل مخصص",
    "Tenants": "المستأجرون",
    "Owners": "الملاك",
    "Text": "نص",
    "Long text": "نص طويل",
    "Number": "رقم",
    "Date": "التاريخ",
    "Yes / no": "نعم / لا",
    "Choice list": "قائمة اختيارات",
    "File": "ملف",
```

- [ ] **Step 10: Migrate the dev DB.**

Run: `php artisan migrate --database=migrator --force`
Expected: `2026_12_25_000100_create_custom_fields_tables ... DONE`.

- [ ] **Step 11: Run the tests.**

Run: `php artisan test tests/Feature/CustomFields/StorageTest.php`, then `php artisan test tests/Concurrency/VendorsMigrationTest.php`, then `php artisan test tests/Feature/Portal/ArabicTest.php` (DocumentCategory labels need Arabic).
Expected: PASS, PASS, PASS.

- [ ] **Step 12: Gates and commit.**

Run: `vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add database/migrations/2026_12_25_000100_create_custom_fields_tables.php app/Enums/CustomFieldEntity.php app/Enums/CustomFieldType.php \
  app/Models/CustomField.php app/Models/CustomFieldChoice.php app/Models/CustomFieldValue.php app/Enums/DocumentCategory.php \
  tests/Pest.php tests/Concurrency/VendorsMigrationTest.php tests/Feature/CustomFields/StorageTest.php lang/ar.json
git commit -m "$(cat <<'EOF'
Custom fields storage: definitions, choices, typed values with one value column each, exact 15,3 numbers, fixed keys, and the Custom field document category

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Administration → Custom fields: add, change, order, archive, restore, delete

**Files:**
- Create: `app/Actions/CustomFields/SaveCustomField.php`, `SetCustomFieldArchived.php`, `DeleteCustomField.php`, `MoveCustomField.php`
- Create: `app/Livewire/Admin/CustomFieldSettings.php`, `resources/views/livewire/admin/custom-field-settings.blade.php`
- Modify: `routes/admin.php`, `resources/views/layouts/app/sidebar.blade.php`, `lang/ar.json`
- Test: `tests/Feature/CustomFields/AdminTest.php`

**Interfaces:**
- Consumes: Task 1's models, enums and `customField()` / `customValue()`; `App\Audit\Audit::log`; `PermissionName::SettingsManage`.
- Produces:
  - `SaveCustomField::handle(User $actor, ?CustomField $field, array $data): CustomField`. `$data` keys: `entity` (new only), `label_en`, `label_ar`, `type`, `help`, `required`, `in_portal`, `choices` (list of `{id: ?int, label_en, label_ar, hidden}`).
  - `SetCustomFieldArchived::handle(User $actor, CustomField $field, bool $archived): void`
  - `DeleteCustomField::handle(User $actor, CustomField $field): void`
  - `MoveCustomField::handle(User $actor, CustomField $field, int $by): void` (`-1` up, `1` down)
  - Route `admin.custom-fields` (`/admin/custom-fields`, `can:settings.manage`).
  - Audit events `custom_field.created|updated|moved|archived|restored|deleted`.

- [ ] **Step 1: Write the failing test** `tests/Feature/CustomFields/AdminTest.php`:

```php
<?php

use App\Actions\CustomFields\DeleteCustomField;
use App\Actions\CustomFields\MoveCustomField;
use App\Actions\CustomFields\SaveCustomField;
use App\Actions\CustomFields\SetCustomFieldArchived;
use App\Enums\RoleName;
use App\Livewire\Admin\CustomFieldSettings;
use App\Models\CustomField;
use App\Models\CustomFieldChoice;
use App\Models\CustomFieldValue;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Admin);
    $this->leasing = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Leasing);
    $this->save = fn (array $data, ?CustomField $field = null) => app(SaveCustomField::class)->handle($this->admin, $field, [
        'entity' => 'unit', 'label_en' => 'Meter number', 'label_ar' => '', 'type' => 'text', 'help' => '', 'required' => false, 'in_portal' => false,
        'choices' => [], ...$data,
    ]);
    $this->choice = fn (string $en, ?int $id = null, bool $hidden = false, string $ar = '') => ['id' => $id, 'label_en' => $en, 'label_ar' => $ar, 'hidden' => $hidden];
});

test('Admin adds a field on the Units tab; the key comes from the English label and the field goes last', function () {
    customField('unit', 'text', 'Plot');

    Livewire::actingAs($this->admin)->test(CustomFieldSettings::class)
        ->set('entity', 'unit')
        ->call('create')
        ->set('form.label_en', 'Meter number')
        ->set('form.label_ar', 'رقم العداد')
        ->set('form.type', 'number')
        ->set('form.required', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('meter_number');

    $field = CustomField::query()->where('key', 'meter_number')->sole();
    expect($field->entity->value)->toBe('unit')
        ->and($field->label('ar'))->toBe('رقم العداد')
        ->and($field->type->value)->toBe('number')
        ->and($field->required)->toBeTrue()
        ->and($field->in_portal)->toBeFalse()
        ->and($field->position)->toBe(2)
        ->and($field->created_by)->toBe($this->admin->id);
});

test('only settings.manage reaches the page and the actions', function () {
    $field = customField('unit', 'text', 'Plot');

    $this->actingAs($this->leasing)->get(route('admin.custom-fields'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('admin.custom-fields'))->assertOk()->assertSee('Custom fields');
    Livewire::actingAs($this->leasing)->test(CustomFieldSettings::class)->assertForbidden();
    expect(fn () => app(SaveCustomField::class)->handle($this->leasing, null, ['entity' => 'unit', 'label_en' => 'X', 'type' => 'text']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SetCustomFieldArchived::class)->handle($this->leasing, $field, true))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteCustomField::class)->handle($this->leasing, $field))->toThrow(AuthorizationException::class)
        ->and(fn () => app(MoveCustomField::class)->handle($this->leasing, $field, 1))->toThrow(AuthorizationException::class);
});

test('once a value exists the type is locked; label, help, required, portal and choices still change, and a used choice is hidden, not deleted', function () {
    $field = ($this->save)(['type' => 'choice', 'label_en' => 'Parking', 'choices' => [($this->choice)('Covered'), ($this->choice)('Open'), ($this->choice)('None')]]);
    [$covered, $open, $none] = $field->choices()->get()->all();
    customValue($field, Unit::factory()->create(), ['choice_id' => $covered->id]);

    expect(fn () => ($this->save)(['type' => 'text', 'label_en' => 'Parking'], $field))->toThrow(ValidationException::class, 'The type cannot change once values are saved.');

    // Rename Open, leave out Covered (used) and None (unused), add Basement.
    ($this->save)(['type' => 'choice', 'label_en' => 'Parking bay', 'help' => 'As on the title deed', 'required' => true, 'in_portal' => true,
        'choices' => [($this->choice)('Open air', $open->id, ar: 'مكشوف'), ($this->choice)('Basement')]], $field);

    $field->refresh();
    $choices = $field->choices()->get();
    expect($field->label_en)->toBe('Parking bay')->and($field->key)->toBe('parking')->and($field->help)->toBe('As on the title deed')
        ->and($field->required)->toBeTrue()->and($field->in_portal)->toBeTrue()
        ->and($choices->firstWhere('id', $covered->id)?->hidden_at)->not->toBeNull()
        ->and(CustomFieldChoice::query()->find($none->id))->toBeNull()
        ->and($choices->whereNull('hidden_at')->pluck('label_en')->values()->all())->toBe(['Open air', 'Basement'])
        ->and($choices->firstWhere('id', $open->id)?->label('ar'))->toBe('مكشوف');
});

test('before any value is saved the type can change; a choice list needs a choice and distinct labels', function () {
    $field = ($this->save)([]);
    ($this->save)(['type' => 'date'], $field);
    expect($field->refresh()->type->value)->toBe('date');

    expect(fn () => ($this->save)(['type' => 'choice'], $field))->toThrow(ValidationException::class, 'A choice list needs at least one choice.')
        ->and(fn () => ($this->save)(['type' => 'choice', 'choices' => [($this->choice)('Sea'), ($this->choice)('sea')]], $field))->toThrow(ValidationException::class);

    ($this->save)(['type' => 'choice', 'choices' => [($this->choice)('Sea')]], $field);
    ($this->save)(['type' => 'text'], $field); // leaving "choice list" with no values deletes the choices
    expect(CustomFieldChoice::query()->count())->toBe(0);
});

test('archive keeps values and can be restored; delete only before any value; at most 30 active fields per kind', function () {
    $used = ($this->save)(['label_en' => 'Used']);
    $unused = ($this->save)(['label_en' => 'Unused']);
    customValue($used, Unit::factory()->create(), ['value_text' => 'x']);

    expect(fn () => app(DeleteCustomField::class)->handle($this->admin, $used))->toThrow(ValidationException::class, 'This field has saved values: archive it instead.');
    app(SetCustomFieldArchived::class)->handle($this->admin, $used, true);
    app(DeleteCustomField::class)->handle($this->admin, $unused);
    expect($used->refresh()->archived_at)->not->toBeNull()
        ->and(CustomFieldValue::query()->count())->toBe(1)
        ->and(CustomField::query()->find($unused->id))->toBeNull();

    foreach (range(1, 30) as $n) {
        customField('unit', 'text', "Field {$n}");
    }
    $full = 'At most 30 active fields per record kind. Archive one first.';
    expect(fn () => ($this->save)(['label_en' => 'One too many']))->toThrow(ValidationException::class, $full)
        ->and(fn () => app(SetCustomFieldArchived::class)->handle($this->admin, $used, false))->toThrow(ValidationException::class, $full);

    ($this->save)(['entity' => 'building', 'label_en' => 'Other kind']); // the cap is per kind
    CustomField::query()->where('label_en', 'Field 1')->sole()->forceFill(['archived_at' => now()])->save();
    app(SetCustomFieldArchived::class)->handle($this->admin, $used, false);
    expect($used->refresh()->archived_at)->toBeNull();
});

test('moving swaps a field with its active neighbour; the first field does not move up', function () {
    $a = ($this->save)(['label_en' => 'Alpha']);
    $b = ($this->save)(['label_en' => 'Beta']);
    $c = ($this->save)(['label_en' => 'Gamma']);

    app(MoveCustomField::class)->handle($this->admin, $c, -1);
    app(MoveCustomField::class)->handle($this->admin, $a, -1);
    expect(CustomField::query()->where('entity', 'unit')->orderBy('position')->pluck('label_en')->all())->toBe(['Alpha', 'Gamma', 'Beta']);

    Livewire::actingAs($this->admin)->test(CustomFieldSettings::class)->set('entity', 'unit')->call('move', $b->id, -1);
    expect(CustomField::query()->where('entity', 'unit')->orderBy('position')->pluck('label_en')->all())->toBe(['Alpha', 'Beta', 'Gamma']);
});

test('adding, changing, moving, archiving, restoring and deleting a field are audited with the admin as causer', function () {
    $a = ($this->save)(['label_en' => 'Alpha']);
    $b = ($this->save)(['label_en' => 'Beta']);
    ($this->save)(['label_en' => 'Alpha', 'required' => true], $a);
    app(MoveCustomField::class)->handle($this->admin, $b, -1);
    app(SetCustomFieldArchived::class)->handle($this->admin, $a, true);
    app(SetCustomFieldArchived::class)->handle($this->admin, $a, false);
    app(DeleteCustomField::class)->handle($this->admin, $b);

    $events = Activity::query()->where('event', 'like', 'custom_field.%')->orderBy('id')->get();
    expect($events->pluck('event')->all())->toBe(['custom_field.created', 'custom_field.created', 'custom_field.updated', 'custom_field.moved',
        'custom_field.archived', 'custom_field.restored', 'custom_field.deleted'])
        ->and($events->every(fn (Activity $e) => $e->causer_id === $this->admin->id))->toBeTrue()
        ->and($events[2]->attribute_changes['old']['required'])->toBeFalse()
        ->and($events[2]->attribute_changes['attributes']['required'])->toBeTrue()
        ->and(array_keys($events[2]->attribute_changes['attributes']))->toBe(['required']);
});

test('tampered ids and kinds give a 404 or a message, never a 500', function () {
    $parking = customField('unit', 'choice', 'Parking', choices: ['Covered']);
    $sea = customField('unit', 'choice', 'View', choices: ['Sea'])->choices->sole();

    Livewire::actingAs($this->admin)->test(CustomFieldSettings::class)->call('edit', 'abc')->assertNotFound();
    Livewire::actingAs($this->admin)->test(CustomFieldSettings::class)->call('edit', $parking->id)->assertNotFound(); // the Tenants tab: another kind
    Livewire::actingAs($this->admin)->test(CustomFieldSettings::class)->set('entity', 'unit')->call('move', $parking->id, 5)->assertNotFound();
    Livewire::actingAs($this->admin)->test(CustomFieldSettings::class)->set('entity', 'invoice')->assertNotFound();
    Livewire::actingAs($this->admin)->test(CustomFieldSettings::class)->set('entity', 'unit')->call('edit', $parking->id)
        ->set('choices.0.id', $sea->id)->call('save')->assertHasErrors('choices.0.id');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/CustomFields/AdminTest.php`
Expected: FAIL with `Class "App\Actions\CustomFields\SaveCustomField" not found`.

- [ ] **Step 3: Create `app/Actions/CustomFields/SaveCustomField.php`:**

```php
<?php

namespace App\Actions\CustomFields;

use App\Audit\Audit;
use App\Enums\CustomFieldEntity;
use App\Enums\CustomFieldType;
use App\Enums\PermissionName;
use App\Models\CustomField;
use App\Models\CustomFieldChoice;
use App\Models\CustomFieldValue;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidatorInstance;

/** Spec 2026-10-21 custom fields §3: adds or changes a field and its choice list (plan rulings 9–11, 13). */
final class SaveCustomField
{
    /** @param  array<string, mixed>  $data  entity (new fields only), label_en, label_ar, type, help, required, in_portal, choices: list of {id, label_en, label_ar, hidden} */
    public function handle(User $actor, ?CustomField $field, array $data): CustomField
    {
        if (! $actor->can(PermissionName::SettingsManage)) {
            throw new AuthorizationException;
        }

        $data = self::clean($data);
        $choiceIds = $field ? $field->choices()->pluck('id')->all() : [];

        $validated = Validator::make($data, [
            'entity' => [$field ? 'nullable' : 'required', Rule::enum(CustomFieldEntity::class)],
            'label_en' => ['required', 'string', 'max:100'],
            'label_ar' => ['nullable', 'string', 'max:100'],
            'type' => ['required', Rule::enum(CustomFieldType::class)],
            'help' => ['nullable', 'string', 'max:255'],
            'required' => ['boolean'],
            'in_portal' => ['boolean'],
            'choices' => ['nullable', 'array', 'max:50', 'required_if:type,choice'],
            'choices.*' => ['array'],
            'choices.*.id' => ['nullable', 'integer', Rule::in($choiceIds)],
            'choices.*.label_en' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
            'choices.*.label_ar' => ['nullable', 'string', 'max:100'],
            'choices.*.hidden' => ['boolean'],
        ], [
            'choices.required_if' => __('A choice list needs at least one choice.'),
            'choices.*.id.in' => __('Choose one of the listed options.'),
        ], [
            'label_en' => __('Label (English)'), 'label_ar' => __('Label (Arabic)'), 'help' => __('Help text'),
            'choices.*.label_en' => __('Choice (English)'), 'choices.*.label_ar' => __('Choice (Arabic)'),
        ])->after(function (ValidatorInstance $validator) use ($field, $data) {
            if ($validator->errors()->isEmpty() && $field !== null && $data['type'] !== $field->type->value
                && CustomFieldValue::query()->where('custom_field_id', $field->id)->exists()) {
                $validator->errors()->add('type', __('The type cannot change once values are saved.'));
            }
        })->validate();

        return DB::transaction(function () use ($actor, $field, $validated) {
            $before = null;
            if ($field === null) {
                $entity = CustomFieldEntity::from((string) $validated['entity']);
                // Locks the kind's fields: one add at a time per kind, so the cap, the key and the position hold (plan rulings 9–10).
                $kind = CustomField::query()->where('entity', $entity)->lockForUpdate()->get(['id', 'position', 'archived_at']);
                if ($kind->whereNull('archived_at')->count() >= CustomField::MAX_ACTIVE) {
                    throw ValidationException::withMessages(['label_en' => __('At most :n active fields per record kind. Archive one first.', ['n' => CustomField::MAX_ACTIVE])]);
                }
                $field = (new CustomField)->forceFill([
                    'entity' => $entity, 'key' => CustomField::keyFor($entity, (string) $validated['label_en']),
                    'position' => (int) $kind->max('position') + 1, 'created_by' => $actor->id,
                ]);
            } else {
                $field = CustomField::query()->lockForUpdate()->findOrFail($field->id);
                $before = self::snapshot($field);
            }

            // Never the key: it is fixed at creation (plan ruling 9).
            $field->forceFill([
                'label_en' => $validated['label_en'], 'label_ar' => $validated['label_ar'] ?? null, 'type' => $validated['type'],
                'help' => $validated['help'] ?? null, 'required' => (bool) ($validated['required'] ?? false), 'in_portal' => (bool) ($validated['in_portal'] ?? false),
            ])->save();
            self::syncChoices($field, $field->type === CustomFieldType::Choice ? array_values((array) ($validated['choices'] ?? [])) : []);
            $after = self::snapshot($field->refresh());

            if ($before === null) {
                Audit::log('custom_field.created', $field, new: $after, causer: $actor);
            } else {
                $changed = array_keys(array_filter($after, fn (mixed $value, string $key) => $before[$key] !== $value, ARRAY_FILTER_USE_BOTH));
                if ($changed !== []) {
                    Audit::log('custom_field.updated', $field, Arr::only($before, $changed), Arr::only($after, $changed), causer: $actor);
                }
            }

            return $field;
        });
    }

    /** @param  list<mixed>  $rows  in order; an existing choice left out is hidden when a record holds it, deleted otherwise (spec §3) */
    private static function syncChoices(CustomField $field, array $rows): void
    {
        $keep = [];
        foreach ($rows as $i => $row) {
            $row = (array) $row;
            $choice = isset($row['id']) ? $field->choices()->findOrFail((int) $row['id']) : new CustomFieldChoice;
            $hidden = filter_var($row['hidden'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $choice->forceFill([
                'custom_field_id' => $field->id, 'label_en' => $row['label_en'], 'label_ar' => $row['label_ar'] ?? null, 'position' => $i + 1,
                'hidden_at' => $hidden ? ($choice->hidden_at ?? now()) : null,
            ])->save();
            $keep[] = $choice->id;
        }

        foreach ($field->choices()->whereNotIn('id', $keep)->get() as $gone) {
            // ponytail: a value saved between this check and the delete hits the RESTRICT foreign key; lock values if that race ever matters.
            if (CustomFieldValue::query()->where('choice_id', $gone->id)->exists()) {
                $gone->forceFill(['hidden_at' => $gone->hidden_at ?? now()])->save();
            } else {
                $gone->delete();
            }
        }
    }

    /** @return array<string, mixed> what the audit compares */
    private static function snapshot(CustomField $field): array
    {
        return [
            'label_en' => $field->label_en, 'label_ar' => $field->label_ar, 'type' => $field->type->value, 'help' => $field->help,
            'required' => $field->required, 'in_portal' => $field->in_portal,
            'choices' => $field->choices()->get()->map(fn (CustomFieldChoice $c) => $c->label_en.($c->label_ar ? ' / '.$c->label_ar : '')
                .($c->hidden_at ? ' ('.__('Hidden').')' : ''))->all(),
        ];
    }

    /**
     * Trimmed strings; blanks become null, at any depth.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private static function clean(array $data): array
    {
        return array_map(fn (mixed $v) => is_array($v) ? self::clean($v) : (is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v), $data);
    }
}
```

- [ ] **Step 4: Create the other three actions.** `app/Actions/CustomFields/SetCustomFieldArchived.php`:

```php
<?php

namespace App\Actions\CustomFields;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Models\CustomField;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Spec 2026-10-21 custom fields §3: archive hides a field and keeps its values; restore brings it back within the cap (plan ruling 10). */
final class SetCustomFieldArchived
{
    public function handle(User $actor, CustomField $field, bool $archived): void
    {
        if (! $actor->can(PermissionName::SettingsManage)) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($actor, $field, $archived) {
            $kind = CustomField::query()->where('entity', $field->entity)->lockForUpdate()->get();
            $field = $kind->firstWhere('id', $field->id) ?? abort(404);
            if ($archived === ($field->archived_at !== null)) {
                return;
            }
            if (! $archived && $kind->whereNull('archived_at')->count() >= CustomField::MAX_ACTIVE) {
                throw ValidationException::withMessages(['restore' => __('At most :n active fields per record kind. Archive one first.', ['n' => CustomField::MAX_ACTIVE])]);
            }

            $field->forceFill(['archived_at' => $archived ? now() : null])->save();
            Audit::log($archived ? 'custom_field.archived' : 'custom_field.restored', $field, causer: $actor);
        });
    }
}
```

`app/Actions/CustomFields/DeleteCustomField.php`:

```php
<?php

namespace App\Actions\CustomFields;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Spec 2026-10-21 custom fields §3: a field with no value ever saved can be deleted; its choices go with it (plan ruling 10). */
final class DeleteCustomField
{
    public function handle(User $actor, CustomField $field): void
    {
        if (! $actor->can(PermissionName::SettingsManage)) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($actor, $field) {
            $field = CustomField::query()->lockForUpdate()->findOrFail($field->id);
            // A value row exists once any value was saved, even if cleared since (plan ruling 1). The RESTRICT foreign key backs this up.
            if (CustomFieldValue::query()->where('custom_field_id', $field->id)->exists()) {
                throw ValidationException::withMessages(['delete' => __('This field has saved values: archive it instead.')]);
            }

            Audit::log('custom_field.deleted', $field, old: ['key' => $field->key, 'label_en' => $field->label_en, 'type' => $field->type->value], causer: $actor);
            $field->delete();
        });
    }
}
```

`app/Actions/CustomFields/MoveCustomField.php`:

```php
<?php

namespace App\Actions\CustomFields;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Models\CustomField;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** Spec 2026-10-21 custom fields §3 "order (up / down)": swaps a field with its active neighbour and renumbers the kind 1…n (plan ruling 10). */
final class MoveCustomField
{
    public function handle(User $actor, CustomField $field, int $by): void
    {
        if (! $actor->can(PermissionName::SettingsManage)) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($actor, $field, $by) {
            $ids = CustomField::query()->where('entity', $field->entity)->whereNull('archived_at')
                ->orderBy('position')->orderBy('id')->lockForUpdate()->pluck('id')->all();
            $from = array_search($field->id, $ids, true);
            $to = $from === false ? null : $from + ($by < 0 ? -1 : 1);
            if ($to === null || ! isset($ids[$to])) {
                return; // already first or last, or archived
            }

            [$ids[$from], $ids[$to]] = [$ids[$to], $ids[$from]];
            foreach ($ids as $n => $id) {
                CustomField::query()->whereKey($id)->update(['position' => $n + 1]);
            }
            Audit::log('custom_field.moved', $field, properties: ['direction' => $by < 0 ? 'up' : 'down'], causer: $actor);
        });
    }
}
```

- [ ] **Step 5: Create the page.** `app/Livewire/Admin/CustomFieldSettings.php`:

```php
<?php

namespace App\Livewire\Admin;

use App\Actions\CustomFields\DeleteCustomField;
use App\Actions\CustomFields\MoveCustomField;
use App\Actions\CustomFields\SaveCustomField;
use App\Actions\CustomFields\SetCustomFieldArchived;
use App\Enums\CustomFieldEntity;
use App\Enums\CustomFieldType;
use App\Enums\PermissionName;
use App\Livewire\Concerns\WithActor;
use App\Models\CustomField;
use App\Models\CustomFieldChoice;
use App\Models\CustomFieldValue;
use Closure;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Spec 2026-10-21 custom fields §3: Administration → Custom fields, one tab per record kind. Ids are looked up inside the tab's kind (plan ruling 14). */
class CustomFieldSettings extends Component
{
    use WithActor;

    #[Url]
    public string $entity = 'customer';

    #[Locked]
    public ?int $editingId = null;

    public bool $editing = false;

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var list<array<string, mixed>> */
    public array $choices = [];

    public function mount(): void
    {
        abort_unless($this->actor()->can(PermissionName::SettingsManage), 403);
    }

    public function updatedEntity(): void
    {
        $this->cancel();
    }

    public function create(): void
    {
        $this->resetErrorBag();
        $this->editingId = null;
        $this->form = ['label_en' => '', 'label_ar' => '', 'type' => CustomFieldType::Text->value, 'help' => '', 'required' => false, 'in_portal' => false];
        $this->choices = [];
        $this->editing = true;
    }

    public function edit(mixed $id): void
    {
        $field = $this->field($id);
        $this->resetErrorBag();
        $this->editingId = $field->id;
        $this->form = ['label_en' => $field->label_en, 'label_ar' => $field->label_ar ?? '', 'type' => $field->type->value, 'help' => $field->help ?? '',
            'required' => $field->required, 'in_portal' => $field->in_portal];
        $this->choices = $field->choices->map(fn (CustomFieldChoice $c) => ['id' => $c->id, 'label_en' => $c->label_en, 'label_ar' => $c->label_ar ?? '',
            'hidden' => $c->hidden_at !== null])->values()->all();
        $this->editing = true;
    }

    public function cancel(): void
    {
        $this->reset('editingId', 'editing', 'form', 'choices');
        $this->resetErrorBag();
    }

    public function addChoice(): void
    {
        $this->choices[] = ['id' => null, 'label_en' => '', 'label_ar' => '', 'hidden' => false];
    }

    public function removeChoice(mixed $index): void
    {
        if (is_int($index) || (is_string($index) && ctype_digit($index))) {
            unset($this->choices[(int) $index]);
            $this->choices = array_values($this->choices);
        }
    }

    public function save(SaveCustomField $save): void
    {
        abort_unless($this->editing, 404);
        try {
            $save->handle($this->actor(), $this->editingId !== null ? $this->field($this->editingId) : null,
                [...$this->form, 'entity' => $this->kind()->value, 'choices' => $this->choices]);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => [str_starts_with($k, 'choices') ? $k : "form.$k" => $m])->all());
        }

        $this->cancel();
        Flux::toast(variant: 'success', text: __('Field saved.'));
    }

    public function move(mixed $id, mixed $by, MoveCustomField $move): void
    {
        abort_unless(in_array($by, [-1, 1, '-1', '1'], true), 404);
        $this->run(fn () => $move->handle($this->actor(), $this->field($id), (int) $by));
    }

    public function archive(mixed $id, SetCustomFieldArchived $set): void
    {
        $this->run(fn () => $set->handle($this->actor(), $this->field($id), true), __('Field archived.'));
    }

    public function restore(mixed $id, SetCustomFieldArchived $set): void
    {
        $this->run(fn () => $set->handle($this->actor(), $this->field($id), false), __('Field restored.'));
    }

    public function delete(mixed $id, DeleteCustomField $delete): void
    {
        $this->run(fn () => $delete->handle($this->actor(), $this->field($id)), __('Field deleted.'));
    }

    private function run(Closure $action, ?string $done = null): void
    {
        try {
            $action();
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            $this->addError('list', (string) collect($e->errors())->flatten()->first());

            return;
        }

        if ($done !== null) {
            Flux::toast(variant: 'success', text: $done);
        }
    }

    private function kind(): CustomFieldEntity
    {
        return CustomFieldEntity::tryFrom($this->entity) ?? abort(404);
    }

    private function field(mixed $id): CustomField
    {
        abort_unless(is_int($id) || (is_string($id) && ctype_digit($id)), 404);

        return CustomField::query()->where('entity', $this->kind())->with('choices')->findOrFail((int) $id);
    }

    public function render(): View
    {
        $kind = $this->kind();

        return view('livewire.admin.custom-field-settings', [
            'kinds' => CustomFieldEntity::cases(),
            'kind' => $kind,
            'types' => CustomFieldType::cases(),
            'fields' => CustomField::query()->where('entity', $kind)->withExists('values')
                ->orderByRaw('archived_at IS NOT NULL')->orderBy('position')->orderBy('id')->get(),
            'typeLocked' => $this->editingId !== null && CustomFieldValue::query()->where('custom_field_id', $this->editingId)->exists(),
        ])->title(__('Custom fields'));
    }
}
```

`resources/views/livewire/admin/custom-field-settings.blade.php`:

```blade
<section class="w-full max-w-4xl space-y-6">
    <flux:heading size="xl" level="1">{{ __('Custom fields') }}</flux:heading>
    <flux:text>{{ __('Fields added here appear under "More details" on each record. Tenant, unit and agreement fields can show in the tenant portal; owner and building fields in the owner portal.') }}</flux:text>

    <div class="flex flex-wrap gap-2">
        @foreach ($kinds as $k)
            <flux:button size="sm" :variant="$k === $kind ? 'primary' : 'ghost'" wire:click="$set('entity', '{{ $k->value }}')">{{ $k->label() }}</flux:button>
        @endforeach
    </div>

    <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Label') }}</flux:table.column>
                <flux:table.column>{{ __('Key') }}</flux:table.column>
                <flux:table.column>{{ __('Type') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column />
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($fields as $field)
                    <flux:table.row :key="'f-'.$field->id">
                        <flux:table.cell>
                            {{ $field->label_en }}@if ($field->label_ar) · <span dir="rtl">{{ $field->label_ar }}</span>@endif
                            @if ($field->required)<flux:badge size="sm">{{ __('Required') }}</flux:badge>@endif
                            @if ($field->in_portal)<flux:badge size="sm" color="blue">{{ __('Show in portal') }}</flux:badge>@endif
                        </flux:table.cell>
                        <flux:table.cell class="font-mono text-xs">{{ $field->key }}</flux:table.cell>
                        <flux:table.cell>{{ $field->type->label() }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$field->archived_at ? 'zinc' : 'green'">{{ $field->archived_at ? __('Archived') : __('Active') }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">
                            @if ($field->archived_at === null)
                                <flux:button size="sm" variant="ghost" icon="arrow-up" wire:click="move({{ $field->id }}, -1)" :aria-label="__('Move up')" />
                                <flux:button size="sm" variant="ghost" icon="arrow-down" wire:click="move({{ $field->id }}, 1)" :aria-label="__('Move down')" />
                                <flux:button size="sm" wire:click="edit({{ $field->id }})">{{ __('Edit') }}</flux:button>
                                <flux:button size="sm" variant="ghost" wire:click="archive({{ $field->id }})">{{ __('Archive') }}</flux:button>
                            @else
                                <flux:button size="sm" variant="ghost" wire:click="restore({{ $field->id }})">{{ __('Restore') }}</flux:button>
                            @endif
                            @unless ($field->values_exists)
                                <flux:button size="sm" variant="ghost" wire:click="delete({{ $field->id }})" wire:confirm="{{ __('Delete this field?') }}">{{ __('Delete') }}</flux:button>
                            @endunless
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="5">{{ __('No fields yet.') }}</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
    <flux:error name="list" />

    @if ($editing)
        <form wire:submit="save" class="space-y-4 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg">{{ $editingId ? __('Edit field') : __('New field') }}</flux:heading>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="form.label_en" :label="__('Label (English)')" required />
                <flux:input wire:model="form.label_ar" :label="__('Label (Arabic)')" dir="rtl" />
                <flux:select wire:model.live="form.type" :label="__('Type')" :disabled="$typeLocked">
                    @foreach ($types as $type)<option value="{{ $type->value }}">{{ $type->label() }}</option>@endforeach
                </flux:select>
                <flux:input wire:model="form.help" :label="__('Help text')" />
            </div>
            <div class="flex flex-wrap gap-6">
                <flux:checkbox wire:model="form.required" :label="__('Required')" />
                <flux:checkbox wire:model="form.in_portal" :label="__('Show in portal')" />
            </div>
            @if (($form['type'] ?? '') === 'choice')
                <flux:fieldset>
                    <flux:legend>{{ __('Choices') }}</flux:legend>
                    <div class="space-y-2">
                        @foreach ($choices as $i => $choice)
                            <div class="flex flex-wrap items-end gap-2" wire:key="choice-{{ $i }}">
                                <flux:input wire:model="choices.{{ $i }}.label_en" :label="__('Choice (English)')" class="min-w-0 flex-1" />
                                <flux:input wire:model="choices.{{ $i }}.label_ar" :label="__('Choice (Arabic)')" dir="rtl" class="min-w-0 flex-1" />
                                <flux:checkbox wire:model="choices.{{ $i }}.hidden" :label="__('Hidden')" />
                                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeChoice({{ $i }})" :aria-label="__('Remove')" />
                                <flux:error name="choices.{{ $i }}.id" />
                            </div>
                        @endforeach
                        <flux:button size="sm" icon="plus" wire:click="addChoice">{{ __('Add choice') }}</flux:button>
                        <flux:error name="choices" />
                    </div>
                </flux:fieldset>
            @endif
            <div class="flex gap-2">
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                <flux:button wire:click="cancel">{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    @else
        <flux:button variant="primary" icon="plus" wire:click="create">{{ __('Add field') }}</flux:button>
    @endif
</section>
```

- [ ] **Step 6: Route and sidebar.** In `routes/admin.php` add `use App\Livewire\Admin\CustomFieldSettings;` to the imports, and after the `banks` route:

```php
    Route::livewire('custom-fields', CustomFieldSettings::class)->middleware('can:settings.manage')->name('custom-fields');
```

In `resources/views/layouts/app/sidebar.blade.php`, after the `admin.banks` sidebar item, add:

```blade
                            <flux:sidebar.item icon="adjustments-horizontal" :href="route('admin.custom-fields')" :current="request()->routeIs('admin.custom-fields')" wire:navigate>{{ __('Custom fields') }}</flux:sidebar.item>
```

- [ ] **Step 7: Arabic.** In `lang/ar.json`, after `    "Credit refund reversal": "عكس استرداد رصيد",` insert:

```json
    "Custom fields": "الحقول المخصصة",
    "Fields added here appear under \"More details\" on each record. Tenant, unit and agreement fields can show in the tenant portal; owner and building fields in the owner portal.": "تظهر الحقول المضافة هنا تحت \"تفاصيل إضافية\" في كل سجل. يمكن عرض حقول المستأجرين والوحدات والعقود في بوابة المستأجر، وحقول الملاك والعقارات في بوابة المالك.",
    "Label": "التسمية",
    "Key": "المفتاح",
    "Type": "النوع",
    "Status": "الحالة",
    "Required": "إلزامي",
    "Show in portal": "عرض في البوابة",
    "Archived": "مؤرشف",
    "Move up": "نقل لأعلى",
    "Move down": "نقل لأسفل",
    "Edit": "تعديل",
    "Archive": "أرشفة",
    "Restore": "استعادة",
    "Delete": "حذف",
    "Delete this field?": "حذف هذا الحقل؟",
    "No fields yet.": "لا توجد حقول بعد.",
    "Edit field": "تعديل الحقل",
    "New field": "حقل جديد",
    "Label (English)": "التسمية (بالإنجليزية)",
    "Label (Arabic)": "التسمية (بالعربية)",
    "Help text": "نص المساعدة",
    "Choices": "الاختيارات",
    "Choice (English)": "الاختيار (بالإنجليزية)",
    "Choice (Arabic)": "الاختيار (بالعربية)",
    "Hidden": "مخفي",
    "Remove": "إزالة",
    "Add choice": "إضافة اختيار",
    "Save": "حفظ",
    "Cancel": "إلغاء",
    "Add field": "إضافة حقل",
    "Field saved.": "تم حفظ الحقل.",
    "Field archived.": "تمت أرشفة الحقل.",
    "Field restored.": "تمت استعادة الحقل.",
    "Field deleted.": "تم حذف الحقل.",
    "A choice list needs at least one choice.": "تحتاج قائمة الاختيارات إلى اختيار واحد على الأقل.",
    "Choose one of the listed options.": "اختر أحد الخيارات المدرجة.",
    "The type cannot change once values are saved.": "لا يمكن تغيير النوع بعد حفظ القيم.",
    "At most :n active fields per record kind. Archive one first.": "الحد الأقصى :n حقلاً نشطاً لكل نوع سجل. أرشف حقلاً أولاً.",
    "This field has saved values: archive it instead.": "لهذا الحقل قيم محفوظة: أرشفه بدلاً من حذفه.",
```

- [ ] **Step 8: Run the tests.**

Run: `php artisan test tests/Feature/CustomFields/AdminTest.php`
Expected: PASS.

- [ ] **Step 9: Gates and commit.**

Run: `vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add app/Actions/CustomFields app/Livewire/Admin/CustomFieldSettings.php resources/views/livewire/admin/custom-field-settings.blade.php \
  routes/admin.php resources/views/layouts/app/sidebar.blade.php lang/ar.json tests/Feature/CustomFields/AdminTest.php
git commit -m "$(cat <<'EOF'
Administration custom fields page: add and change fields per record kind, type locked once used, used choices hidden, order, archive and restore within the 30-field cap, delete before any value, all audited

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Values on records: the shared service, the More details section and box, wired into tenants

**Files:**
- Create: `app/CustomFields/CustomFields.php`
- Create: `resources/views/components/custom-fields/section.blade.php`, `resources/views/components/custom-fields/display.blade.php`
- Modify: `app/Actions/Customers/SaveCustomer.php`, `app/Livewire/Customers/Form.php`, `resources/views/livewire/customers/form.blade.php`
- Modify: `app/Livewire/Documents/Panel.php`, `lang/ar.json`
- Test: `tests/Feature/CustomFields/ValuesTest.php`

**Interfaces:**
- Consumes: Task 1 models and enums, `StoreDocument::handle(User, Model, UploadedFile, DocumentCategory)` and `StoreDocument::MAX_KB`, `Fils`, `Audit::log`.
- Produces (all static on `App\CustomFields\CustomFields`):
  - `NUMBER_PATTERN`, `FILE_MIMES`.
  - `active(CustomFieldEntity): Collection<int, CustomField>`.
  - `values(Model): Collection<int, CustomFieldValue>` (keyed by field id).
  - `validation(CustomFieldEntity, ?Model, array $data, bool $enforceRequired = true): array{0: array<string, mixed>, 1: array<string, string>, 2: array<string, string>}`.
  - `save(User, Model, array $values): void`.
  - `text(CustomField, CustomFieldValue, ?string $locale = null): ?string`.
  - `number(string): string`.
  - `formValues(CustomFieldEntity, ?Model): array<string, string|null>`.
  - `documents(?Model): array<string, Document>`.
  - `display(Model, bool $portal = false): list<array{label: string, text: string, document: Document|null}>`.
- Components: `<x-custom-fields.section :fields :values :documents />` and `<x-custom-fields.display :items :file-route="'documents.view'" />`.
- Livewire forms hold `public array $custom` and use `WithFileUploads`. They pass `[...$this->form, 'custom' => $this->custom]` and keep `custom.*` error keys as they are.
- Audit event `custom_fields.changed`.

- [ ] **Step 1: Write the failing test** `tests/Feature/CustomFields/ValuesTest.php`:

```php
<?php

use App\Actions\Customers\SaveCustomer;
use App\CustomFields\CustomFields;
use App\Enums\DocumentCategory;
use App\Enums\RoleName;
use App\Livewire\Customers\Form;
use App\Livewire\Documents\Panel;
use App\Models\Building;
use App\Models\Customer;
use App\Models\CustomFieldValue;
use App\Models\Document;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    $this->leasing = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Leasing);
    $this->person = [
        'type' => 'individual', 'name_en' => 'Mohammed Ali', 'id_type' => 'cpr', 'id_number' => '900101234', 'mobile' => '+97333112233',
    ];
    customField('customer', 'text', 'Employer', ['required' => true]);
    customField('customer', 'long_text', 'Notes for staff');
    customField('customer', 'number', 'Monthly income');
    customField('customer', 'date', 'Visa expiry');
    customField('customer', 'bool', 'Has pets');
    $source = customField('customer', 'choice', 'Source', choices: ['Walk-in', 'Agent', ['Website', 'الموقع']]);
    [$this->walkIn, $this->agent, $this->website] = $source->choices->all();
    $this->letter = customField('customer', 'file', 'Work letter');
});

test('the tenant form saves every type with the same Save, and the values read back exactly', function () {
    Livewire::actingAs($this->leasing)->test(Form::class)
        ->set('form.name_en', 'Aisha Noor')->set('form.id_type', 'passport')->set('form.id_number', 'P1234567')->set('form.mobile', '+97339998877')
        ->set('custom.employer', '  Bapco  ')
        ->set('custom.notes_for_staff', "Line one\n<b>Line two</b>")
        ->set('custom.monthly_income', '123456789012.5')
        ->set('custom.visa_expiry', '2027-03-31')
        ->set('custom.has_pets', '0')
        ->set('custom.source', (string) $this->website->id)
        ->set('custom.work_letter', UploadedFile::fake()->create('letter.pdf', 20, 'application/pdf'))
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $customer = Customer::query()->where('id_number', 'P1234567')->sole();
    $income = CustomFieldValue::query()->where('entity_id', $customer->id)->whereNotNull('value_number')->sole();
    expect($income->value_number)->toBe('123456789012.500')
        ->and(array_column(CustomFields::display($customer), 'text', 'label'))->toBe([
            'Employer' => 'Bapco', 'Notes for staff' => "Line one\n<b>Line two</b>", 'Monthly income' => '123456789012.5', 'Visa expiry' => '31/03/2027',
            'Has pets' => 'No', 'Source' => 'Website', 'Work letter' => 'letter.pdf',
        ])
        ->and(Document::query()->where('documentable_id', $customer->id)->sole()->category)->toBe(DocumentCategory::CustomField);

    $audit = Activity::query()->where('event', 'custom_fields.changed')->sole();
    expect($audit->causer_id)->toBe($this->leasing->id)->and($audit->subject_id)->toBe($customer->id)
        ->and($audit->attribute_changes['attributes']['Monthly income'])->toBe('123456789012.5')
        ->and($audit->attribute_changes['old']['Monthly income'])->toBeNull();
});

test('long text is escaped; people who cannot edit see a read-only box, editors the inputs', function () {
    $customer = app(SaveCustomer::class)->handle($this->leasing, null, [...$this->person, 'custom' => ['employer' => 'Bapco', 'notes_for_staff' => '<b>Bold</b>']]);
    $finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);

    $this->actingAs($finance)->get(route('customers.edit', $customer))->assertOk()
        ->assertSeeInOrder(['More details', 'Employer', 'Bapco', 'Notes for staff', '&lt;b&gt;Bold&lt;/b&gt;'], false)
        ->assertDontSee('<b>Bold</b>', false)
        ->assertDontSee('Visa expiry'); // empty and optional: left out
    $this->actingAs($this->leasing)->get(route('customers.edit', $customer))->assertOk()
        ->assertSee('Visa expiry')->assertDontSee('<b>Bold</b>', false);
});

test('each type is validated and tampered values give messages, never a 500', function (string $key, mixed $value, string $message) {
    expect(fn () => app(SaveCustomer::class)->handle($this->leasing, null, [...$this->person, 'custom' => ['employer' => 'Bapco', $key => $value]]))
        ->toThrow(ValidationException::class, $message);
})->with([
    'text too long' => ['employer', str_repeat('x', 256), 'The Employer field must not be greater than 255 characters.'],
    'text as a list' => ['employer', ['a'], 'The Employer field must be a string.'],
    'required left blank' => ['employer', '   ', 'The Employer field is required.'],
    'long text too long' => ['notes_for_staff', str_repeat('x', 2001), 'The Notes for staff field must not be greater than 2000 characters.'],
    'four decimals' => ['monthly_income', '1.2345', 'Enter Monthly income as a number with up to 3 decimals.'],
    'thirteen digits' => ['monthly_income', '1234567890123', 'Enter Monthly income as a number with up to 3 decimals.'],
    'exponent' => ['monthly_income', '1e5', 'Enter Monthly income as a number with up to 3 decimals.'],
    'not a date' => ['visa_expiry', '31/02/2027', 'The Visa expiry field must match the format Y-m-d.'],
    'not yes or no' => ['has_pets', 'maybe', 'The Has pets field must be true or false.'],
    'unknown choice' => ['source', '999999', 'Choose Source from the list.'],
    'choice by word' => ['source', 'Agent', 'The Source field must be an integer.'],
]);

test('files are JPG, PNG, WebP or PDF up to 10 MB; a whole tampered section is a message; unknown keys are ignored', function () {
    $save = fn (array $custom) => app(SaveCustomer::class)->handle($this->leasing, null, [...$this->person, 'custom' => $custom]);

    expect(fn () => $save(['employer' => 'Bapco', 'work_letter' => UploadedFile::fake()->create('virus.exe', 5)]))
        ->toThrow(ValidationException::class, 'Upload Work letter as a JPG, PNG, WebP or PDF file.')
        ->and(fn () => $save(['employer' => 'Bapco', 'work_letter' => UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf')]))
        ->toThrow(ValidationException::class, 'The Work letter field must not be greater than 10240 kilobytes.')
        ->and(fn () => $save(['employer' => 'Bapco', 'work_letter' => 'not-a-file']))->toThrow(ValidationException::class, 'The Work letter field must be a file.');
    expect(fn () => app(SaveCustomer::class)->handle($this->leasing, null, [...$this->person, 'custom' => 'abc']))
        ->toThrow(ValidationException::class, 'The More details field must be an array.');

    $customer = $save(['employer' => 'Bapco', 'not_a_field' => 'x']);
    expect(CustomFieldValue::query()->where('entity_id', $customer->id)->count())->toBe(1);

    Livewire::actingAs($this->leasing)->test(Form::class, ['customer' => $customer])
        ->set('custom.source', ['x'])->set('custom.visa_expiry', ['y'])->call('save')
        ->assertHasErrors(['custom.source', 'custom.visa_expiry']);
});

test('a hidden choice is refused for new picks but a tenant holding it keeps it; a new required field waits for the next edit', function () {
    $customer = app(SaveCustomer::class)->handle($this->leasing, null, [...$this->person, 'custom' => ['employer' => 'Bapco', 'source' => (string) $this->agent->id]]);
    $this->agent->forceFill(['hidden_at' => now()])->save();

    app(SaveCustomer::class)->handle($this->leasing, $customer, [...$this->person, 'custom' => ['employer' => 'Bapco', 'source' => (string) $this->agent->id]]);
    expect(fn () => app(SaveCustomer::class)->handle($this->leasing, null, [...$this->person, 'id_number' => '900109999',
        'custom' => ['employer' => 'Alba', 'source' => (string) $this->agent->id]]))->toThrow(ValidationException::class, 'Choose Source from the list.');
    Livewire::actingAs($this->leasing)->test(Form::class, ['customer' => $customer])->assertSee('Agent'); // still offered to the tenant holding it

    customField('customer', 'text', 'Badge number', ['required' => true]);
    expect(array_column(CustomFields::display($customer), 'text', 'label')['Badge number'])->toBe('—'); // shown missing, nothing blocked
    expect(fn () => app(SaveCustomer::class)->handle($this->leasing, $customer, [...$this->person, 'custom' => ['employer' => 'Bapco']]))
        ->toThrow(ValidationException::class, 'The Badge number field is required.');
});

test('a new file replaces the field\'s file and the old one stays among the tenant\'s documents; downloads follow the tenant\'s permissions', function () {
    $customer = app(SaveCustomer::class)->handle($this->leasing, null, [...$this->person, 'custom' => ['employer' => 'Bapco', 'work_letter' => UploadedFile::fake()->create('2025.pdf', 10, 'application/pdf')]]);
    app(SaveCustomer::class)->handle($this->leasing, $customer, [...$this->person, 'custom' => ['employer' => 'Bapco', 'work_letter' => UploadedFile::fake()->image('2026.jpg')]]);
    app(SaveCustomer::class)->handle($this->leasing, $customer, [...$this->person, 'custom' => ['employer' => 'Bapco', 'work_letter' => null]]); // no new file: kept

    $documents = Document::query()->where('documentable_type', $customer->getMorphClass())->where('documentable_id', $customer->id)->orderBy('id')->get();
    expect($documents->pluck('original_name')->all())->toBe(['2025.pdf', '2026.jpg'])
        ->and($documents->pluck('category')->unique()->values()->all())->toBe([DocumentCategory::CustomField])
        ->and(CustomFieldValue::query()->where('custom_field_id', $this->letter->id)->sole()->document_id)->toBe($documents[1]->id);

    $audit = Activity::query()->where('event', 'custom_fields.changed')->latest('id')->first();
    expect($audit->attribute_changes['old'])->toBe(['Work letter' => '2025.pdf'])
        ->and($audit->attribute_changes['attributes'])->toBe(['Work letter' => '2026.jpg']);

    // The panel cannot make Custom field documents itself.
    Livewire::actingAs($this->leasing)->test(Panel::class, ['documentable' => $customer])
        ->set('upload', UploadedFile::fake()->create('x.pdf', 5, 'application/pdf'))->set('category', 'custom_field')->call('save')
        ->assertHasErrors('category');

    // Staff download: whoever can view the tenant, building scope as today.
    $building = Building::factory()->create();
    activeAgreement(['customer_id' => $customer->id], [Unit::factory()->for($building)->create()]);
    $this->actingAs($this->leasing)->get(route('documents.download', $documents[1]))->assertForbidden();
    $this->leasing->buildings()->attach($building->id);
    $this->actingAs($this->leasing)->get(route('documents.download', $documents[1]))->assertOk();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/CustomFields/ValuesTest.php`
Expected: FAIL with `Class "App\CustomFields\CustomFields" not found`.

- [ ] **Step 3: Create `app/CustomFields/CustomFields.php`:**

```php
<?php

namespace App\CustomFields;

use App\Actions\Documents\StoreDocument;
use App\Audit\Audit;
use App\Enums\CustomFieldEntity;
use App\Enums\CustomFieldType;
use App\Enums\DocumentCategory;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Document;
use App\Models\User;
use App\Support\Fils;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Spec 2026-10-21 custom fields §4–§5: the one implementation of "More details" for the five record kinds. A record's
 * save action merges validation() into its own validator and calls save() inside its own transaction (plan ruling 2).
 */
final class CustomFields
{
    /** Plan ruling 1: 12 integer digits and up to 3 decimals, exactly what DECIMAL(15,3) holds. */
    public const string NUMBER_PATTERN = '/^-?\d{1,12}(\.\d{1,3})?$/';

    /** Spec §4: file fields take jpg, png, webp or pdf. */
    public const string FILE_MIMES = 'jpg,jpeg,png,webp,pdf';

    /** @return Collection<int, CustomField> the kind's active fields in order, with their choices */
    public static function active(CustomFieldEntity $entity): Collection
    {
        return CustomField::query()->where('entity', $entity)->whereNull('archived_at')->with('choices')->orderBy('position')->orderBy('id')->get();
    }

    /** @return Collection<int, CustomFieldValue> the record's values keyed by field id, with the choice and the (not deleted) document */
    public static function values(Model $record): Collection
    {
        return CustomFieldValue::query()->where('entity', CustomFieldEntity::of($record))->where('entity_id', $record->getKey())
            ->with(['choice', 'document'])->get()->keyBy('custom_field_id');
    }

    /**
     * Rules, messages and attribute names to spread into a record save's own validator. Nothing when $data has no
     * "custom" key: such a caller is not touching custom fields (plan ruling 2). The import passes $enforceRequired
     * false (spec §5).
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: array<string, string>, 2: array<string, string>}
     */
    public static function validation(CustomFieldEntity $entity, ?Model $record, array $data, bool $enforceRequired = true): array
    {
        if (! array_key_exists('custom', $data)) {
            return [[], [], []];
        }

        $values = $record?->exists ? self::values($record) : new Collection;
        $rules = ['custom' => ['array']];
        $names = ['custom' => (string) __('More details')];
        foreach (self::active($entity) as $field) {
            $held = $values->get($field->id);
            $required = $enforceRequired && $field->required ? 'required' : 'nullable';
            $rules['custom.'.$field->key] = match ($field->type) {
                CustomFieldType::Text => ['bail', $required, 'string', 'max:255'],
                CustomFieldType::LongText => ['bail', $required, 'string', 'max:2000'],
                CustomFieldType::Number => ['bail', $required, 'regex:'.self::NUMBER_PATTERN],
                CustomFieldType::Date => ['bail', $required, 'date_format:Y-m-d'],
                CustomFieldType::Bool => ['bail', $required, 'boolean'],
                // A hidden choice is no longer offered, but a record holding it keeps it (spec §4, plan ruling 11).
                CustomFieldType::Choice => ['bail', $required, 'integer', Rule::in([
                    ...$field->choices->whereNull('hidden_at')->modelKeys(), ...array_filter([$held?->choice_id]),
                ])],
                // A record that has a file keeps it when no new one is sent (plan ruling 4).
                CustomFieldType::File => ['bail', $held?->document !== null ? 'nullable' : $required, 'file', 'mimes:'.self::FILE_MIMES, 'max:'.StoreDocument::MAX_KB],
            };
            $names['custom.'.$field->key] = $field->label();
        }

        return [$rules, [
            'custom.*.regex' => (string) __('Enter :attribute as a number with up to 3 decimals.'),
            'custom.*.in' => (string) __('Choose :attribute from the list.'),
            'custom.*.mimes' => (string) __('Upload :attribute as a JPG, PNG, WebP or PDF file.'),
        ], $names];
    }

    /**
     * Writes the sent values of the kind's active fields in the caller's transaction (plan ruling 2). A key not sent is
     * left alone, and a blank clears. A file is stored as a "Custom field" document on the record and becomes the
     * field's file; the old one stays among the record's documents (plan ruling 4). Every change is audited once per
     * save, with old and new text (plan ruling 13).
     *
     * ponytail: a retried transaction (SaveCustomer's attempts: 3) stores a sent file again; harmless, the value points at the last one.
     *
     * @param  array<string, mixed>  $values  validated, keyed by field key
     */
    public static function save(User $actor, Model $record, array $values): void
    {
        $entity = CustomFieldEntity::of($record);
        $existing = self::values($record);
        $old = [];
        $new = [];
        foreach (self::active($entity) as $field) {
            if (! array_key_exists($field->key, $values)) {
                continue;
            }
            $column = $field->type->column();
            $row = $existing->get($field->id);
            $value = self::clean($field, $values[$field->key]);
            if ($field->type === CustomFieldType::File) {
                if (! $value instanceof UploadedFile) {
                    continue; // no new upload: the file stays (clearing it is deleting the document, plan ruling 4)
                }
                $value = app(StoreDocument::class)->handle($actor, $record, $value, DocumentCategory::CustomField)->id;
            }
            $raw = $row?->getRawOriginal($column);
            $same = $raw === null ? $value === null : ($value !== null && (string) $raw === (string) $value);
            if ($same) {
                continue;
            }

            $before = $row === null ? null : self::text($field, $row, 'en');
            $row ??= (new CustomFieldValue)->forceFill(['custom_field_id' => $field->id, 'entity' => $entity, 'entity_id' => $record->getKey()]);
            $row->forceFill([$column => $value, 'updated_by' => $actor->id])->save();
            $row->unsetRelations();
            $old[$field->label_en] = $before;
            $new[$field->label_en] = self::text($field, $row, 'en');
        }

        if ($new !== []) {
            Audit::log('custom_fields.changed', $record, $old, $new, causer: $actor);
        }
    }

    /** A value as people read it: numbers without trailing zeros, dates dd/mm/yyyy, yes/no and choices in $locale. */
    public static function text(CustomField $field, CustomFieldValue $value, ?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();
        if ($value->getAttribute($field->type->column()) === null) {
            return null;
        }

        return match ($field->type) {
            CustomFieldType::Text, CustomFieldType::LongText => $value->value_text,
            CustomFieldType::Number => self::number((string) $value->value_number),
            CustomFieldType::Date => $value->value_date?->format('d/m/Y'),
            CustomFieldType::Bool => (string) ($value->value_bool ? __('Yes', [], $locale) : __('No', [], $locale)),
            CustomFieldType::Choice => $value->choice?->label($locale),
            CustomFieldType::File => $value->document?->original_name,
        };
    }

    /** "12.500" → "12.5", "1200.000" → "1200": the stored decimal string, trimmed, never through a float. */
    public static function number(string $decimal): string
    {
        return str_contains($decimal, '.') ? rtrim(rtrim($decimal, '0'), '.') : $decimal;
    }

    /** @return array<string, string|null> the form's starting values, keyed by field key; files start empty */
    public static function formValues(CustomFieldEntity $entity, ?Model $record): array
    {
        $values = $record?->exists ? self::values($record) : new Collection;
        $form = [];
        foreach (self::active($entity) as $field) {
            $value = $values->get($field->id);
            $form[$field->key] = match (true) {
                $value === null, $field->type === CustomFieldType::File => null,
                $field->type === CustomFieldType::Number => $value->value_number === null ? null : self::number($value->value_number),
                $field->type === CustomFieldType::Date => $value->value_date?->toDateString(),
                $field->type === CustomFieldType::Bool => $value->value_bool === null ? null : ($value->value_bool ? '1' : '0'),
                $field->type === CustomFieldType::Choice => $value->choice_id === null ? null : (string) $value->choice_id,
                default => $value->value_text,
            };
        }

        return $form;
    }

    /** @return array<string, Document> the current file of each active file field that has one, keyed by field key */
    public static function documents(?Model $record): array
    {
        if ($record === null || ! $record->exists) {
            return [];
        }
        $values = self::values($record);
        $documents = [];
        foreach (self::active(CustomFieldEntity::of($record)) as $field) {
            $document = $field->type === CustomFieldType::File ? $values->get($field->id)?->document : null;
            if ($document !== null) {
                $documents[$field->key] = $document;
            }
        }

        return $documents;
    }

    /**
     * The read-only "More details" of a record: active fields in order, in the viewer's language. Staff see an empty
     * required field as "—" and no empty optional one; portals ($portal) show only filled "Show in portal" fields
     * (plan rulings 8 and 12).
     *
     * @return list<array{label: string, text: string, document: Document|null}>
     */
    public static function display(Model $record, bool $portal = false): array
    {
        $values = self::values($record);
        $items = [];
        foreach (self::active(CustomFieldEntity::of($record)) as $field) {
            if ($portal && ! $field->in_portal) {
                continue;
            }
            $value = $values->get($field->id);
            $text = $value === null ? null : self::text($field, $value);
            if ($text === null && ($portal || ! $field->required)) {
                continue;
            }
            $items[] = ['label' => $field->label(), 'text' => $text ?? '—', 'document' => $field->type === CustomFieldType::File ? $value?->document : null];
        }

        return $items;
    }

    /** A validated input as its column stores it (plan ruling 1); blank is null. */
    private static function clean(CustomField $field, mixed $value): mixed
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return match ($field->type) {
            CustomFieldType::Text, CustomFieldType::LongText => trim((string) $value),
            CustomFieldType::Number => Fils::toDecimal(Fils::fromDecimal(trim((string) $value))), // exact: integer thousandths, never a float
            CustomFieldType::Date => (string) $value,
            CustomFieldType::Bool => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            CustomFieldType::Choice => (int) $value,
            CustomFieldType::File => $value,
        };
    }
}
```

- [ ] **Step 4: Create the two components.** `resources/views/components/custom-fields/section.blade.php`:

```blade
@props(['fields', 'values' => [], 'documents' => []])
{{-- Spec 2026-10-21 custom fields §4: the "More details" inputs, the same for all five record kinds (plan ruling 2). --}}
@if ($fields->isNotEmpty())
    <flux:fieldset class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:legend>{{ __('More details') }}</flux:legend>
        <div class="grid gap-4 sm:grid-cols-2">
            @foreach ($fields as $field)
                @php
                    $model = 'custom.'.$field->key;
                    $current = $values[$field->key] ?? null;
                    $held = is_scalar($current) ? (string) $current : '';
                    $wide = in_array($field->type, [App\Enums\CustomFieldType::LongText, App\Enums\CustomFieldType::File], true);
                @endphp
                <div wire:key="cf-{{ $field->id }}" @class(['min-w-0', 'sm:col-span-2' => $wide])>
                    @switch($field->type)
                        @case(App\Enums\CustomFieldType::LongText)
                            <flux:textarea wire:model="{{ $model }}" :label="$field->label()" :description="$field->help" rows="3" />
                            @break
                        @case(App\Enums\CustomFieldType::Number)
                            <flux:input wire:model="{{ $model }}" :label="$field->label()" :description="$field->help" inputmode="decimal" />
                            @break
                        @case(App\Enums\CustomFieldType::Date)
                            <x-date-input wire:model="{{ $model }}" :label="$field->label()" :description="$field->help" />
                            @break
                        @case(App\Enums\CustomFieldType::Bool)
                            <flux:select wire:model="{{ $model }}" :label="$field->label()" :description="$field->help">
                                <option value="">—</option>
                                <option value="1">{{ __('Yes') }}</option>
                                <option value="0">{{ __('No') }}</option>
                            </flux:select>
                            @break
                        @case(App\Enums\CustomFieldType::Choice)
                            <flux:select wire:model="{{ $model }}" :label="$field->label()" :description="$field->help">
                                <option value="">—</option>
                                @foreach ($field->choices as $choice)
                                    @if ($choice->hidden_at === null || (string) $choice->id === $held)
                                        <option value="{{ $choice->id }}">{{ $choice->label() }}</option>
                                    @endif
                                @endforeach
                            </flux:select>
                            @break
                        @case(App\Enums\CustomFieldType::File)
                            <flux:field>
                                <flux:label>{{ $field->label() }}</flux:label>
                                <flux:description>{{ $field->help ?: __('JPG, PNG, WebP or PDF, max 10 MB') }}</flux:description>
                                <x-file-button wire:model="{{ $model }}" accept=".jpg,.jpeg,.png,.webp,.pdf" />
                                @isset($documents[$field->key])
                                    <flux:text size="sm">
                                        {{ __('Current file: :name', ['name' => $documents[$field->key]->original_name]) }}
                                        <flux:link :href="route('documents.view', $documents[$field->key])" target="_blank">{{ __('View') }}</flux:link>
                                    </flux:text>
                                @endisset
                                <flux:error :name="$model" />
                            </flux:field>
                            @break
                        @default
                            <flux:input wire:model="{{ $model }}" :label="$field->label()" :description="$field->help" />
                    @endswitch
                </div>
            @endforeach
        </div>
    </flux:fieldset>
@endif
```

`resources/views/components/custom-fields/display.blade.php`:

```blade
@props(['items', 'fileRoute' => 'documents.view'])
{{-- Spec 2026-10-21 custom fields §4–§5: read-only "More details", escaped; long text keeps its line breaks, never HTML (plan ruling 12). --}}
@if ($items !== [])
    <flux:card class="space-y-2">
        <flux:heading size="lg" level="2">{{ __('More details') }}</flux:heading>
        <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
            @foreach ($items as $item)
                <div class="min-w-0">
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ $item['label'] }}</dt>
                    <dd class="break-words whitespace-pre-line">
                        @if ($item['document'])
                            <flux:link :href="route($fileRoute, $item['document'])">{{ $item['text'] }}</flux:link>
                        @else
                            {{ $item['text'] }}
                        @endif
                    </dd>
                </div>
            @endforeach
        </dl>
    </flux:card>
@endif
```

- [ ] **Step 5: `SaveCustomer` saves the section in its transaction.** In `app/Actions/Customers/SaveCustomer.php`:
  - Add to the imports:

```php
use App\CustomFields\CustomFields;
use App\Enums\CustomFieldEntity;
use Illuminate\Support\Arr;
```

  - Replace

```php
        $validated = Validator::make($data, [
```

with

```php
        // Spec 2026-10-21 custom fields §4: "More details" is validated with the record (plan ruling 2).
        [$customRules, $customMessages, $customNames] = CustomFields::validation(CustomFieldEntity::Customer, $customer, $data);

        $validated = Validator::make($data, [
```

  - Replace

```php
            'no_reminders' => ['sometimes', 'boolean'], // spec 2026-10-17 §6: "Don't send reminders"
        ])->after(function (ValidatorInstance $validator) use ($actor, $data, $customer) {
```

with

```php
            'no_reminders' => ['sometimes', 'boolean'], // spec 2026-10-17 §6: "Don't send reminders"
            ...$customRules,
        ], $customMessages, $customNames)->after(function (ValidatorInstance $validator) use ($actor, $data, $customer) {
```

  - Replace

```php
        $id = $customer?->id;
```

with

```php
        $custom = Arr::pull($validated, 'custom');
        $id = $customer?->id;
```

  - Replace

```php
            return DB::transaction(function () use ($id, $validated) {
                $customer = $id !== null ? Customer::query()->lockForUpdate()->findOrFail($id) : new Customer;
                $customer->fill($validated)->save();
                SyncPortalEmail::apply($customer);
```

with

```php
            return DB::transaction(function () use ($actor, $id, $validated, $custom) {
                $customer = $id !== null ? Customer::query()->lockForUpdate()->findOrFail($id) : new Customer;
                $customer->fill($validated)->save();
                SyncPortalEmail::apply($customer);
                if (is_array($custom)) {
                    CustomFields::save($actor, $customer, $custom);
                }
```

- [ ] **Step 6: The tenant form.** In `app/Livewire/Customers/Form.php`:
  - Add to the imports:

```php
use App\CustomFields\CustomFields;
use App\Enums\CustomFieldEntity;
use Livewire\WithFileUploads;
```

  - Replace `    use WithActor;` with `    use WithActor, WithFileUploads;`.
  - After the `public array $form = …;` line, add:

```php

    /** @var array<string, mixed> spec 2026-10-21 custom fields §4: "More details", field key => value (files are uploads) */
    public array $custom = [];
```

  - At the end of `mount()`, after the closing `}` of the `if … else` block, add:

```php
        $this->custom = CustomFields::formValues(CustomFieldEntity::Customer, $this->customerId !== null ? $customer : null);
```

  - In `save()`, replace

```php
            $customer = $save->handle($this->actor(), $this->customerId ? Customer::findOrFail($this->customerId) : null, $this->form);
```

with

```php
            $customer = $save->handle($this->actor(), $this->customerId ? Customer::findOrFail($this->customerId) : null, [...$this->form, 'custom' => $this->custom]);
```

and replace

```php
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ["form.$k" => $m])->all());
```

with

```php
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => [str_starts_with($k, 'custom') ? $k : "form.$k" => $m])->all());
```

  - In `render()`, after `'canEdit' => $customer ? $this->actor()->can('update', $customer) : true,` add:

```php
            'customFields' => CustomFields::active(CustomFieldEntity::Customer),
            'customDocuments' => CustomFields::documents($customer),
            'customDetails' => $customer ? CustomFields::display($customer) : [],
```

In `resources/views/livewire/customers/form.blade.php`, replace

```blade
        @if ($canEdit)
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        @endif
    </form>
```

with

```blade
        @if ($canEdit)
            <x-custom-fields.section :fields="$customFields" :values="$custom" :documents="$customDocuments" />
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        @endif
    </form>
    @unless ($canEdit)
        <x-custom-fields.display :items="$customDetails" />
    @endunless
```

- [ ] **Step 7: The documents panel cannot pick the new category.** In `app/Livewire/Documents/Panel.php`, in `save()`, replace

```php
            'category' => ['required', Rule::enum(DocumentCategory::class)],
```

with

```php
            'category' => ['required', Rule::enum(DocumentCategory::class)->except(DocumentCategory::CustomField)], // written only through a file field
```

and in `render()` replace

```php
            'categories' => array_filter(DocumentCategory::cases(), fn ($c) => $c !== DocumentCategory::GeneratedPdf),
```

with

```php
            'categories' => array_filter(DocumentCategory::cases(), fn ($c) => ! in_array($c, [DocumentCategory::GeneratedPdf, DocumentCategory::CustomField], true)),
```

- [ ] **Step 8: Arabic.** In `lang/ar.json`, after `    "Credit refund reversal": "عكس استرداد رصيد",` insert:

```json
    "More details": "تفاصيل إضافية",
    "Enter :attribute as a number with up to 3 decimals.": "أدخل :attribute رقماً بثلاث خانات عشرية كحد أقصى.",
    "Choose :attribute from the list.": "اختر :attribute من القائمة.",
    "Upload :attribute as a JPG, PNG, WebP or PDF file.": "ارفع :attribute كملف JPG أو PNG أو WebP أو PDF.",
    "JPG, PNG, WebP or PDF, max 10 MB": "JPG أو PNG أو WebP أو PDF، بحد أقصى 10 ميغابايت",
    "Current file: :name": "الملف الحالي: :name",
    "View": "عرض",
```

- [ ] **Step 9: Run the tests.**

Run: `php artisan test tests/Feature/CustomFields/ValuesTest.php`, then `php artisan test tests/Feature/Customers/CustomersTest.php`, then `php artisan test tests/Feature/Documents`.
Expected: PASS, PASS, PASS. The existing tests call `SaveCustomer` without `custom`, so no rules are added for them.

- [ ] **Step 10: Gates and commit.**

Run: `vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add app/CustomFields/CustomFields.php resources/views/components/custom-fields app/Actions/Customers/SaveCustomer.php \
  app/Livewire/Customers/Form.php resources/views/livewire/customers/form.blade.php app/Livewire/Documents/Panel.php lang/ar.json \
  tests/Feature/CustomFields/ValuesTest.php
git commit -m "$(cat <<'EOF'
Custom field values on tenants: one shared service and More details section, typed validation, saved with the tenant in its transaction, files through the document store, read-only box for viewers, audited

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Units, buildings and owners: the same section, saved by their own actions

**Files:**
- Modify: `app/Actions/Units/SaveUnit.php`, `app/Actions/Buildings/SaveBuilding.php`, `app/Actions/Owners/SaveOwner.php`
- Modify: `app/Livewire/Units/Form.php`, `app/Livewire/Buildings/Form.php`, `app/Livewire/Owners/Form.php`
- Modify: `resources/views/livewire/units/form.blade.php`, `resources/views/livewire/buildings/form.blade.php`, `resources/views/livewire/owners/form.blade.php`
- Test: `tests/Feature/CustomFields/RecordsTest.php`

**Interfaces:**
- Consumes: `CustomFields::validation()`, `::save()`, `::formValues()`, `::documents()`, `::display()`, `::active()` (Task 3); the two components (Task 3).
- Produces: no new names. `SaveUnit`, `SaveBuilding` and `SaveOwner` accept an optional `custom` key in `$data`, and their forms hold `public array $custom`.

- [ ] **Step 1: Write the failing test** `tests/Feature/CustomFields/RecordsTest.php`:

```php
<?php

use App\CustomFields\CustomFields;
use App\Enums\DocumentCategory;
use App\Enums\RoleName;
use App\Livewire\Buildings\Form as BuildingForm;
use App\Livewire\Owners\Form as OwnerForm;
use App\Livewire\Units\Form as UnitForm;
use App\Models\Building;
use App\Models\CustomFieldValue;
use App\Models\Document;
use App\Models\Owner;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    $this->tower = Building::factory()->create(['name' => 'Seef Tower']);
    $this->other = Building::factory()->create(['name' => 'Other Tower']);
    $this->manager = User::factory()->withTwoFactor()->create()->assignRole(RoleName::PropertyManager);
    $this->leasing = matrixUser(RoleName::Leasing, $this->tower);
    $this->meter = customField('unit', 'text', 'Meter number');
    customField('unit', 'file', 'Floor plan');
    customField('building', 'number', 'Plot area');
    $this->broker = customField('owner', 'choice', 'Broker', choices: ['Direct', 'Agency']);
});

test('units, buildings and owners save their More details with the record, in one Save', function () {
    $unit = Unit::factory()->for($this->tower)->create(['code' => '101']);
    $owner = Owner::factory()->create();
    $finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);

    Livewire::actingAs($this->manager)->test(UnitForm::class, ['unit' => $unit])
        ->set('custom.meter_number', 'EWA-778')
        ->set('custom.floor_plan', UploadedFile::fake()->create('plan.pdf', 10, 'application/pdf'))
        ->call('save')->assertHasNoErrors();
    Livewire::actingAs($this->manager)->test(BuildingForm::class, ['building' => $this->tower])
        ->set('custom.plot_area', '1250.75')->call('save')->assertHasNoErrors();
    Livewire::actingAs($finance)->test(OwnerForm::class, ['owner' => $owner])
        ->set('custom.broker', (string) $this->broker->choices[1]->id)->call('save')->assertHasNoErrors();

    expect(array_column(CustomFields::display($unit), 'text', 'label'))->toBe(['Meter number' => 'EWA-778', 'Floor plan' => 'plan.pdf'])
        ->and(Document::query()->where('documentable_type', $unit->getMorphClass())->where('documentable_id', $unit->id)->sole()->category)->toBe(DocumentCategory::CustomField)
        ->and(array_column(CustomFields::display($this->tower), 'text', 'label'))->toBe(['Plot area' => '1250.75'])
        ->and(array_column(CustomFields::display($owner), 'text', 'label'))->toBe(['Broker' => 'Agency']);
});

test('people who can only view see the read-only box, editors the inputs', function () {
    $unit = Unit::factory()->for($this->tower)->create(['code' => '101']);
    customValue($this->meter, $unit, ['value_text' => 'EWA-778']);

    $this->actingAs($this->leasing)->get(route('units.edit', $unit))->assertOk()
        ->assertSeeInOrder(['More details', 'Meter number', 'EWA-778'])->assertDontSee('Floor plan');
    $this->actingAs($this->manager)->get(route('units.edit', $unit))->assertOk()->assertSee('Floor plan');
});

test('values follow the record\'s own permission and building scope', function () {
    $mine = Unit::factory()->for($this->tower)->create();
    $theirs = Unit::factory()->for($this->other)->create();
    $management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);

    Livewire::actingAs($this->leasing)->test(UnitForm::class, ['unit' => $mine])
        ->set('custom.meter_number', 'X')->call('save')->assertForbidden(); // buildings.view only
    Livewire::actingAs($this->leasing)->test(UnitForm::class, ['unit' => $theirs])->assertForbidden(); // not their building
    Livewire::actingAs($management)->test(OwnerForm::class, ['owner' => Owner::factory()->create()])
        ->set('custom.broker', (string) $this->broker->choices[0]->id)->call('save')->assertHasErrors('form.iban'); // the owner form reports a refused save there

    expect(CustomFieldValue::query()->count())->toBe(0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/CustomFields/RecordsTest.php`
Expected: FAIL. `custom.meter_number` is not a property of the unit form (`PropertyNotFoundException`).

- [ ] **Step 3: `SaveUnit`.** In `app/Actions/Units/SaveUnit.php`:
  - Add to the imports:

```php
use App\CustomFields\CustomFields;
use App\Enums\CustomFieldEntity;
use Illuminate\Support\Arr;
```

  - Replace

```php
        $validated = Validator::make([...$data, 'building_id' => $buildingId], [
```

with

```php
        // Spec 2026-10-21 custom fields §4: "More details" is validated with the unit (plan ruling 2).
        [$customRules, $customMessages, $customNames] = CustomFields::validation(CustomFieldEntity::Unit, $unit, $data);

        $validated = Validator::make([...$data, 'building_id' => $buildingId], [
```

  - Replace

```php
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();
```

with

```php
            'notes' => ['nullable', 'string', 'max:2000'],
            ...$customRules,
        ], $customMessages, $customNames)->validate();
```

  - Replace

```php
        return DB::transaction(function () use ($unit, $validated) {
            $validated['code'] ??= self::nextCode((int) $validated['building_id'], (string) $validated['floor']);
            $unit ??= new Unit;
            $unit->fill($validated)->save();
```

with

```php
        $custom = Arr::pull($validated, 'custom');

        return DB::transaction(function () use ($actor, $unit, $validated, $custom) {
            $validated['code'] ??= self::nextCode((int) $validated['building_id'], (string) $validated['floor']);
            $unit ??= new Unit;
            $unit->fill($validated)->save();
            if (is_array($custom)) {
                CustomFields::save($actor, $unit, $custom);
            }
```

- [ ] **Step 4: `SaveBuilding`.** In `app/Actions/Buildings/SaveBuilding.php`:
  - Add to the imports:

```php
use App\CustomFields\CustomFields;
use App\Enums\CustomFieldEntity;
use Illuminate\Support\Arr;
```

  - Replace

```php
        $validated = Validator::make($data, [
```

with

```php
        // Spec 2026-10-21 custom fields §4: "More details" is validated with the building (plan ruling 2).
        [$customRules, $customMessages, $customNames] = CustomFields::validation(CustomFieldEntity::Building, $building, $data);

        $validated = Validator::make($data, [
```

  - Replace

```php
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        return DB::transaction(function () use ($building, $validated) {
```

with

```php
            'notes' => ['nullable', 'string', 'max:2000'],
            ...$customRules,
        ], $customMessages, $customNames)->validate();
        $custom = Arr::pull($validated, 'custom');

        return DB::transaction(function () use ($actor, $building, $validated, $custom) {
```

  - Replace

```php
            $building->fill($validated)->save(); // LogsActivity records old/new
```

with

```php
            $building->fill($validated)->save(); // LogsActivity records old/new
            if (is_array($custom)) {
                CustomFields::save($actor, $building, $custom);
            }
```

- [ ] **Step 5: `SaveOwner`.** In `app/Actions/Owners/SaveOwner.php` (`Arr` is already imported):
  - Add to the imports:

```php
use App\CustomFields\CustomFields;
use App\Enums\CustomFieldEntity;
```

  - Replace

```php
        $validated = Validator::make($data, [
```

with

```php
        // Spec 2026-10-21 custom fields §4: "More details" is validated with the owner (plan ruling 2).
        [$customRules, $customMessages, $customNames] = CustomFields::validation(CustomFieldEntity::Owner, $owner, $data);

        $validated = Validator::make($data, [
```

  - Replace

```php
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->after(function (ValidatorInstance $validator) use ($actor, $data, $owner) {
```

with

```php
            'notes' => ['nullable', 'string', 'max:2000'],
            ...$customRules,
        ], $customMessages, $customNames)->after(function (ValidatorInstance $validator) use ($actor, $data, $owner) {
```

  - Replace

```php
        $bankNew = Arr::only($validated, Owner::BANK_FIELDS) + array_fill_keys(Owner::BANK_FIELDS, null);
```

with

```php
        $custom = Arr::pull($validated, 'custom');
        $bankNew = Arr::only($validated, Owner::BANK_FIELDS) + array_fill_keys(Owner::BANK_FIELDS, null);
```

  - Replace

```php
            return DB::transaction(function () use ($actor, $owner, $validated, $bankChanged, $bankOld, $bankNew, $importing) {
```

with

```php
            return DB::transaction(function () use ($actor, $owner, $validated, $bankChanged, $bankOld, $bankNew, $importing, $custom) {
```

  - Replace

```php
                $owner->save();
                SyncPortalEmail::apply($owner);
```

with

```php
                $owner->save();
                SyncPortalEmail::apply($owner);
                if (is_array($custom)) {
                    CustomFields::save($actor, $owner, $custom);
                }
```

- [ ] **Step 6: The three forms.** Make the same five changes in each of `app/Livewire/Units/Form.php` (kind `Unit`, record `$unit`, id `$this->unitId`), `app/Livewire/Buildings/Form.php` (kind `Building`, record `$building`, id `$this->buildingId`) and `app/Livewire/Owners/Form.php` (kind `Owner`, record `$owner`, id `$this->ownerId`). The Units form is shown in full; for the other two, substitute the kind, record and id given above.
  1. Add to the imports:

```php
use App\CustomFields\CustomFields;
use App\Enums\CustomFieldEntity;
use Livewire\WithFileUploads;
```

  2. Replace `    use WithActor;` with `    use WithActor, WithFileUploads;`, and after the `public array $form = …;` line add:

```php

    /** @var array<string, mixed> spec 2026-10-21 custom fields §4: "More details", field key => value (files are uploads) */
    public array $custom = [];
```

  3. At the end of `mount()`, after the `if … else` block (Units shown):

```php
        $this->custom = CustomFields::formValues(CustomFieldEntity::Unit, $this->unitId !== null ? $unit : null);
```

  For Buildings: `CustomFields::formValues(CustomFieldEntity::Building, $this->buildingId !== null ? $building : null)`. For Owners: `CustomFields::formValues(CustomFieldEntity::Owner, $this->ownerId !== null ? $owner : null)`.

  4. In `save()`, send the section and keep its error keys.
     - Units: replace `$save->handle($this->actor(), $this->unitId ? Unit::findOrFail($this->unitId) : null, $this->form)` with `$save->handle($this->actor(), $this->unitId ? Unit::findOrFail($this->unitId) : null, [...$this->form, 'custom' => $this->custom])`.
     - Buildings: replace `$save->handle($this->actor(), $this->buildingId ? Building::findOrFail($this->buildingId) : null, $this->form)` with `$save->handle($this->actor(), $this->buildingId ? Building::findOrFail($this->buildingId) : null, [...$this->form, 'custom' => $this->custom])`.
     - Owners: replace `$owner = $save->handle($this->actor(), $existing, $data);` with `$owner = $save->handle($this->actor(), $existing, [...$data, 'custom' => $this->custom]);`.
     - In all three, replace

```php
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ["form.$k" => $m])->all());
```

     with

```php
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => [str_starts_with($k, 'custom') ? $k : "form.$k" => $m])->all());
```

  5. In `render()`, after the `'canEdit' => …,` line add (Units shown; use `$building` / `$owner` and their kinds in the others):

```php
            'customFields' => CustomFields::active(CustomFieldEntity::Unit),
            'customDocuments' => CustomFields::documents($unit),
            'customDetails' => $unit ? CustomFields::display($unit) : [],
```

- [ ] **Step 7: The three views.** In each of `resources/views/livewire/units/form.blade.php`, `resources/views/livewire/buildings/form.blade.php` and `resources/views/livewire/owners/form.blade.php`, replace

```blade
        @if ($canEdit)
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        @endif
    </form>
```

with

```blade
        @if ($canEdit)
            <x-custom-fields.section :fields="$customFields" :values="$custom" :documents="$customDocuments" />
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        @endif
    </form>
    @unless ($canEdit)
        <x-custom-fields.display :items="$customDetails" />
    @endunless
```

- [ ] **Step 8: Run the tests.**

Run: `php artisan test tests/Feature/CustomFields/RecordsTest.php`, then `php artisan test tests/Feature/Units`, then `php artisan test tests/Feature/Buildings`, then `php artisan test tests/Feature/Owners`, then `php artisan test tests/Feature/Banks`.
Expected: all PASS.

- [ ] **Step 9: Gates and commit.**

Run: `vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add app/Actions/Units/SaveUnit.php app/Actions/Buildings/SaveBuilding.php app/Actions/Owners/SaveOwner.php \
  app/Livewire/Units/Form.php app/Livewire/Buildings/Form.php app/Livewire/Owners/Form.php \
  resources/views/livewire/units/form.blade.php resources/views/livewire/buildings/form.blade.php resources/views/livewire/owners/form.blade.php \
  tests/Feature/CustomFields/RecordsTest.php
git commit -m "$(cat <<'EOF'
Custom field values on units, buildings and owners: the shared More details section saved by each record's own action and transaction, read-only box for viewers, record permission and building scope

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: Agreements: the section on the new-agreement form, and an editable More details panel after approval

**Files:**
- Modify: `app/Actions/Agreements/SaveAgreement.php`
- Create: `app/Actions/Agreements/SaveAgreementDetails.php`
- Modify: `app/Livewire/Agreements/Form.php`, `resources/views/livewire/agreements/form.blade.php`
- Modify: `app/Livewire/Agreements/Show.php`, `resources/views/livewire/agreements/show.blade.php`
- Modify: `lang/ar.json`
- Test: `tests/Feature/CustomFields/AgreementDetailsTest.php`

**Interfaces:**
- Consumes: Task 3's `CustomFields` methods and components; `AgreementPolicy::update`.
- Produces:
  - `SaveAgreementDetails::handle(User $actor, Agreement $agreement, array $custom): void` (ruling 3).
  - `Agreements\Show::saveDetails(SaveAgreementDetails $save): void`, which takes no browser arguments.
  - `Agreements\Form` and `Agreements\Show` hold `public array $custom`.

- [ ] **Step 1: Write the failing test** `tests/Feature/CustomFields/AgreementDetailsTest.php`:

```php
<?php

use App\Actions\Agreements\SaveAgreementDetails;
use App\Actions\ContractTemplates\EnsureDefaultContractTemplate;
use App\CustomFields\CustomFields;
use App\Enums\AgreementStatus;
use App\Enums\RoleName;
use App\Livewire\Agreements\Form;
use App\Livewire\Agreements\Show;
use App\Models\Agreement;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\CustomFieldValue;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    Storage::fake('local');
    app(EnsureDefaultContractTemplate::class)();
    $this->building = Building::factory()->create(['name' => 'Juffair Heights']);
    $this->flat = Unit::factory()->for($this->building)->create(['code' => 'J-101', 'list_rent' => '500.000', 'list_deposit' => '500.000']);
    $this->customer = Customer::factory()->create(['name_en' => 'Hassan Qasim']);
    $this->leasing = matrixUser(RoleName::Leasing, $this->building);
    $this->broker = customField('agreement', 'text', 'Broker', ['required' => true]);
    customField('agreement', 'date', 'Key handover');
});

test('the new-agreement form saves More details with the draft, required fields included', function () {
    $form = Livewire::actingAs($this->leasing)->test(Form::class)
        ->call('selectCustomer', $this->customer->id)
        ->set('form.start_date', '2026-11-01')->set('form.end_date', '2027-10-31')
        ->set('pickBuilding', $this->building->id)->set('pickUnit', $this->flat->id)->call('addUnit');

    $form->call('save')->assertHasErrors('custom.broker');
    $form->set('custom.broker', 'Gulf Realty')->call('save')->assertHasNoErrors()->assertRedirect();

    expect(array_column(CustomFields::display(Agreement::query()->sole()), 'text', 'label'))->toBe(['Broker' => 'Gulf Realty']);
});

test('after approval the agreement page keeps More details editable for agreement managers; viewers see the box', function () {
    $agreement = activeAgreement(['customer_id' => $this->customer->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->flat]);

    Livewire::actingAs($this->leasing)->test(Show::class, ['agreement' => $agreement])
        ->assertSee('Save details')
        ->set('custom.broker', 'Gulf Realty')->set('custom.key_handover', '2026-01-02')
        ->call('saveDetails')->assertHasNoErrors();

    expect($agreement->refresh()->status)->toBe(AgreementStatus::Active)
        ->and(array_column(CustomFields::display($agreement), 'text', 'label'))->toBe(['Broker' => 'Gulf Realty', 'Key handover' => '02/01/2026']);
    $audit = Activity::query()->where('event', 'custom_fields.changed')->sole();
    expect($audit->subject_id)->toBe($agreement->id)->and($audit->causer_id)->toBe($this->leasing->id)
        ->and($audit->attribute_changes['attributes'])->toBe(['Broker' => 'Gulf Realty', 'Key handover' => '02/01/2026']);

    $finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    Livewire::actingAs($finance)->test(Show::class, ['agreement' => $agreement])
        ->assertDontSee('Save details')->assertSeeInOrder(['More details', 'Broker', 'Gulf Realty'])
        ->set('custom.broker', 'Changed')->call('saveDetails')->assertForbidden();
    expect(CustomFieldValue::query()->where('custom_field_id', $this->broker->id)->sole()->value_text)->toBe('Gulf Realty');
});

test('the details action re-checks the agreement permission and building scope; tampered input gives messages', function () {
    $agreement = activeAgreement(['customer_id' => $this->customer->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->flat]);
    $outsider = matrixUser(RoleName::Leasing, Building::factory()->create());

    expect(fn () => app(SaveAgreementDetails::class)->handle($outsider, $agreement, ['broker' => 'X']))->toThrow(AuthorizationException::class);
    Livewire::actingAs($this->leasing)->test(Show::class, ['agreement' => $agreement])
        ->set('custom.broker', ['nested'])->set('custom.key_handover', '2026-02-30')
        ->call('saveDetails')->assertHasErrors(['custom.broker', 'custom.key_handover']);

    expect(CustomFieldValue::query()->count())->toBe(0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/CustomFields/AgreementDetailsTest.php`
Expected: FAIL with `Class "App\Actions\Agreements\SaveAgreementDetails" not found`.

- [ ] **Step 3: `SaveAgreement` saves the section with the draft.** In `app/Actions/Agreements/SaveAgreement.php`:
  - Add to the imports:

```php
use App\CustomFields\CustomFields;
use App\Enums\CustomFieldEntity;
use Illuminate\Support\Arr;
```

  - Replace

```php
        $validated = Validator::make($data, [
```

with

```php
        // Spec 2026-10-21 custom fields §4: "More details" is validated with the draft (plan rulings 2–3).
        [$customRules, $customMessages, $customNames] = CustomFields::validation(CustomFieldEntity::Agreement, $agreement, $data);

        $validated = Validator::make($data, [
```

  - Replace

```php
            'units.*.charges.*.tax_category' => ['required', Rule::enum(TaxCategory::class)],
        ])->after(fn (ValidatorInstance $v) => $this->check($v, $data, $actor))->validate();
```

with

```php
            'units.*.charges.*.tax_category' => ['required', Rule::enum(TaxCategory::class)],
            ...$customRules,
        ], $customMessages, $customNames)->after(fn (ValidatorInstance $v) => $this->check($v, $data, $actor))->validate();
        $custom = Arr::pull($validated, 'custom');
```

  - Replace

```php
        return DB::transaction(function () use ($actor, $id, $validated) {
```

with

```php
        return DB::transaction(function () use ($actor, $id, $validated, $custom) {
```

  - Replace

```php
            return $agreement->load('agreementUnits.charges');
```

with

```php
            if (is_array($custom)) {
                CustomFields::save($actor, $agreement, $custom);
            }

            return $agreement->load('agreementUnits.charges');
```

- [ ] **Step 4: Create `app/Actions/Agreements/SaveAgreementDetails.php`:**

```php
<?php

namespace App\Actions\Agreements;

use App\CustomFields\CustomFields;
use App\Enums\CustomFieldEntity;
use App\Models\Agreement;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Spec 2026-10-21 custom fields §4: an agreement's "More details" after the draft, at any status (agreements lock,
 * custom fields do not), for whoever can manage the agreement: agreements.manage with every unit in scope (plan ruling 3).
 */
final class SaveAgreementDetails
{
    /** @param  array<string, mixed>  $custom  field key => value */
    public function handle(User $actor, Agreement $agreement, array $custom): void
    {
        if (! $actor->can('update', $agreement)) {
            throw new AuthorizationException;
        }

        $data = ['custom' => $custom];
        [$rules, $messages, $names] = CustomFields::validation(CustomFieldEntity::Agreement, $agreement, $data);
        $validated = Validator::make($data, $rules, $messages, $names)->validate();

        DB::transaction(function () use ($actor, $agreement, $validated) {
            // A read lock only: the agreement row is not written, so its status triggers are not involved.
            $locked = Agreement::query()->lockForUpdate()->findOrFail($agreement->id);
            CustomFields::save($actor, $locked, (array) ($validated['custom'] ?? []));
        });
    }
}
```

- [ ] **Step 5: The agreement form.** In `app/Livewire/Agreements/Form.php`:
  - Add to the imports:

```php
use App\CustomFields\CustomFields;
use App\Enums\CustomFieldEntity;
use Livewire\WithFileUploads;
```

  - Replace `    use WithActor;` with `    use WithActor, WithFileUploads;`. After the `public array $units = [];` line add:

```php

    /** @var array<string, mixed> spec 2026-10-21 custom fields §4: "More details", field key => value (files are uploads) */
    public array $custom = [];
```

  - Replace

```php
        if (! $agreement?->exists) {
            abort_unless($this->actor()->can('create', Agreement::class), 403);

            return;
        }
```

with

```php
        if (! $agreement?->exists) {
            abort_unless($this->actor()->can('create', Agreement::class), 403);
            $this->custom = CustomFields::formValues(CustomFieldEntity::Agreement, null);

            return;
        }
```

  - Replace

```php
        ])->values()->all();
    }

    public function selectCustomer(int $customerId): void
```

with

```php
        ])->values()->all();
        $this->custom = CustomFields::formValues(CustomFieldEntity::Agreement, $agreement);
    }

    public function selectCustomer(int $customerId): void
```

  - In `save()`, replace

```php
        $data = [...$this->form, 'units' => array_map(fn (array $u) => collect($u)->except('label')->all(), $this->units)];
```

with

```php
        $data = [...$this->form, 'units' => array_map(fn (array $u) => collect($u)->except('label')->all(), $this->units), 'custom' => $this->custom];
```

and replace

```php
                ->mapWithKeys(fn ($m, $k) => [str_starts_with($k, 'units') ? $k : "form.$k" => $m])->all());
```

with

```php
                ->mapWithKeys(fn ($m, $k) => [str_starts_with($k, 'units') || str_starts_with($k, 'custom') ? $k : "form.$k" => $m])->all());
```

  - In `render()`, after `'templates' => …,` add:

```php
            'customFields' => CustomFields::active(CustomFieldEntity::Agreement),
            'customDocuments' => CustomFields::documents($this->agreementId !== null ? Agreement::query()->find($this->agreementId) : null),
```

In `resources/views/livewire/agreements/form.blade.php`, replace

```blade
        <flux:button variant="primary" type="submit">{{ __('Save draft') }}</flux:button>
```

with

```blade
        <x-custom-fields.section :fields="$customFields" :values="$custom" :documents="$customDocuments" />
        <flux:button variant="primary" type="submit">{{ __('Save draft') }}</flux:button>
```

- [ ] **Step 6: The agreement page's panel.** In `app/Livewire/Agreements/Show.php`:
  - Add to the imports:

```php
use App\Actions\Agreements\SaveAgreementDetails;
use App\CustomFields\CustomFields;
use App\Enums\CustomFieldEntity;
use Livewire\WithFileUploads;
```

  - Replace `    use WithActor;` with `    use WithActor, WithFileUploads;`. After `public string $plannedExit = '';` add:

```php

    /** @var array<string, mixed> spec 2026-10-21 custom fields §4: the "More details" panel, field key => value */
    public array $custom = [];
```

  - Replace

```php
        abort_unless($this->actor()->can('view', $agreement), 403);
        $this->agreementId = $agreement->id;
    }
```

with

```php
        abort_unless($this->actor()->can('view', $agreement), 403);
        $this->agreementId = $agreement->id;
        $this->custom = CustomFields::formValues(CustomFieldEntity::Agreement, $agreement);
    }

    /** Spec 2026-10-21 custom fields §4: "More details" stay editable at any status, for whoever can manage the agreement (plan ruling 3). */
    public function saveDetails(SaveAgreementDetails $save): void
    {
        try {
            $save->handle($this->actor(), $this->agreement(), $this->custom);
        } catch (AuthorizationException) {
            abort(403);
        }

        $this->custom = CustomFields::formValues(CustomFieldEntity::Agreement, $this->agreement());
        Flux::toast(variant: 'success', text: __('Details saved.'));
    }
```

  - In `render()`, after `'canManage' => $this->actor()->can('update', $agreement),` add:

```php
            'customFields' => CustomFields::active(CustomFieldEntity::Agreement),
            'customDocuments' => CustomFields::documents($agreement),
            'customDetails' => CustomFields::display($agreement),
```

In `resources/views/livewire/agreements/show.blade.php`, replace

```blade
    @if ($amendments->isNotEmpty())
```

with

```blade
    @if ($canManage && $customFields->isNotEmpty())
        <form wire:submit="saveDetails" class="space-y-3">
            <x-custom-fields.section :fields="$customFields" :values="$custom" :documents="$customDocuments" />
            <flux:button type="submit">{{ __('Save details') }}</flux:button>
        </form>
    @elseif (! $canManage)
        <x-custom-fields.display :items="$customDetails" />
    @endif

    @if ($amendments->isNotEmpty())
```

- [ ] **Step 7: Arabic.** In `lang/ar.json`, after `    "Credit refund reversal": "عكس استرداد رصيد",` insert:

```json
    "Save details": "حفظ التفاصيل",
    "Details saved.": "تم حفظ التفاصيل.",
```

- [ ] **Step 8: Run the tests.**

Run: `php artisan test tests/Feature/CustomFields/AgreementDetailsTest.php`, then `php artisan test tests/Feature/Agreements`.
Expected: PASS, PASS.

- [ ] **Step 9: Gates and commit.**

Run: `vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add app/Actions/Agreements/SaveAgreement.php app/Actions/Agreements/SaveAgreementDetails.php app/Livewire/Agreements/Form.php \
  app/Livewire/Agreements/Show.php resources/views/livewire/agreements/form.blade.php resources/views/livewire/agreements/show.blade.php \
  lang/ar.json tests/Feature/CustomFields/AgreementDetailsTest.php
git commit -m "$(cat <<'EOF'
Custom field values on agreements: More details on the new-agreement form saved with the draft, and an agreement-page panel editable at any status through its own action and permission check

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: More filters on the Tenants, Units, Agreements, Owners and Buildings lists

**Files:**
- Create: `app/CustomFields/CustomFieldFilter.php`, `resources/views/components/custom-fields/filter.blade.php`
- Modify: `app/Livewire/Customers/Index.php`, `app/Livewire/Units/Index.php`, `app/Livewire/Buildings/Index.php`, `app/Livewire/Owners/Index.php`, `app/Livewire/Agreements/Index.php` and their five `index.blade.php` views
- Modify: `lang/ar.json`
- Test: `tests/Feature/CustomFields/ListFiltersTest.php`

**Interfaces:**
- Consumes: `CustomFields::active()`, `CustomFields::NUMBER_PATTERN` (Task 3), `customField()` / `customValue()` (Task 1).
- Produces:
  - `CustomFieldFilter::CONDITIONS` (`array<string, list<string>>`, keyed by type value).
  - `CustomFieldFilter::apply(Builder $query, CustomFieldEntity $entity, mixed $filter): Builder` (returns `$query`).
  - `<x-custom-fields.filter :fields :more />`.
  - Each Index has `public mixed $more = []`.

- [ ] **Step 1: Write the failing test** `tests/Feature/CustomFields/ListFiltersTest.php`:

```php
<?php

use App\CustomFields\CustomFieldFilter;
use App\Enums\CustomFieldEntity;
use App\Enums\RoleName;
use App\Livewire\Agreements\Index as AgreementsIndex;
use App\Livewire\Buildings\Index as BuildingsIndex;
use App\Livewire\Customers\Index as CustomersIndex;
use App\Livewire\Owners\Index as OwnersIndex;
use App\Livewire\Units\Index as UnitsIndex;
use App\Models\Agreement;
use App\Models\Building;
use App\Models\Customer;
use App\Models\Owner;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->a = Building::factory()->create(['code' => 'AAA']);
    $this->b = Building::factory()->create(['code' => 'BBB']);
    $this->leasing = matrixUser(RoleName::Leasing, $this->a);
    $this->admin = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Admin);
});

test('the Tenants list filters on a text field inside the user\'s building scope', function () {
    $employer = customField('customer', 'text', 'Employer');
    $sara = Customer::factory()->create(['name_en' => 'Sara Inscope']);
    $omar = Customer::factory()->create(['name_en' => 'Omar Elsewhere']);
    $lina = Customer::factory()->create(['name_en' => 'Lina Inscope']);
    Customer::factory()->create(['name_en' => 'Nadia Blank']); // no agreement yet: everyone sees her
    activeAgreement(['customer_id' => $sara->id], [Unit::factory()->for($this->a)->create()]);
    activeAgreement(['customer_id' => $omar->id], [Unit::factory()->for($this->b)->create()]);
    activeAgreement(['customer_id' => $lina->id], [Unit::factory()->for($this->a)->create()]);
    customValue($employer, $sara, ['value_text' => 'Bapco Refining']);
    customValue($employer, $omar, ['value_text' => 'Bapco Gas']);
    customValue($employer, $lina, ['value_text' => 'Alba']);

    Livewire::actingAs($this->leasing)->test(CustomersIndex::class)
        ->assertSee('More filters')
        ->set('more', ['field' => 'employer', 'op' => 'contains', 'value' => 'bapco'])
        ->assertSee('Sara Inscope')->assertDontSee('Lina Inscope')->assertDontSee('Nadia Blank')->assertDontSee('Omar Elsewhere')
        ->set('more', ['field' => 'employer', 'op' => 'empty'])
        ->assertSee('Nadia Blank')->assertDontSee('Sara Inscope')->assertDontSee('Lina Inscope')->assertDontSee('Omar Elsewhere');
});

test('number and date fields filter from–to, inclusive at both edges, exactly', function () {
    $area = customField('unit', 'number', 'Balcony area');
    $handover = customField('unit', 'date', 'Handover');
    [$u1, $u2, $u3] = Unit::factory()->for($this->a)->count(3)->create()->all();
    customValue($area, $u1, ['value_number' => '999999999999.998']);
    customValue($area, $u2, ['value_number' => '999999999999.999']);
    customValue($area, $u3, ['value_number' => '12.5']);
    customValue($handover, $u1, ['value_date' => '2026-01-31']);
    customValue($handover, $u3, ['value_date' => '2026-02-01']);
    $ids = fn (array $filter) => CustomFieldFilter::apply(Unit::query(), CustomFieldEntity::Unit, $filter)->orderBy('id')->pluck('id')->all();

    expect($ids(['field' => 'balcony_area', 'op' => 'range', 'from' => '999999999999.999']))->toBe([$u2->id])
        ->and($ids(['field' => 'balcony_area', 'op' => 'range', 'to' => '12.500']))->toBe([$u3->id])
        ->and($ids(['field' => 'handover', 'op' => 'range', 'from' => '2026-02-01', 'to' => '2026-12-31']))->toBe([$u3->id])
        ->and($ids(['field' => 'handover', 'op' => 'empty']))->toBe([$u2->id]);
});

test('choices, yes/no and "is empty" filter Agreements, Owners and Buildings; bad filter values are ignored', function () {
    $source = customField('agreement', 'choice', 'Source', choices: ['Walk-in', 'Agent', 'Website']);
    [$walkIn, $agent] = $source->choices->all();
    $ag1 = activeAgreement([], [Unit::factory()->for($this->a)->create()]);
    $ag2 = activeAgreement([], [Unit::factory()->for($this->a)->create()]);
    customValue($source, $ag1, ['choice_id' => $agent->id]);
    $agent->forceFill(['hidden_at' => now()])->save(); // still filters: records keep hidden choices
    $vat = customField('owner', 'bool', 'VAT registered');
    [$o1, $o2, $o3] = Owner::factory()->count(3)->create()->all();
    customValue($vat, $o1, ['value_bool' => true]);
    customValue($vat, $o2, ['value_bool' => false]);
    $deed = customField('building', 'text', 'Title deed');
    customValue($deed, $this->a, ['value_text' => 'TD-1']);

    $agreements = fn (mixed $f) => CustomFieldFilter::apply(Agreement::query(), CustomFieldEntity::Agreement, $f)->orderBy('id')->pluck('id')->all();
    $owners = fn (mixed $f) => CustomFieldFilter::apply(Owner::query(), CustomFieldEntity::Owner, $f)->orderBy('id')->pluck('id')->all();
    expect($agreements(['field' => 'source', 'op' => 'in', 'choices' => [(string) $agent->id, (string) $walkIn->id]]))->toBe([$ag1->id])
        ->and($owners(['field' => 'vat_registered', 'op' => 'yes']))->toBe([$o1->id])
        ->and($owners(['field' => 'vat_registered', 'op' => 'no']))->toBe([$o2->id])
        ->and($owners(['field' => 'vat_registered', 'op' => 'empty']))->toBe([$o3->id])
        ->and(CustomFieldFilter::apply(Building::query(), CustomFieldEntity::Building, ['field' => 'title_deed', 'op' => 'empty'])->pluck('id')->all())->toBe([$this->b->id]);

    customField('agreement', 'number', 'Commission');
    $all = [$ag1->id, $ag2->id];
    foreach ([
        'abc', [], ['field' => 'nope', 'op' => 'empty'], ['field' => ['x'], 'op' => 'in'], ['field' => 'source', 'op' => 'contains', 'value' => 'x'],
        ['field' => 'source', 'op' => 'in', 'choices' => 'x'], ['field' => 'source', 'op' => 'in', 'choices' => ['abc', 999999]],
        ['field' => 'commission', 'op' => 'range', 'from' => '1e3', 'to' => ['x']],
    ] as $bad) {
        expect($agreements($bad))->toBe($all);
    }
});

test('every list offers "More filters" and shrugs off a tampered filter', function (string $component, string $entity) {
    customField($entity, 'text', 'Reference');

    Livewire::actingAs($this->admin)->test($component)->assertSee('More filters')
        ->set('more', 'abc')->assertOk()
        ->set('more', ['field' => ['x'], 'op' => ['y']])->assertOk()
        ->set('more', ['field' => 'reference', 'op' => 'contains', 'value' => 'x'])->assertOk()->assertSee('Contains');
})->with([
    'tenants' => [CustomersIndex::class, 'customer'],
    'units' => [UnitsIndex::class, 'unit'],
    'buildings' => [BuildingsIndex::class, 'building'],
    'owners' => [OwnersIndex::class, 'owner'],
    'agreements' => [AgreementsIndex::class, 'agreement'],
]);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/CustomFields/ListFiltersTest.php`
Expected: FAIL with `Class "App\CustomFields\CustomFieldFilter" not found`.

- [ ] **Step 3: Create `app/CustomFields/CustomFieldFilter.php`:**

```php
<?php

namespace App\CustomFields;

use App\Enums\CustomFieldEntity;
use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Spec 2026-10-21 custom fields §5 "More filters": one custom field condition on a list. It only adds a where on the
 * list's own, already scoped query, so it can narrow but never widen building scope; a bad filter is ignored (plan ruling 6).
 */
final class CustomFieldFilter
{
    /** The conditions each type offers, keyed by CustomFieldType value. */
    public const array CONDITIONS = [
        'text' => ['contains', 'empty'],
        'long_text' => ['contains', 'empty'],
        'number' => ['range', 'empty'],
        'date' => ['range', 'empty'],
        'bool' => ['yes', 'no', 'empty'],
        'choice' => ['in', 'empty'],
        'file' => ['empty'],
    ];

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  mixed  $filter  {field, op, value, from, to, choices} from the browser, untrusted
     * @return Builder<TModel>
     */
    public static function apply(Builder $query, CustomFieldEntity $entity, mixed $filter): Builder
    {
        if (! is_array($filter) || ! is_string($filter['field'] ?? null) || ! is_string($filter['op'] ?? null)) {
            return $query;
        }
        $field = CustomFields::active($entity)->firstWhere('key', $filter['field']);
        if ($field === null || ! in_array($filter['op'], self::CONDITIONS[$field->type->value], true)) {
            return $query;
        }

        $column = $field->type->column();
        $key = $query->getModel()->getQualifiedKeyName();
        $filled = CustomFieldValue::query()->select('entity_id')->where('custom_field_id', $field->id)->whereNotNull($column);

        if ($filter['op'] === 'empty') {
            return $query->whereNotIn($key, $filled);
        }

        $matching = match ($filter['op']) {
            'contains' => self::contains($filled, $column, $filter['value'] ?? null),
            'range' => self::range($filled, $column, $field->type, $filter['from'] ?? null, $filter['to'] ?? null),
            'yes', 'no' => $filled->where($column, $filter['op'] === 'yes'),
            'in' => self::choices($filled, $field, $filter['choices'] ?? null),
            default => null,
        };

        return $matching === null ? $query : $query->whereIn($key, $matching);
    }

    /**
     * @param  Builder<CustomFieldValue>  $values
     * @return Builder<CustomFieldValue>|null
     */
    private static function contains(Builder $values, string $column, mixed $text): ?Builder
    {
        return is_string($text) && trim($text) !== '' && mb_strlen($text) <= 100
            ? $values->where($column, 'like', '%'.addcslashes(trim($text), '%_\\').'%')
            : null;
    }

    /**
     * Either end may be left out; an invalid end is ignored, and the filter too when neither end is valid.
     *
     * @param  Builder<CustomFieldValue>  $values
     * @return Builder<CustomFieldValue>|null
     */
    private static function range(Builder $values, string $column, CustomFieldType $type, mixed $from, mixed $to): ?Builder
    {
        $ok = fn (mixed $v): bool => is_string($v) && ($type === CustomFieldType::Number
            ? preg_match(CustomFields::NUMBER_PATTERN, $v) === 1
            : preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]));
        if (! $ok($from) && ! $ok($to)) {
            return null;
        }

        // Numbers compare as DECIMAL, never as a double (plan ruling 1). $column is ours, never input.
        $bound = $type === CustomFieldType::Number ? 'CAST(? AS DECIMAL(15,3))' : '?';
        if ($ok($from)) {
            $values->whereRaw("{$column} >= {$bound}", [$from]);
        }
        if ($ok($to)) {
            $values->whereRaw("{$column} <= {$bound}", [$to]);
        }

        return $values;
    }

    /**
     * Any of the field's choices, hidden ones included: records keep them (plan ruling 11).
     *
     * @param  Builder<CustomFieldValue>  $values
     * @return Builder<CustomFieldValue>|null
     */
    private static function choices(Builder $values, CustomField $field, mixed $picked): ?Builder
    {
        $ids = is_array($picked) ? array_values(array_intersect($field->choices->modelKeys(),
            array_map(intval(...), array_filter($picked, fn (mixed $c) => is_int($c) || (is_string($c) && ctype_digit($c)))))) : [];

        return $ids === [] ? null : $values->whereIn('choice_id', $ids);
    }
}
```

- [ ] **Step 4: Create `resources/views/components/custom-fields/filter.blade.php`:**

```blade
@props(['fields', 'more' => []])
{{-- Spec 2026-10-21 custom fields §5 "More filters": one field, one condition (plan ruling 6). $more may be anything the browser sent. --}}
@if ($fields->isNotEmpty())
    @php
        $picked = $fields->firstWhere('key', data_get($more, 'field'));
        $op = data_get($more, 'op');
        $conditions = $picked ? App\CustomFields\CustomFieldFilter::CONDITIONS[$picked->type->value] : [];
        $labels = ['contains' => __('Contains'), 'range' => __('From – to'), 'yes' => __('Yes'), 'no' => __('No'), 'in' => __('Is one of'), 'empty' => __('Is empty')];
    @endphp
    <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
        <flux:select wire:model.live="more.field" :label="__('More filters')" class="sm:max-w-56">
            <option value="">{{ __('No extra filter') }}</option>
            @foreach ($fields as $f)
                <option value="{{ $f->key }}">{{ $f->label() }}</option>
            @endforeach
        </flux:select>
        @if ($picked)
            <flux:select wire:model.live="more.op" :label="__('Condition')" class="sm:max-w-44">
                <option value="">{{ __('Choose…') }}</option>
                @foreach ($conditions as $c)
                    <option value="{{ $c }}">{{ $labels[$c] }}</option>
                @endforeach
            </flux:select>
            @if ($op === 'contains')
                <flux:input wire:model.live.debounce.300ms="more.value" :label="__('Text')" class="sm:max-w-56" />
            @elseif ($op === 'range' && $picked->type === App\Enums\CustomFieldType::Date)
                <x-date-input wire:model.live="more.from" :label="__('From')" class="sm:max-w-44" />
                <x-date-input wire:model.live="more.to" :label="__('To')" class="sm:max-w-44" />
            @elseif ($op === 'range')
                <flux:input wire:model.live.debounce.300ms="more.from" :label="__('From')" inputmode="decimal" class="sm:max-w-32" />
                <flux:input wire:model.live.debounce.300ms="more.to" :label="__('To')" inputmode="decimal" class="sm:max-w-32" />
            @elseif ($op === 'in')
                <div class="flex flex-wrap gap-3">
                    @foreach ($picked->choices as $choice)
                        <label class="flex items-center gap-1 text-sm"><input type="checkbox" value="{{ $choice->id }}" wire:model.live="more.choices"> {{ $choice->label() }}</label>
                    @endforeach
                </div>
            @endif
        @endif
    </div>
@endif
```

- [ ] **Step 5: The five lists.** In each of the five Index components:
  - Add to the imports:

```php
use App\CustomFields\CustomFieldFilter;
use App\CustomFields\CustomFields;
use App\Enums\CustomFieldEntity;
```

  - After the line `    public string $search = '';`, add:

```php

    /** Spec 2026-10-21 custom fields §5 "More filters": field, op, value, from, to, choices. Mixed and not in the URL, so a tampered value is ignored, never a TypeError (plan ruling 6). */
    public mixed $more = [];
```

  - Then, per component:
    - **`app/Livewire/Customers/Index.php`:**
      - Replace `            ->when($term !== '', fn ($q) => $q->search($term))` with

```php
            ->when($term !== '', fn ($q) => $q->search($term))
            ->tap(fn ($q) => CustomFieldFilter::apply($q, CustomFieldEntity::Customer, $this->more))
```

      - Replace `return view('livewire.customers.index', ['customers' => $customers, 'hint' => $hint]);` with `return view('livewire.customers.index', ['customers' => $customers, 'hint' => $hint, 'customFields' => CustomFields::active(CustomFieldEntity::Customer)]);`.
    - **`app/Livewire/Units/Index.php`:**
      - Replace `            ->when($this->search !== '', fn ($q) => $q->where('code', 'like', '%'.$this->search.'%'))` with

```php
            ->when($this->search !== '', fn ($q) => $q->where('code', 'like', '%'.$this->search.'%'))
            ->tap(fn ($q) => CustomFieldFilter::apply($q, CustomFieldEntity::Unit, $this->more))
```

      - In its `view(...)` array, after `'units' => $units,` add `'customFields' => CustomFields::active(CustomFieldEntity::Unit),`.
    - **`app/Livewire/Buildings/Index.php`:**
      - Replace

```php
            ->orderBy('name')
            ->paginate(25);

        return view('livewire.buildings.index', ['buildings' => $buildings]);
```

      with

```php
            ->tap(fn ($q) => CustomFieldFilter::apply($q, CustomFieldEntity::Building, $this->more))
            ->orderBy('name')
            ->paginate(25);

        return view('livewire.buildings.index', ['buildings' => $buildings, 'customFields' => CustomFields::active(CustomFieldEntity::Building)]);
```

    - **`app/Livewire/Owners/Index.php`:**
      - Replace

```php
            ->orderBy('name_en')
            ->paginate(25);

        return view('livewire.owners.index', ['owners' => $owners]);
```

      with

```php
            ->tap(fn ($q) => CustomFieldFilter::apply($q, CustomFieldEntity::Owner, $this->more))
            ->orderBy('name_en')
            ->paginate(25);

        return view('livewire.owners.index', ['owners' => $owners, 'customFields' => CustomFields::active(CustomFieldEntity::Owner)]);
```

    - **`app/Livewire/Agreements/Index.php`:**
      - Replace

```php
            ->latest('id')
            ->paginate(25);

        return view('livewire.agreements.index', ['agreements' => $agreements, 'statuses' => AgreementStatus::cases()]);
```

      with

```php
            ->tap(fn ($q) => CustomFieldFilter::apply($q, CustomFieldEntity::Agreement, $this->more))
            ->latest('id')
            ->paginate(25);

        return view('livewire.agreements.index', ['agreements' => $agreements, 'statuses' => AgreementStatus::cases(),
            'customFields' => CustomFields::active(CustomFieldEntity::Agreement)]);
```

  The existing `updating()` hook already resets the page when `more` changes.

- [ ] **Step 6: The five views.** Add `<x-custom-fields.filter :fields="$customFields" :more="$more" />` as its own row, right after each list's existing filter row:
  - `resources/views/livewire/customers/index.blade.php`: replace

```blade
        <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Name, mobile, ID number or unit')" icon="magnifying-glass" class="sm:max-w-xs" />
    </div>
```

  with

```blade
        <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Name, mobile, ID number or unit')" icon="magnifying-glass" class="sm:max-w-xs" />
    </div>
    <x-custom-fields.filter :fields="$customFields" :more="$more" />
```

  - `resources/views/livewire/units/index.blade.php`: replace

```blade
        <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Unit code')" icon="magnifying-glass" class="sm:max-w-40" />
    </div>
```

  with

```blade
        <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Unit code')" icon="magnifying-glass" class="sm:max-w-40" />
    </div>
    <x-custom-fields.filter :fields="$customFields" :more="$more" />
```

  - `resources/views/livewire/agreements/index.blade.php`: replace

```blade
        <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Number, tenant, mobile or unit')" icon="magnifying-glass" class="sm:max-w-xs" />
    </div>
```

  with

```blade
        <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Number, tenant, mobile or unit')" icon="magnifying-glass" class="sm:max-w-xs" />
    </div>
    <x-custom-fields.filter :fields="$customFields" :more="$more" />
```

  - `resources/views/livewire/buildings/index.blade.php`: replace

```blade
    <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Search name or code')" icon="magnifying-glass" class="sm:max-w-xs" />
```

  with

```blade
    <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Search name or code')" icon="magnifying-glass" class="sm:max-w-xs" />
    <x-custom-fields.filter :fields="$customFields" :more="$more" />
```

  - `resources/views/livewire/owners/index.blade.php`: replace

```blade
    <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Name or exact ID number')" icon="magnifying-glass" class="sm:max-w-xs" />
```

  with

```blade
    <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Name or exact ID number')" icon="magnifying-glass" class="sm:max-w-xs" />
    <x-custom-fields.filter :fields="$customFields" :more="$more" />
```

- [ ] **Step 7: Arabic.** In `lang/ar.json`, after `    "Credit refund reversal": "عكس استرداد رصيد",` insert:

```json
    "More filters": "عوامل تصفية إضافية",
    "No extra filter": "بدون تصفية إضافية",
    "Condition": "الشرط",
    "Contains": "يحتوي على",
    "From – to": "من – إلى",
    "Is one of": "أحد الخيارات",
    "Is empty": "فارغ",
```

- [ ] **Step 8: Run the tests.**

Run: `php artisan test tests/Feature/CustomFields/ListFiltersTest.php`, then `php artisan test tests/Feature/Customers`, then `php artisan test tests/Feature/Units`, then `php artisan test tests/Feature/Agreements`.
Expected: all PASS.

- [ ] **Step 9: Gates and commit.**

Run: `vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add app/CustomFields/CustomFieldFilter.php resources/views/components/custom-fields/filter.blade.php \
  app/Livewire/Customers/Index.php app/Livewire/Units/Index.php app/Livewire/Buildings/Index.php app/Livewire/Owners/Index.php app/Livewire/Agreements/Index.php \
  resources/views/livewire/customers/index.blade.php resources/views/livewire/units/index.blade.php resources/views/livewire/buildings/index.blade.php \
  resources/views/livewire/owners/index.blade.php resources/views/livewire/agreements/index.blade.php lang/ar.json tests/Feature/CustomFields/ListFiltersTest.php
git commit -m "$(cat <<'EOF'
More filters on the tenant, unit, agreement, owner and building lists: one custom field condition per type, exact decimal ranges, inside each list's existing scope, bad values ignored

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: Report builder: custom columns and filters, typed, by key, with archived and deleted fields handled

**Files:**
- Create: `app/Reports/Builder/CustomColumns.php`
- Modify: `app/Reports/Builder/Field.php`, `app/Reports/Builder/QueryCompiler.php`, `app/Reports/Builder/ReportDefinition.php`
- Modify: `app/Reports/Builder/Subjects/Tenants.php`, `Units.php`, `Agreements.php`, `Owners.php`
- Modify: `resources/views/livewire/reports/builder.blade.php`, `lang/ar.json`
- Test: `tests/Feature/CustomFields/ReportBuilderTest.php`

**Interfaces:**
- Consumes: Task 1 models and enums; `customField()` / `customValue()`; `runReport()` (tests/Pest.php); `Field`, `FieldType`, `Registry`, `ReportDefinition`.
- Produces:
  - `CustomColumns::fields(CustomFieldEntity $entity, string $prefix, string $section, string $idSql): list<Field>`.
  - `Field::$archived` (bool, default false; last constructor parameter).
  - Report keys `cf_<key>`, `cfb_<key>` (Units) and `cft_<key>` (Agreements).

- [ ] **Step 1: Write the failing test** `tests/Feature/CustomFields/ReportBuilderTest.php`:

```php
<?php

use App\Enums\RoleName;
use App\Livewire\Reports\Builder;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use App\Reports\Builder\FieldType;
use App\Reports\Builder\Registry;
use App\Reports\Builder\ReportDefinition;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00', 'Asia/Bahrain'));
    $this->today = CarbonImmutable::today('Asia/Bahrain');
    $this->a = Building::factory()->create(['code' => 'AAA']);
    $this->b = Building::factory()->create(['code' => 'BBB']);
    $this->leasing = matrixUser(RoleName::Leasing, $this->a);
    $this->admin = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Admin);
});

test('unit and building fields become typed Units columns: numbers total, building fields in a Building section, files left out, scope kept', function () {
    $area = customField('unit', 'number', 'Balcony area');
    customField('unit', 'file', 'Floor plan');
    $zone = customField('building', 'choice', 'Zone', choices: ['North', 'South']);
    [$north, $south] = $zone->choices->all();
    customValue($zone, $this->a, ['choice_id' => $north->id]);
    customValue($zone, $this->b, ['choice_id' => $south->id]);
    $u1 = Unit::factory()->for($this->a)->create(['code' => 'A1']);
    $u2 = Unit::factory()->for($this->a)->create(['code' => 'A2']);
    $u3 = Unit::factory()->for($this->b)->create(['code' => 'B1']);
    customValue($area, $u1, ['value_number' => '10.125']);
    customValue($area, $u2, ['value_number' => '2.5']);
    customValue($area, $u3, ['value_number' => '1000']);

    $fields = Registry::get('units')->fields($this->leasing, $this->today);
    expect($fields['cf_balcony_area']->type)->toBe(FieldType::Number)
        ->and($fields['cf_balcony_area']->section)->toBe('More details')
        ->and($fields['cfb_zone']->section)->toBe('Building')
        ->and($fields['cfb_zone']->options)->toBe([(string) $north->id => 'North', (string) $south->id => 'South'])
        ->and(array_key_exists('cf_floor_plan', $fields))->toBeFalse();

    $result = runReport($this->leasing, ['subject' => 'units', 'columns' => ['code', 'cf_balcony_area', 'cfb_zone'],
        'totals' => ['cf_balcony_area' => 'sum'], 'sort' => ['field' => 'code', 'dir' => 'asc']]);
    expect(array_map(fn (array $r) => [$r['code'], (string) $r['cf_balcony_area'], (string) $r['cfb_zone']], $result->rows))
        ->toBe([['A1', '10.125', (string) $north->id], ['A2', '2.500', (string) $north->id]]) // BBB is not Leasing's
        ->and((string) $result->grandTotals['cf_balcony_area:sum'])->toBe('12.625');
});

test('dates group by month, yes/no groups, and Agreements carry tenant fields in a Tenant section', function () {
    $moved = customField('customer', 'date', 'Moved to Bahrain');
    $pets = customField('customer', 'bool', 'Has pets');
    $t1 = Customer::factory()->create(['name_en' => 'T1']);
    $t2 = Customer::factory()->create(['name_en' => 'T2']);
    $t3 = Customer::factory()->create(['name_en' => 'T3']);
    customValue($moved, $t1, ['value_date' => '2024-03-05']);
    customValue($moved, $t2, ['value_date' => '2024-03-28']);
    customValue($moved, $t3, ['value_date' => '2024-07-01']);
    customValue($pets, $t1, ['value_bool' => true]);
    customValue($pets, $t2, ['value_bool' => false]);
    activeAgreement(['customer_id' => $t1->id], [Unit::factory()->for($this->a)->create()]);

    $byMonth = runReport($this->leasing, ['subject' => 'tenants', 'columns' => ['name', 'cf_moved_to_bahrain'], 'groups' => [['field' => 'cf_moved_to_bahrain', 'bucket' => 'month']]]);
    $byPets = runReport($this->leasing, ['subject' => 'tenants', 'columns' => ['name'], 'groups' => [['field' => 'cf_has_pets']]]);
    $agreements = runReport($this->leasing, ['subject' => 'agreements', 'columns' => ['number', 'cft_has_pets']]);

    expect(array_map(fn (array $g) => [$g['label'], $g['totals']['count']], $byMonth->groups))->toBe([['Mar 2024', 2], ['Jul 2024', 1]])
        ->and(array_map(fn (array $g) => [$g['label'], $g['totals']['count']], $byPets->groups))->toBe([['No', 1], ['Yes', 1], ['—', 1]])
        ->and(Registry::get('agreements')->fields($this->leasing, $this->today)['cft_has_pets']->section)->toBe('Tenant')
        ->and(array_map(fn (array $r) => (int) $r['cft_has_pets'], $agreements->rows))->toBe([1]);
});

test('saved reports use the key: a renamed field keeps working, an archived one is an empty "(archived)" column, a deleted one is left out with a note', function () {
    $employer = customField('customer', 'text', 'Employer');
    $badge = customField('customer', 'text', 'Badge');
    customValue($employer, Customer::factory()->create(['name_en' => 'Sara']), ['value_text' => 'Bapco']);
    $saved = ['subject' => 'tenants', 'columns' => ['name', 'cf_employer', 'cf_badge'],
        'filters' => [['field' => 'cf_employer', 'op' => 'contains', 'value' => 'bap']], 'sort' => ['field' => 'name', 'dir' => 'asc']];

    $employer->forceFill(['label_en' => 'Employer name'])->save();
    $renamed = runReport($this->leasing, $saved);
    expect($renamed->columns[1]->label)->toBe('Employer name')->and($renamed->rows[0]['cf_employer'])->toBe('Bapco');

    $employer->forceFill(['archived_at' => now()])->save();
    $archived = runReport($this->leasing, $saved);
    expect($archived->columns[1]->label)->toBe('Employer name (archived)')
        ->and($archived->rows)->toHaveCount(1) // its filter no longer narrows
        ->and($archived->rows[0]['cf_employer'])->toBeNull();

    $badge->delete();
    [$data, $dropped] = ReportDefinition::forViewer($saved, $this->leasing);
    expect($data['columns'])->toBe(['name', 'cf_employer'])->and($dropped)->toBe(['cf_badge'])
        ->and(runReport($this->leasing, $data)->rows)->toHaveCount(1);
});

test('the builder offers active fields and keeps an archived one only where a report already uses it', function () {
    customField('customer', 'text', 'Employer');
    customField('customer', 'text', 'Old code', ['archived_at' => now()]);

    Livewire::actingAs($this->admin)->test(Builder::class)->call('setSubject', 'tenants')
        ->assertSee('Employer')->assertDontSee('Old code (archived)');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/CustomFields/ReportBuilderTest.php`
Expected: FAIL with `Undefined array key "cf_balcony_area"`.

- [ ] **Step 3: `Field` can be archived.** In `app/Reports/Builder/Field.php`, replace

```php
        public ?PermissionName $permission = null,
    ) {}
```

with

```php
        public ?PermissionName $permission = null,
        public bool $archived = false, // custom fields plan ruling 5: an archived custom field: empty column, filter skipped, hidden from pickers
    ) {}
```

- [ ] **Step 4: Create `app/Reports/Builder/CustomColumns.php`:**

```php
<?php

namespace App\Reports\Builder;

use App\Enums\CustomFieldEntity;
use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Models\CustomFieldChoice;
use LogicException;

/**
 * Spec 2026-10-21 custom fields §5: a kind's fields as builder fields keyed "<prefix><key>". Files are not columns.
 * Each value is a correlated subquery on the unique (custom_field_id, entity_id) index, so no joins are added and
 * MAX_COLUMNS stays the only limit. An archived field keeps its key, so saved reports still run: its column is empty
 * and its label says so (plan ruling 5). The SQL embeds only our integer ids and $idSql, which subjects write.
 */
final class CustomColumns
{
    /** @return list<Field> */
    public static function fields(CustomFieldEntity $entity, string $prefix, string $section, string $idSql): array
    {
        $fields = [];
        $list = CustomField::query()->where('entity', $entity)->where('type', '!=', CustomFieldType::File)->with('choices')
            ->orderByRaw('archived_at IS NOT NULL')->orderBy('position')->orderBy('id')->get();
        foreach ($list as $field) {
            $archived = $field->archived_at !== null;
            $id = (int) $field->id;
            $column = $field->type->column();
            $sql = $archived ? 'NULL'
                : "(SELECT cfv{$id}.{$column} FROM custom_field_values cfv{$id} WHERE cfv{$id}.custom_field_id = {$id} AND cfv{$id}.entity_id = {$idSql})";
            $label = $archived ? (string) __(':label (archived)', ['label' => $field->label()]) : $field->label();
            [$type, $options, $groupable] = match ($field->type) {
                CustomFieldType::Text => [FieldType::Text, [], true],
                CustomFieldType::LongText => [FieldType::Text, [], false],
                CustomFieldType::Number => [FieldType::Number, [], false],
                CustomFieldType::Date => [FieldType::Date, [], true],
                CustomFieldType::Bool => [FieldType::Boolean, [], true],
                // Hidden choices stay: records keep them (plan ruling 11).
                CustomFieldType::Choice => [FieldType::Enum, $field->choices->mapWithKeys(fn (CustomFieldChoice $c) => [(string) $c->id => $c->label()])->all(), true],
                CustomFieldType::File => throw new LogicException('Files are not report columns.'),
            };
            $fields[] = new Field($prefix.$field->key, $label, $section, $type, $sql, $options, $groupable, true, null, $archived);
        }

        return $fields;
    }
}
```

- [ ] **Step 5: The four subjects.** In each, add to the imports:

```php
use App\Enums\CustomFieldEntity;
use App\Reports\Builder\CustomColumns;
```

and add the custom columns at the end of `define()`'s list:
  - `app/Reports/Builder/Subjects/Tenants.php`: replace

```php
            Field::money('outstanding', __('Outstanding'), __('Amounts'), "({$balance} - {$credit})", PermissionName::ReportsFinancial),
        ];
```

  with

```php
            Field::money('outstanding', __('Outstanding'), __('Amounts'), "({$balance} - {$credit})", PermissionName::ReportsFinancial),
            ...CustomColumns::fields(CustomFieldEntity::Customer, 'cf_', __('More details'), 'customers.id'),
        ];
```

  - `app/Reports/Builder/Subjects/Units.php`: replace

```php
            Field::date('agreement_end', __('Agreement end'), __('Occupancy'), 'cau.end_date'),
        ];
```

  with

```php
            Field::date('agreement_end', __('Agreement end'), __('Occupancy'), 'cau.end_date'),
            ...CustomColumns::fields(CustomFieldEntity::Unit, 'cf_', __('More details'), 'units.id'),
            ...CustomColumns::fields(CustomFieldEntity::Building, 'cfb_', __('Building'), 'units.building_id'),
        ];
```

  - `app/Reports/Builder/Subjects/Agreements.php`: replace

```php
                '(SELECT COALESCE(SUM(da.deposit_amount), 0) FROM agreement_units da WHERE da.agreement_id = agreements.id)'),
        ];
```

  with

```php
                '(SELECT COALESCE(SUM(da.deposit_amount), 0) FROM agreement_units da WHERE da.agreement_id = agreements.id)'),
            ...CustomColumns::fields(CustomFieldEntity::Agreement, 'cf_', __('More details'), 'agreements.id'),
            ...CustomColumns::fields(CustomFieldEntity::Customer, 'cft_', __('Tenant'), 'agreements.customer_id'),
        ];
```

  - `app/Reports/Builder/Subjects/Owners.php`: replace

```php
                    WHERE oc2.owner_id = owners.id)", filterable: false),
        ];
```

  with

```php
                    WHERE oc2.owner_id = owners.id)", filterable: false),
            ...CustomColumns::fields(CustomFieldEntity::Owner, 'cf_', __('More details'), 'owners.id'),
        ];
```

- [ ] **Step 6: An archived field's filter no longer narrows.** In `app/Reports/Builder/QueryCompiler.php`, replace

```php
        foreach ($def->filters as $filter) {
            self::applyFilter($query, $fields[$filter['field']], $filter['op'], $filter['value']);
        }
```

with

```php
        foreach ($def->filters as $filter) {
            if ($fields[$filter['field']]->archived) {
                continue; // custom fields plan ruling 5: the column is empty, so its filter is set aside rather than emptying the report
            }
            self::applyFilter($query, $fields[$filter['field']], $filter['op'], $filter['value']);
        }
```

- [ ] **Step 7: A deleted field's key is left out with the existing note.** In `app/Reports/Builder/ReportDefinition.php`, `forViewer()`, replace

```php
        $hidden = $subject->hiddenFrom($user, CarbonImmutable::parse($asAt, 'Asia/Bahrain'));
        $dropped = [];
        $gone = function (mixed $key) use ($hidden, &$dropped): bool {
            if (is_string($key) && isset($hidden[$key])) {
                $dropped[$key] = $hidden[$key];

                return true;
            }

            return false;
        };
```

with

```php
        $hidden = $subject->hiddenFrom($user, CarbonImmutable::parse($asAt, 'Asia/Bahrain'));
        $known = $subject->fields($user, CarbonImmutable::parse($asAt, 'Asia/Bahrain'));
        $dropped = [];
        $gone = function (mixed $key) use ($hidden, $known, &$dropped): bool {
            // Custom fields plan ruling 5: a deleted custom field's key is left out the same way, named by its key.
            $deleted = is_string($key) && preg_match('/^cf[bt]?_/', $key) === 1 && ! isset($known[$key]) && ! isset($hidden[$key]);
            if (is_string($key) && (isset($hidden[$key]) || $deleted)) {
                $dropped[$key] = $hidden[$key] ?? $key;

                return true;
            }

            return false;
        };
```

The Builder, `SendScheduledReport` and `PackageRunner` already call `forViewer()` before `fromArray()`, so saved reports, schedules and packages all follow.

- [ ] **Step 8: The pickers hide archived fields unless already used.** In `resources/views/livewire/reports/builder.blade.php` (this file uses inline `@php(...)`; add no `@php … @endphp` block):
  - Replace

```blade
                            @foreach ($sectionFields as $f)
                                <label class="flex items-center gap-2 py-0.5 text-sm">
```

  with

```blade
                            @foreach ($sectionFields as $f)
                                @continue($f->archived && ! in_array($f->key, is_array($def['columns'] ?? null) ? $def['columns'] : [], true))
                                <label class="flex items-center gap-2 py-0.5 text-sm">
```

  - Replace `@foreach ($fields as $f)@if ($f->filterable)<option value="{{ $f->key }}">{{ $f->label }}</option>@endif @endforeach` with

```blade
@foreach ($fields as $f)@if ($f->filterable && (! $f->archived || ($filter['field'] ?? null) === $f->key))<option value="{{ $f->key }}">{{ $f->label }}</option>@endif @endforeach
```

  - Replace `@foreach ($fields as $f)<option value="{{ $f->key }}">{{ $f->label }}</option>@endforeach` (the Sort by select) with

```blade
@foreach ($fields as $f)@if (! $f->archived || ($def['sort']['field'] ?? null) === $f->key)<option value="{{ $f->key }}">{{ $f->label }}</option>@endif @endforeach
```

  - Replace `@foreach ($fields as $f)@if ($f->groupable)<option value="{{ $f->key }}">{{ $f->label }}</option>@endif @endforeach` with

```blade
@foreach ($fields as $f)@if ($f->groupable && (! $f->archived || ($g['field'] ?? null) === $f->key))<option value="{{ $f->key }}">{{ $f->label }}</option>@endif @endforeach
```

- [ ] **Step 9: Arabic.** In `lang/ar.json`, after `    "Credit refund reversal": "عكس استرداد رصيد",` insert:

```json
    ":label (archived)": ":label (مؤرشف)",
    "Building": "العقار",
```

- [ ] **Step 10: Run the tests.**

Run: `php artisan test tests/Feature/CustomFields/ReportBuilderTest.php`, then `php artisan test tests/Feature/ReportBuilder`, then `php artisan test tests/Feature/ReportPackages`.
Expected: all PASS. With no custom fields, the subjects' field lists are unchanged.

- [ ] **Step 11: Gates and commit.**

Run: `vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add app/Reports/Builder/CustomColumns.php app/Reports/Builder/Field.php app/Reports/Builder/QueryCompiler.php app/Reports/Builder/ReportDefinition.php \
  app/Reports/Builder/Subjects/Tenants.php app/Reports/Builder/Subjects/Units.php app/Reports/Builder/Subjects/Agreements.php app/Reports/Builder/Subjects/Owners.php \
  resources/views/livewire/reports/builder.blade.php lang/ar.json tests/Feature/CustomFields/ReportBuilderTest.php
git commit -m "$(cat <<'EOF'
Report builder custom columns: each active field a typed column and filter of its subject by key, building fields on Units and tenant fields on Agreements, no joins, archived fields empty with a note, deleted keys left out

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 8: CSV import: `cf_<key>` columns on the record sheets

**Files:**
- Create: `app/CustomFields/CustomFieldImport.php`
- Modify: `app/Enums/ImportKind.php`, `app/Actions/Import/RunImport.php`, `lang/ar.json`
- Test: `tests/Feature/CustomFields/ImportTest.php`

**Interfaces:**
- Consumes: `CustomFields::active()`, `::validation(..., enforceRequired: false)`, `::save()` (Task 3); `RunImport`'s per-row savepoint.
- Produces:
  - `ImportKind::customFieldEntity(): ?CustomFieldEntity`.
  - `CustomFieldImport::headerProblems(CustomFieldEntity $entity, list<string> $headers): list<string>`.
  - `CustomFieldImport::save(User $actor, Model $record, array $row): void`.
  - `RunImport::agreement()` now returns `Agreement`.

- [ ] **Step 1: Write the failing test** `tests/Feature/CustomFields/ImportTest.php`:

```php
<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\Import\RunImport;
use App\CustomFields\CustomFields;
use App\Enums\ImportKind;
use App\Enums\RoleName;
use App\Models\Agreement;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\CustomFieldValue;
use App\Models\Owner;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Spatie\SimpleExcel\SimpleExcelWriter;

/** An .xlsx with the kind's full header row plus any cf_ columns the first row names (custom field import tests). */
function cfImportFile(ImportKind $kind, array $rows): string
{
    $path = sys_get_temp_dir().'/'.uniqid('cf-'.$kind->value.'-').'.xlsx';
    $writer = SimpleExcelWriter::create($path);
    foreach ($rows as $row) {
        $writer->addRow([...array_fill_keys($kind->headers(), ''), ...$row]);
    }
    $writer->close();

    return $path;
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['go_live_at' => '2026-11-01 00:00:00']);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    fixtureBanks();
    $this->vendor = User::factory()->withTwoFactor()->create()->assignRole(RoleName::VendorSupport);
    customField('building', 'text', 'Title deed');
    customField('unit', 'number', 'Balcony area', ['required' => true]);
    customField('unit', 'file', 'Floor plan');
    customField('owner', 'bool', 'VAT registered');
    customField('customer', 'choice', 'Source', choices: [['Walk-in', 'زيارة'], 'Agent']);
    customField('customer', 'date', 'Visa expiry');
    customField('agreement', 'text', 'Broker');
    $this->unit = fn (string $code, array $cf = []) => ['building_code' => 'MT', 'code' => $code, 'use' => 'residential', 'type' => 'flat',
        'furnishing' => 'unfurnished', 'list_rent' => '450.000', 'blocked' => 'no', ...$cf];
    $this->tenant = fn (string $name, string $id, array $cf = []) => ['type' => 'individual', 'name_en' => $name, 'id_type' => 'cpr', 'id_number' => $id,
        'mobile' => '+973360'.substr($id, -5), ...$cf];
    $this->building = fn (array $cf = []) => cfImportFile(ImportKind::Buildings, [['code' => 'MT', 'name' => 'Marina Tower', 'type' => 'residential', ...$cf]]);
});

test('cf_ columns import through the same rules: choices by English or Arabic label, dd/mm/yyyy dates, required not enforced', function () {
    $result = app(RunImport::class)->handle($this->vendor, [
        'buildings' => ($this->building)(['cf_title_deed' => 'TD-55']),
        'units' => cfImportFile(ImportKind::Units, [($this->unit)('101', ['cf_balcony_area' => '12.75']), ($this->unit)('102', ['cf_balcony_area' => ''])]),
        'owners' => cfImportFile(ImportKind::Owners, [['type' => 'person', 'name_en' => 'Ali Hassan', 'id_type' => 'cpr', 'id_number' => '080101234', 'cf_vat_registered' => 'Yes']]),
        'customers' => cfImportFile(ImportKind::Customers, [
            ($this->tenant)('Sara Ahmed', '090202345', ['cf_source' => 'زيارة', 'cf_visa_expiry' => '31/03/2027']),
            ($this->tenant)('Omar Ali', '090202346', ['cf_source' => 'agent', 'cf_visa_expiry' => '']),
        ]),
    ], commit: true);

    expect($result->errors)->toBe([])->and($result->committed)->toBeTrue();
    $shown = fn ($record) => array_column(CustomFields::display($record), 'text', 'label');
    expect($shown(Building::query()->where('code', 'MT')->sole()))->toBe(['Title deed' => 'TD-55'])
        ->and($shown(Unit::query()->where('code', '101')->sole()))->toBe(['Balcony area' => '12.75'])
        ->and($shown(Unit::query()->where('code', '102')->sole()))->toBe(['Balcony area' => '—']) // required, left blank: imported, shown as missing
        ->and($shown(Owner::query()->sole()))->toBe(['VAT registered' => 'Yes'])
        ->and($shown(Customer::query()->where('id_number', '090202345')->sole()))->toBe(['Source' => 'Walk-in', 'Visa expiry' => '31/03/2027'])
        ->and($shown(Customer::query()->where('id_number', '090202346')->sole()))->toBe(['Source' => 'Agent']);
});

test('a bad cf_ value fails its row by the same rules; an unknown or file cf_ column fails the file', function () {
    $result = app(RunImport::class)->handle($this->vendor, [
        'buildings' => ($this->building)(),
        'units' => cfImportFile(ImportKind::Units, [($this->unit)('101', ['cf_balcony_area' => '1.2345']), ($this->unit)('102', ['cf_balcony_area' => '3'])]),
        'owners' => cfImportFile(ImportKind::Owners, [['type' => 'person', 'name_en' => 'Ali Hassan', 'id_type' => 'cpr', 'id_number' => '080101234', 'cf_unknown' => 'x']]),
        'customers' => cfImportFile(ImportKind::Customers, [($this->tenant)('Sara Ahmed', '090202345', ['cf_source' => 'Newspaper'])]),
    ], commit: true);

    expect($result->committed)->toBeFalse()
        ->and($result->errors['units'])->toBe([2 => ['Enter Balcony area as a number with up to 3 decimals.']])
        ->and($result->errors['owners'])->toBe([1 => ['Unknown custom field column cf_unknown.']])
        ->and($result->errors['customers'])->toBe([2 => ['Source: "Newspaper" is not one of: Walk-in, Agent.']])
        ->and(CustomFieldValue::query()->count())->toBe(0);

    $files = app(RunImport::class)->handle($this->vendor, ['units' => cfImportFile(ImportKind::Units, [($this->unit)('101', ['cf_floor_plan' => 'plan.pdf'])])], commit: false);
    expect($files->errors['units'])->toBe([1 => ['File fields cannot be imported: cf_floor_plan.']]);
});

test('agreement rows take their cf_ values from the first row and must agree on them', function () {
    Storage::fake('local');
    $base = [
        'buildings' => ($this->building)(),
        'units' => cfImportFile(ImportKind::Units, [($this->unit)('101', ['cf_balcony_area' => '5']), ($this->unit)('103', ['cf_balcony_area' => '6'])]),
        'customers' => cfImportFile(ImportKind::Customers, [($this->tenant)('Sara Ahmed', '090202345')]),
    ];
    $row = fn (string $unit, string $broker) => ['import_ref' => 'L-001', 'customer_id_type' => 'cpr', 'customer_id_number' => '090202345',
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'frequency' => 'monthly', 'building_code' => 'MT', 'unit_code' => $unit,
        'rent' => '450.000', 'tax_category' => 'exempt', 'cf_broker' => $broker];

    $bad = app(RunImport::class)->handle($this->vendor, [...$base, 'agreements' => cfImportFile(ImportKind::Agreements, [$row('101', 'Gulf Realty'), $row('103', 'Other')])], commit: false);
    expect($bad->errors['agreements'])->toBe([2 => ['Rows of agreement L-001 disagree on cf_broker.']]);

    $good = app(RunImport::class)->handle($this->vendor, [...$base, 'agreements' => cfImportFile(ImportKind::Agreements, [$row('101', 'Gulf Realty'), $row('103', 'Gulf Realty')])], commit: true);
    expect($good->errors)->toBe([])
        ->and(array_column(CustomFields::display(Agreement::query()->where('import_ref', 'L-001')->sole()), 'text', 'label'))->toBe(['Broker' => 'Gulf Realty']);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/CustomFields/ImportTest.php`
Expected: FAIL. The first test's values are missing: `display()` returns `[]` for the building.

- [ ] **Step 3: The import kinds name their record kind.** In `app/Enums/ImportKind.php`, add after `label()`:

```php
    /** Spec 2026-10-21 custom fields §5: the record kind whose cf_<key> columns this file takes, if any. */
    public function customFieldEntity(): ?CustomFieldEntity
    {
        return match ($this) {
            self::Buildings => CustomFieldEntity::Building,
            self::Units => CustomFieldEntity::Unit,
            self::Owners => CustomFieldEntity::Owner,
            self::Customers => CustomFieldEntity::Customer,
            self::Agreements => CustomFieldEntity::Agreement,
            default => null,
        };
    }
```

- [ ] **Step 4: Create `app/CustomFields/CustomFieldImport.php`:**

```php
<?php

namespace App\CustomFields;

use App\Enums\CustomFieldEntity;
use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Models\CustomFieldChoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Spec 2026-10-21 custom fields §5: optional cf_<key> columns on the record sheets, by the form's rules with required
 * not enforced; choices by EN or AR label; files not importable; an unknown cf_ column is an error (plan ruling 7).
 */
final class CustomFieldImport
{
    /**
     * @param  list<string>  $headers
     * @return list<string> one message per cf_ column that is not an importable active field of the kind
     */
    public static function headerProblems(CustomFieldEntity $entity, array $headers): array
    {
        $fields = CustomFields::active($entity)->keyBy('key');
        $problems = [];
        foreach ($headers as $header) {
            if (! str_starts_with($header, 'cf_')) {
                continue;
            }
            $field = $fields->get(substr($header, 3));
            if ($field === null) {
                $problems[] = (string) __('Unknown custom field column :column.', ['column' => $header]);
            } elseif ($field->type === CustomFieldType::File) {
                $problems[] = (string) __('File fields cannot be imported: :column.', ['column' => $header]);
            }
        }

        return $problems;
    }

    /**
     * Validates and saves a just-imported record's cf_ cells; a blank cell is skipped. Runs inside the row's savepoint,
     * so a bad cell fails the row and rolls the record back too.
     *
     * @param  array<string, mixed>  $row  a normalised import row
     */
    public static function save(User $actor, Model $record, array $row): void
    {
        $entity = CustomFieldEntity::of($record);
        $custom = [];
        foreach (CustomFields::active($entity) as $field) {
            $cell = $row['cf_'.$field->key] ?? null;
            if ($cell === null || $cell === '' || $field->type === CustomFieldType::File) {
                continue;
            }
            $custom[$field->key] = match ($field->type) {
                CustomFieldType::Choice => self::choice($field, (string) $cell),
                CustomFieldType::Bool => self::yesNo($field, (string) $cell),
                CustomFieldType::Date => preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', (string) $cell, $m) === 1 ? "{$m[3]}-{$m[2]}-{$m[1]}" : $cell,
                default => $cell,
            };
        }
        if ($custom === []) {
            return;
        }

        $data = ['custom' => $custom];
        [$rules, $messages, $names] = CustomFields::validation($entity, $record, $data, enforceRequired: false);
        $validated = Validator::make($data, $rules, $messages, $names)->validate();
        CustomFields::save($actor, $record, (array) $validated['custom']);
    }

    /** A visible choice by its English label (any case) or its Arabic label; its id as the form would send it. */
    private static function choice(CustomField $field, string $cell): string
    {
        $visible = $field->choices->whereNull('hidden_at');
        $match = $visible->first(fn (CustomFieldChoice $c) => strcasecmp(trim($cell), $c->label_en) === 0 || ($c->label_ar !== null && trim($cell) === $c->label_ar));

        return $match !== null ? (string) $match->id : throw ValidationException::withMessages(['cf_'.$field->key => __(':field: ":value" is not one of: :list.', [
            'field' => $field->label_en, 'value' => $cell, 'list' => $visible->pluck('label_en')->implode(', '),
        ])]);
    }

    private static function yesNo(CustomField $field, string $cell): string
    {
        return match (mb_strtolower(trim($cell))) {
            'yes', 'y', 'true', '1', 'نعم' => '1',
            'no', 'n', 'false', '0', 'لا' => '0',
            default => throw ValidationException::withMessages(['cf_'.$field->key => __(':field: enter yes or no.', ['field' => $field->label_en])]),
        };
    }
}
```

- [ ] **Step 5: `RunImport`.** In `app/Actions/Import/RunImport.php`:
  - Add to the imports: `use App\CustomFields\CustomFieldImport;`.
  - In `handle()`, replace

```php
                if ($missing !== []) {
                    $errors[$kind->value][1] = [__('Missing columns: :list', ['list' => implode(', ', $missing)])];

                    continue;
                }
```

with

```php
                if ($missing !== []) {
                    $errors[$kind->value][1] = [__('Missing columns: :list', ['list' => implode(', ', $missing)])];

                    continue;
                }

                // Spec 2026-10-21 custom fields §5: every cf_ column must be an importable active field of the kind (plan ruling 7).
                $entity = $kind->customFieldEntity();
                $unknown = $entity === null ? [] : CustomFieldImport::headerProblems($entity, $headers);
                if ($unknown !== []) {
                    $errors[$kind->value][1] = $unknown;

                    continue;
                }
```

  - Replace the whole `importRow()` method with:

```php
    /** @param  list<array<string, mixed>>  $rows  one row, except for agreements: the rows sharing one import_ref */
    private function importRow(User $actor, ImportKind $kind, array $rows, CarbonImmutable $cutover): void
    {
        $row = $rows[0];

        // Spec 2026-10-21 custom fields §5: the record kinds take cf_ columns, saved after the record in this row's savepoint.
        if ($kind->customFieldEntity() !== null) {
            $record = match ($kind) {
                ImportKind::Buildings => $this->buildings->handle($actor, null, self::buildingRow($row)),
                ImportKind::Units => $this->units->handle($actor, null, [
                    ...$row,
                    'type' => UnitType::canonical((string) ($row['type'] ?? '')) ?? $row['type'] ?? null,
                    'building_id' => $this->buildingId($row['building_code'] ?? null),
                    'blocked' => in_array(strtolower((string) ($row['blocked'] ?? '')), ['yes', 'y', 'true', '1'], true),
                ]),
                ImportKind::Owners => $this->owners->handle($actor, null, self::listRow($row), viaImport: true),
                ImportKind::Customers => $this->customers->handle($actor, null, self::listRow($row)),
                default => $this->agreement($actor, $rows, $cutover),
            };
            CustomFieldImport::save($actor, $record, $row);

            return;
        }

        match ($kind) {
            ImportKind::OwnerContracts => $this->ownerContract($actor, $row, $cutover),
            ImportKind::CustomerBalances => $this->customerBalance($actor, $row, $cutover),
            ImportKind::DepositsHeld => $this->depositHeld($actor, $rows[0], $cutover),
            ImportKind::Cheques => $this->cheque($actor, self::listRow($rows[0])),
            ImportKind::OwnerBalances => $this->ownerBalance($actor, $row, $cutover),
            default => throw new LogicException("Unhandled import kind [{$kind->value}]."),
        };
    }
```

  - In `agreement()`, change the return type `): void` to `): Agreement` (the method signature `private function agreement(User $actor, array $rows, CarbonImmutable $cutover)`). Then replace

```php
        foreach (['customer_id_type', 'customer_id_number', 'start_date', 'end_date', 'frequency', 'billing_day', 'grace_days', 'notice_period_days'] as $column) {
```

with

```php
        // Custom fields plan ruling 7: the first row's cf_ cells are the agreement's, so every row must agree on them too.
        $agreed = ['customer_id_type', 'customer_id_number', 'start_date', 'end_date', 'frequency', 'billing_day', 'grace_days', 'notice_period_days',
            ...array_values(array_filter(array_map('strval', array_keys($first)), fn (string $c) => str_starts_with($c, 'cf_')))];
        foreach ($agreed as $column) {
```

and replace

```php
        $this->activateAgreement->handle($agreement, $actor, $cutover);
    }
```

with

```php
        $this->activateAgreement->handle($agreement, $actor, $cutover);

        return $agreement;
    }
```

- [ ] **Step 6: Arabic.** In `lang/ar.json`, after `    "Credit refund reversal": "عكس استرداد رصيد",` insert:

```json
    "Unknown custom field column :column.": "عمود حقل مخصص غير معروف :column.",
    "File fields cannot be imported: :column.": "لا يمكن استيراد حقول الملفات: :column.",
    ":field: \":value\" is not one of: :list.": ":field: \":value\" ليس من: :list.",
    ":field: enter yes or no.": ":field: أدخل نعم أو لا.",
```

- [ ] **Step 7: Run the tests.**

Run: `php artisan test tests/Feature/CustomFields/ImportTest.php`, then `php artisan test tests/Feature/Import/ImportTest.php`.
Expected: PASS, PASS. The old import files have no `cf_` columns, so they are unaffected.

- [ ] **Step 8: Gates and commit.**

Run: `vendor/bin/pint --dirty`, `php vendor/bin/phpstan clear-result-cache`, `php vendor/bin/phpstan analyse --memory-limit=2G` (0 errors), `npm run build`.

```bash
git add app/CustomFields/CustomFieldImport.php app/Enums/ImportKind.php app/Actions/Import/RunImport.php lang/ar.json tests/Feature/CustomFields/ImportTest.php
git commit -m "$(cat <<'EOF'
Import cf_ columns on the building, unit, owner, tenant and agreement sheets: same rules without required, choices by English or Arabic label, per-row errors, unknown and file columns refused

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 9: Portals: "Show in portal" fields on the tenant and owner pages, file downloads through the portal's own checks, and the full suite

**Files:**
- Modify: `app/CustomFields/CustomFields.php` (portal scope)
- Modify: `app/Http/Controllers/Portal/DownloadController.php`, `app/Http/Controllers/Portal/OwnerDownloadController.php`, `routes/portal.php`
- Modify: `app/Livewire/Portal/Home.php`, `app/Livewire/Portal/Agreements.php`, `app/Livewire/Portal/Owner/Home.php`, `app/Livewire/Portal/Owner/Buildings.php`
- Modify: `resources/views/livewire/portal/home.blade.php`, `agreements.blade.php`, `owner/home.blade.php`, `owner/buildings.blade.php`
- Modify: `tests/Feature/Portal/ArabicTest.php`
- Test: `tests/Feature/CustomFields/PortalTest.php`

**Interfaces:**
- Consumes:
  - `CustomFields::display(Model, portal: true)` (Task 3) and `<x-custom-fields.display :items :file-route />` (Task 3).
  - `SaveAgreementDetails` (Task 5).
  - `InPortal::customer()` / `customerId()` / `owner()` / `ownerId()`, `OwnerPortal::buildings(int)`, `OwnerContract::portalFor(int)`.
  - The Pest helpers `portalAccount()` and `portalOwnerFixture()`.
- Produces:
  - `CustomFields::tenantScope(int $customerId): array`.
  - `CustomFields::ownerScope(int $ownerId): array`.
  - `CustomFields::portalDocument(string $documentId, array $scope): Document`.
  - Routes `portal.custom-fields.file` and `portal.owner.custom-fields.file`.
  - Controller methods `DownloadController::customFieldFile(string)` and `OwnerDownloadController::customFieldFile(string)`.

- [ ] **Step 1: Write the failing test** `tests/Feature/CustomFields/PortalTest.php`:

```php
<?php

use App\Actions\Agreements\SaveAgreementDetails;
use App\Actions\Documents\StoreDocument;
use App\Actions\EnsureNumberSequences;
use App\Enums\DocumentCategory;
use App\Enums\RoleName;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->admin = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Admin);
    $pdf = fn (string $name) => UploadedFile::fake()->create($name, 5, 'application/pdf');

    // Tenants: Sara and Omar in Seef Tower.
    $building = Building::factory()->create(['name' => 'Seef Tower']);
    $sara = Customer::factory()->create(['name_en' => 'Sara Tenant', 'email' => 'sara@tenant.test']);
    $omar = Customer::factory()->create(['name_en' => 'Omar Tenant', 'email' => 'omar@tenant.test']);
    $saraUnit = Unit::factory()->for($building)->create(['code' => '101']);
    $omarUnit = Unit::factory()->for($building)->create(['code' => '102']);
    $saraAgreement = activeAgreement(['customer_id' => $sara->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$saraUnit]);
    $omarAgreement = activeAgreement(['customer_id' => $omar->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$omarUnit]);
    $this->saraAccount = portalAccount($sara);

    $employer = customField('customer', 'text', 'Employer', ['in_portal' => true, 'label_ar' => 'جهة العمل']);
    $rating = customField('customer', 'text', 'Internal rating');
    $scan = customField('customer', 'file', 'ID scan');
    $this->broker = customField('agreement', 'text', 'Broker', ['in_portal' => true]);
    $this->permit = customField('agreement', 'file', 'Parking permit', ['in_portal' => true]);
    $meter = customField('unit', 'text', 'Meter number', ['in_portal' => true]);
    customValue($employer, $sara, ['value_text' => 'Bapco']);
    customValue($employer, $omar, ['value_text' => 'Alba']);
    customValue($rating, $sara, ['value_text' => 'Gold']);
    customValue($meter, $saraUnit, ['value_text' => 'EWA-101']);
    customValue($meter, $omarUnit, ['value_text' => 'EWA-102']);
    $details = app(SaveAgreementDetails::class);
    $details->handle($this->admin, $saraAgreement, ['broker' => 'Gulf Realty', 'parking_permit' => $pdf('old-permit.pdf')]);
    $details->handle($this->admin, $saraAgreement, ['parking_permit' => $pdf('permit.pdf')]); // replaces it
    $details->handle($this->admin, $omarAgreement, ['broker' => 'Omar Broker', 'parking_permit' => $pdf('omar-permit.pdf')]);
    $named = fn (string $name) => Document::query()->where('original_name', $name)->sole();
    [$this->oldPermit, $this->saraPermit, $this->omarPermit] = [$named('old-permit.pdf'), $named('permit.pdf'), $named('omar-permit.pdf')];
    $this->secretScan = app(StoreDocument::class)->handle($this->admin, $sara, $pdf('id-scan.pdf'), DocumentCategory::CustomField);
    customValue($scan, $sara, ['document_id' => $this->secretScan->id]); // not "Show in portal"

    // Owners: Ali and Badr, each with a managed tower.
    $this->ali = portalOwnerFixture('Ali Owner', '7', $this->admin);
    $this->badr = portalOwnerFixture('Badr Owner', '8', $this->admin);
    $plot = customField('owner', 'text', 'Plot number', ['in_portal' => true, 'label_ar' => 'رقم القسيمة']);
    $deed = customField('building', 'text', 'Title deed no', ['in_portal' => true]);
    $note = customField('building', 'text', 'Staff note');
    $sitePlan = customField('building', 'file', 'Site plan', ['in_portal' => true]);
    customValue($plot, $this->ali['owner'], ['value_text' => 'PL-7']);
    customValue($deed, $this->ali['tower'], ['value_text' => 'TD-TOWER-7']);
    customValue($note, $this->ali['tower'], ['value_text' => 'Leaky roof']);
    customValue($deed, $this->badr['tower'], ['value_text' => 'TD-TOWER-8']);
    $this->aliPlan = app(StoreDocument::class)->handle($this->admin, $this->ali['tower'], $pdf('ali-plan.pdf'), DocumentCategory::CustomField);
    $this->badrPlan = app(StoreDocument::class)->handle($this->admin, $this->badr['tower'], $pdf('badr-plan.pdf'), DocumentCategory::CustomField);
    customValue($sitePlan, $this->ali['tower'], ['document_id' => $this->aliPlan->id]);
    customValue($sitePlan, $this->badr['tower'], ['document_id' => $this->badrPlan->id]);
});

test('the tenant portal shows filled "Show in portal" fields of their own records, in their language', function () {
    $this->actingAs($this->saraAccount, 'tenant')->get(route('portal.home'))->assertOk()
        ->assertSeeInOrder(['More details', 'Employer', 'Bapco'])
        ->assertDontSee('Internal rating')->assertDontSee('Gold')->assertDontSee('ID scan')->assertDontSee('Alba');
    $this->get(route('portal.agreements'))->assertOk()
        ->assertSeeInOrder(['Broker', 'Gulf Realty', 'Parking permit', 'permit.pdf', 'Meter number', 'EWA-101'])
        ->assertDontSee('old-permit.pdf')->assertDontSee('Omar Broker')->assertDontSee('EWA-102');

    $this->saraAccount->forceFill(['locale' => 'ar'])->save();
    $this->get(route('portal.home'))->assertOk()->assertSeeInOrder(['تفاصيل إضافية', 'جهة العمل', 'Bapco'])->assertDontSee('Employer');
});

test('the owner portal shows owner fields on Home and their own buildings\' fields on the building cards', function () {
    $this->actingAs($this->ali['account'], 'tenant')->get(route('portal.owner.home'))->assertOk()->assertSeeInOrder(['More details', 'Plot number', 'PL-7']);
    $this->get(route('portal.owner.buildings'))->assertOk()
        ->assertSeeInOrder(['Ali Owner Tower', 'Title deed no', 'TD-TOWER-7', 'Site plan', 'ali-plan.pdf'])
        ->assertDontSee('TD-TOWER-8')->assertDontSee('Leaky roof')->assertDontSee('badr-plan.pdf');

    $this->ali['account']->forceFill(['locale' => 'ar'])->save();
    $this->get(route('portal.owner.home'))->assertOk()->assertSee('رقم القسيمة')->assertDontSee('Plot number');
});

test('portal file downloads go through the portal\'s own ownership checks', function () {
    $this->actingAs($this->saraAccount, 'tenant')->get(route('portal.custom-fields.file', $this->saraPermit))->assertOk()->assertDownload('permit.pdf');
    expect(Activity::query()->where('event', 'document.downloaded')->latest('id')->first()?->causer_id)->toBe($this->saraAccount->id);
    foreach ([$this->omarPermit, $this->oldPermit, $this->secretScan, $this->aliPlan] as $document) {
        $this->get(route('portal.custom-fields.file', $document))->assertNotFound();
    }
    $this->get('/portal/custom-fields/123456789012345678901234567890')->assertNotFound();
    $this->get(route('portal.owner.custom-fields.file', $this->aliPlan))->assertNotFound(); // a tenant-only account has no owner side

    $this->actingAs($this->ali['account'], 'tenant')->get(route('portal.owner.custom-fields.file', $this->aliPlan))->assertOk()->assertDownload('ali-plan.pdf');
    $this->get(route('portal.owner.custom-fields.file', $this->badrPlan))->assertNotFound();
    $this->get(route('portal.custom-fields.file', $this->saraPermit))->assertNotFound(); // an owner-only account has no tenant side
});

test('archived fields leave the portal, and their files with them', function () {
    $this->broker->forceFill(['archived_at' => now()])->save();
    $this->permit->forceFill(['archived_at' => now()])->save();

    $this->actingAs($this->saraAccount, 'tenant')->get(route('portal.agreements'))->assertOk()
        ->assertDontSee('Gulf Realty')->assertDontSee('permit.pdf')->assertSee('EWA-101');
    $this->get(route('portal.custom-fields.file', $this->saraPermit))->assertNotFound();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/CustomFields/PortalTest.php`
Expected: FAIL with `Route [portal.custom-fields.file] not defined.` (and the pages do not show the fields).

- [ ] **Step 3: The portal scope.** In `app/CustomFields/CustomFields.php`, add to the imports:

```php
use App\Enums\AgreementStatus;
use App\Models\Agreement;
use App\Models\AgreementUnit;
use App\Models\OwnerContract;
use Illuminate\Database\Eloquent\Builder;
```

and add before `private static function clean(`:

```php
    /**
     * Plan ruling 8: the records whose "Show in portal" files a signed-in tenant may download: themselves, their
     * agreements past approval (as the Agreements page lists them) and those agreements' units.
     *
     * @return array<string, list<int>|Builder<Agreement>|Builder<AgreementUnit>>
     */
    public static function tenantScope(int $customerId): array
    {
        $agreements = fn () => Agreement::query()->where('customer_id', $customerId)
            ->whereNotIn('status', [AgreementStatus::Draft, AgreementStatus::PendingApproval])->select('id');

        return [
            CustomFieldEntity::Customer->value => [$customerId],
            CustomFieldEntity::Agreement->value => $agreements(),
            CustomFieldEntity::Unit->value => AgreementUnit::query()->whereIn('agreement_id', $agreements())->select('unit_id'),
        ];
    }

    /**
     * Plan ruling 8: the owner and the buildings of their contracts (owner portal plan ruling 8).
     *
     * @return array<string, list<int>|Builder<OwnerContract>>
     */
    public static function ownerScope(int $ownerId): array
    {
        return [
            CustomFieldEntity::Owner->value => [$ownerId],
            CustomFieldEntity::Building->value => OwnerContract::query()->portalFor($ownerId)->select('building_id'),
        ];
    }

    /**
     * Plan ruling 8: a file a portal page links to is the current file of an active "Show in portal" file field on one
     * of $scope's records. Anything else is a 404, including a replaced file and a deleted document.
     *
     * @param  array<string, list<int>|Builder<Agreement>|Builder<AgreementUnit>|Builder<OwnerContract>>  $scope  entity => record ids
     */
    public static function portalDocument(string $documentId, array $scope): Document
    {
        $value = CustomFieldValue::query()->where('document_id', $documentId)
            ->whereHas('field', fn (Builder $f) => $f->where('type', CustomFieldType::File)->where('in_portal', true)->whereNull('archived_at'))
            ->where(function (Builder $q) use ($scope) {
                foreach ($scope as $entity => $ids) {
                    $q->orWhere(fn (Builder $w) => $w->where('entity', $entity)->whereIn('entity_id', $ids));
                }
            })->firstOrFail();

        return Document::query()->findOrFail($value->document_id);
    }
```

- [ ] **Step 4: The two download controllers.** In `app/Http/Controllers/Portal/DownloadController.php`, add `use App\CustomFields\CustomFields;` to the imports, then replace

```php
        $model = Document::query()->sharedWithTenant((int) $account->customer_id)->findOrFail($document);
        Audit::log('document.downloaded', $model, causer: $account);

        return Storage::disk($model->disk)->download($model->path, $model->original_name);
    }
```

with

```php
        $model = Document::query()->sharedWithTenant((int) $account->customer_id)->findOrFail($document);
        Audit::log('document.downloaded', $model, causer: $account);

        return Storage::disk($model->disk)->download($model->path, $model->original_name);
    }

    /** Spec 2026-10-21 custom fields §5: a "Show in portal" file on the tenant, their agreements or those agreements' units (plan ruling 8). */
    public function customFieldFile(string $document): Response
    {
        $account = $this->account();
        $model = CustomFields::portalDocument($document, CustomFields::tenantScope((int) $account->customer_id));
        Audit::log('document.downloaded', $model, causer: $account);

        return Storage::disk($model->disk)->download($model->path, $model->original_name);
    }
```

In `app/Http/Controllers/Portal/OwnerDownloadController.php`, add `use App\CustomFields\CustomFields;` to the imports, then replace

```php
        $model = Document::query()->sharedWithOwner((int) $account->owner_id)->findOrFail($document);
        Audit::log('document.downloaded', $model, causer: $account);

        return Storage::disk($model->disk)->download($model->path, $model->original_name);
    }
```

with

```php
        $model = Document::query()->sharedWithOwner((int) $account->owner_id)->findOrFail($document);
        Audit::log('document.downloaded', $model, causer: $account);

        return Storage::disk($model->disk)->download($model->path, $model->original_name);
    }

    /** Spec 2026-10-21 custom fields §5: a "Show in portal" file on the owner or a building of their contracts (plan ruling 8). */
    public function customFieldFile(string $document): Response
    {
        $account = $this->account();
        $model = CustomFields::portalDocument($document, CustomFields::ownerScope((int) $account->owner_id));
        Audit::log('document.downloaded', $model, causer: $account);

        return Storage::disk($model->disk)->download($model->path, $model->original_name);
    }
```

- [ ] **Step 5: Routes.** In `routes/portal.php`:
  - In the tenant group, after the `documents/{document}` route, add:

```php
            Route::get('custom-fields/{document}', [DownloadController::class, 'customFieldFile'])->whereNumber('document')->name('custom-fields.file');
```

  - In the owner group, after its `documents/{document}` route, add:

```php
            Route::get('custom-fields/{document}', [OwnerDownloadController::class, 'customFieldFile'])->whereNumber('document')->name('custom-fields.file');
```

- [ ] **Step 6: The four portal components.** Each adds `use App\CustomFields\CustomFields;` to its imports.
  - **`app/Livewire/Portal/Home.php`:** replace `            'name' => $customer->displayName(app()->getLocale()),` with

```php
            'name' => $customer->displayName(app()->getLocale()),
            'details' => CustomFields::display($customer, portal: true), // spec 2026-10-21 custom fields §5: tenant fields on Home
```

  - **`app/Livewire/Portal/Owner/Home.php`:** replace `            'name' => $owner->displayName(app()->getLocale()),` with

```php
            'name' => $owner->displayName(app()->getLocale()),
            'details' => CustomFields::display($owner, portal: true), // spec 2026-10-21 custom fields §5: owner fields on Home
```

  - **`app/Livewire/Portal/Agreements.php`:** add `use App\Models\Unit;` to its imports and replace `render()` with:

```php
    public function render(): View
    {
        $agreements = Agreement::query()->where('customer_id', $this->customerId())
            ->whereNotIn('status', [AgreementStatus::Draft, AgreementStatus::PendingApproval])
            ->with(['agreementUnits.unit' => fn ($q) => $q->withTrashed(), 'agreementUnits.unit.building' => fn ($q) => $q->withTrashed(), 'agreementUnits.charges'])
            ->orderByDesc('start_date')->orderByDesc('id')->get();

        // Spec 2026-10-21 custom fields §5: agreement and unit fields marked "Show in portal" (plan ruling 8).
        // ponytail: a few small queries per agreement and unit; batch them if a tenant ever has dozens.
        return view('livewire.portal.agreements', [
            'agreements' => $agreements,
            'details' => $agreements->mapWithKeys(fn (Agreement $a) => [$a->id => CustomFields::display($a, portal: true)])->all(),
            'unitDetails' => $agreements->flatMap(fn (Agreement $a) => $a->agreementUnits->pluck('unit'))->filter()->unique('id')
                ->mapWithKeys(fn (Unit $u) => [$u->id => CustomFields::display($u, portal: true)])->all(),
        ])->title(__('Agreements'));
    }
```

  - **`app/Livewire/Portal/Owner/Buildings.php`:** replace `render()` with:

```php
    public function render(): View
    {
        $buildings = OwnerPortal::buildings($this->ownerId());

        return view('livewire.portal.owner.buildings', [
            'buildings' => $buildings,
            // Spec 2026-10-21 custom fields §5: building fields marked "Show in portal" on the building cards (plan ruling 8).
            'details' => $buildings->mapWithKeys(fn (array $card) => [$card['building']->id => CustomFields::display($card['building'], portal: true)])->all(),
        ])->title(__('Buildings'));
    }
```

- [ ] **Step 7: The four portal views.**
  - `resources/views/livewire/portal/home.blade.php`: replace

```blade
    <flux:heading size="xl" level="1">{{ __('Welcome, :name', ['name' => $name]) }}</flux:heading>
```

  with

```blade
    <flux:heading size="xl" level="1">{{ __('Welcome, :name', ['name' => $name]) }}</flux:heading>
    <x-custom-fields.display :items="$details" file-route="portal.custom-fields.file" />
```

  - `resources/views/livewire/portal/owner/home.blade.php`: replace the same heading line with

```blade
    <flux:heading size="xl" level="1">{{ __('Welcome, :name', ['name' => $name]) }}</flux:heading>
    <x-custom-fields.display :items="$details" file-route="portal.owner.custom-fields.file" />
```

  - `resources/views/livewire/portal/agreements.blade.php`: replace

```blade
                <div class="text-sm text-zinc-600 dark:text-zinc-300">{{ $a->agreementUnits->map(fn ($au) => $au->unit->building->name.' '.$au->unit->code)->implode(', ') }}</div>
```

  with

```blade
                <div class="text-sm text-zinc-600 dark:text-zinc-300">{{ $a->agreementUnits->map(fn ($au) => $au->unit->building->name.' '.$au->unit->code)->implode(', ') }}</div>
                <x-custom-fields.display :items="$details[$a->id] ?? []" file-route="portal.custom-fields.file" />
                @foreach ($a->agreementUnits as $au)
                    @if (($unitDetails[$au->unit_id] ?? []) !== [])
                        <flux:text size="sm" class="font-medium">{{ __('Unit') }} {{ $au->unit->code }}</flux:text>
                        <x-custom-fields.display :items="$unitDetails[$au->unit_id]" file-route="portal.custom-fields.file" />
                    @endif
                @endforeach
```

  - `resources/views/livewire/portal/owner/buildings.blade.php`: replace

```blade
                    <flux:badge size="sm">{{ __(':occupied of :total units let', ['occupied' => $card['occupied'], 'total' => $card['total']]) }}</flux:badge>
                @endif
            </div>
```

  with

```blade
                    <flux:badge size="sm">{{ __(':occupied of :total units let', ['occupied' => $card['occupied'], 'total' => $card['total']]) }}</flux:badge>
                @endif
            </div>
            <x-custom-fields.display :items="$details[$card['building']->id] ?? []" file-route="portal.owner.custom-fields.file" />
```

- [ ] **Step 8: The portal's Arabic check covers the display component.** In `tests/Feature/Portal/ArabicTest.php`, in the first test's `$files` list, after `resource_path('views/layouts/portal.blade.php'),` add:

```php
        resource_path('views/components/custom-fields/display.blade.php'),
```

- [ ] **Step 9: Run the tests.**

Run: `php artisan test tests/Feature/CustomFields/PortalTest.php`, then `php artisan test tests/Feature/Portal`.
Expected: PASS, PASS. This includes `StorageTest`'s "every `portal.*` route takes the tenant guard" and `IsolationTest`'s reflection check: no public portal method gains an argument.

- [ ] **Step 10: Full suite and gates.** Run these serially:

```bash
php artisan test tests/Feature/CustomFields
php artisan test
vendor/bin/pint --dirty
php vendor/bin/phpstan clear-result-cache
php vendor/bin/phpstan analyse --memory-limit=2G
npm run build
```

Expected: every test passes, phpstan reports 0 errors, the build succeeds. `IntegrityCheckTest` still finds `EXPECTED_TRIGGERS` = 61.

- [ ] **Step 11: Commit.**

```bash
git add app/CustomFields/CustomFields.php app/Http/Controllers/Portal/DownloadController.php app/Http/Controllers/Portal/OwnerDownloadController.php routes/portal.php \
  app/Livewire/Portal/Home.php app/Livewire/Portal/Agreements.php app/Livewire/Portal/Owner/Home.php app/Livewire/Portal/Owner/Buildings.php \
  resources/views/livewire/portal/home.blade.php resources/views/livewire/portal/agreements.blade.php \
  resources/views/livewire/portal/owner/home.blade.php resources/views/livewire/portal/owner/buildings.blade.php \
  tests/Feature/Portal/ArabicTest.php tests/Feature/CustomFields/PortalTest.php
git commit -m "$(cat <<'EOF'
Portal custom fields: Show in portal fields read-only in the viewer's language, tenant fields on Home and agreement and unit fields on Agreements, owner fields on Home and building fields on the building cards, files through the portal's own ownership checks

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

## Self-review notes (done while writing)

- **Spec coverage:**
  - §1 purpose: define (Task 2), fill in (Tasks 3–5), filter (Task 6), report (Task 7), import (Task 8), portals (Task 9). Out of scope stays out: no formulas, no money-record fields, no conditional fields, no portal editing, no design pass.
  - §2 approach: one typed values table plus definitions and choices (Task 1). Record kinds are `CustomFieldEntity`, and "customer" is shown as Tenants (Task 1).
  - §3 defining fields:
    - the `settings.manage` page with one tab per kind (Task 2);
    - labels EN/AR with fallback (Task 1);
    - the seven types with their limits (Tasks 1 and 3);
    - choices EN/AR, at most 50 (Task 2);
    - required, show in portal, help text, order up/down (Task 2);
    - the fixed key (Task 1, ruling 9);
    - the 30 cap (Task 2, ruling 10);
    - after values exist: type locked, choices added or renamed, used choice hidden (Task 2, ruling 11);
    - archive / restore / delete (Task 2);
    - a new required field waits for the next edit (Task 3 test);
    - audits (Task 2).
  - §4 values:
    - the More details section on tenants (Task 3), units, buildings and owners (Task 4), saved by each record's action in its transaction (ruling 2);
    - agreements on the new form plus the panel at any status for agreement managers (Task 5);
    - who can edit is the record's update policy with building scope (Tasks 3–5);
    - validation per type, required, hidden-choice holding, tampered input (Task 3);
    - files: types, size, the document store, the Custom field category, replacement keeping the old file, downloads following the record (Task 3, ruling 4);
    - detail boxes (Tasks 3–5, ruling 12);
    - value audits (Task 3).
  - §5:
    - list filters with every listed condition, building scope, bad values ignored (Task 6);
    - report builder columns and filters per subject, the Building and Tenant sections, typed totals and groups, no files, keys, rename, archived note, packages and schedules through `forViewer` (Task 7);
    - import `cf_` columns on all five sheets, same rules, choices by EN or AR, required not enforced, no files, unknown columns refused (Task 8);
    - portals per page, in the viewer's language, empty hidden, files through portal checks (Task 9).
  - §6 tables: Task 1. No money tables; `EXPECTED_TRIGGERS` unchanged (Task 9 full suite).
  - §7 safety: actions only (`SaveCustomField`, the record saves, `SaveAgreementDetails`); active fields only and tampering (rulings 2 and 14); escaping (Task 3 test); scope (Tasks 3, 4, 6, 7 and 9); `__()` and "tenant" (ruling 15).
  - §8 testing: each bullet maps to a file:
    - `AdminTest`: field CRUD, type lock, used choices, archive/restore, 30 cap;
    - `ValuesTest`: validation, required, tampering;
    - `ValuesTest` / `RecordsTest` / `AgreementDetailsTest`: five kinds, the agreement panel, permissions and scope;
    - `ValuesTest` / `PortalTest`: files and download checks;
    - `ListFiltersTest`: list filters;
    - `ReportBuilderTest`: report builder;
    - `ImportTest`: import;
    - `PortalTest`: portal, EN/AR;
    - audits in `AdminTest`, `ValuesTest` and `AgreementDetailsTest`.
- **Spec ambiguities ruled on:**
  - Report keys use `cf_` / `cfb_` / `cft_`, not `cf.<key>`: a dot breaks the builder's `wire:model="def.totals.{key}"` (ruling 5).
  - Custom columns are correlated subqueries, not per-field joins, so there is no extra column limit beyond `MAX_COLUMNS` (ruling 5).
  - An archived field's saved filter is set aside rather than emptying the report. A deleted field's key is left out with the existing note (ruling 5).
  - Clearing a file is done by deleting its document in the Documents panel; the form only uploads and replaces (ruling 4).
  - On the staff box an empty **required** field shows "—", while empty optional fields and, in portals, all empty fields are hidden (ruling 12).
  - Unknown or archived keys sent from a form are ignored rather than refused, so a form opened before an archive still saves (rulings 2 and 14).
  - Rows exist only once a value was saved, and clearing keeps the row, so "no value ever saved" (delete allowed) is "no row" (rulings 1 and 10).
  - Unit fields in the tenant portal are those of the units on the tenant's agreements past approval. Building fields in the owner portal are those of the buildings of their portal contracts (ruling 8).
  - Agreement import rows must agree on their `cf_` cells, like the other agreement-level columns (ruling 7).
  - Choice EN labels must be distinct (ignoring case), because the import matches by label (ruling 11).
  - Moving a field and the field audits use explicit `custom_field.*` events, not `LogsActivity` (ruling 13).
- **Placeholder scan:** none. Every step has its code or its exact command. Task 4 Step 6 gives the Units form in full and names the exact substitutions (kind, record variable, id property, `save()` line) for the Buildings and Owners forms.
- **Type consistency:**
  - `customField(string $entity, string $type, string $label, array $attributes = [], array $choices = [])` and `customValue(CustomField, Model, array)` are the same in Tasks 1–9.
  - `CustomField::keyFor(CustomFieldEntity, string)`, `->label(?string)` and `CustomField::MAX_ACTIVE` are the same in Tasks 1, 2 and 7.
  - `CustomFieldType::column()` is the same in Tasks 1, 3, 6 and 7.
  - `CustomFields::validation(CustomFieldEntity, ?Model, array, bool = true)` returns `[rules, messages, names]` in Tasks 3, 4, 5 and 8.
  - `CustomFields::save(User, Model, array)` is the same in Tasks 3, 4, 5 and 8.
  - `display(Model, bool $portal = false)` returns `list<array{label, text, document}>` in Tasks 3, 4, 5, 8 and 9.
  - `formValues(CustomFieldEntity, ?Model)` and `documents(?Model)` are the same in Tasks 3–5.
  - `NUMBER_PATTERN` is used in Tasks 3 and 6.
  - `SaveAgreementDetails::handle(User, Agreement, array)` is the same in Tasks 5 and 9.
  - `CustomFieldFilter::apply(Builder, CustomFieldEntity, mixed): Builder` is the same in Task 6.
  - `CustomColumns::fields(CustomFieldEntity, string, string, string)` and `Field::$archived` are the same in Task 7.
  - `ImportKind::customFieldEntity()`, `CustomFieldImport::headerProblems()` and `::save()` are the same in Task 8.
  - `tenantScope(int)`, `ownerScope(int)` and `portalDocument(string, array)` are the same in Task 9.
  - Routes `admin.custom-fields`, `portal.custom-fields.file` and `portal.owner.custom-fields.file` are used as defined.
  - Component props `section(:fields, :values, :documents)`, `display(:items, file-route)` and `filter(:fields, :more)` are used as defined.
  - Livewire error keys stay `custom.<key>` in all six forms.
- **Fixture arithmetic:**
  - `ValuesTest`:
    - `123456789012.5` is stored as `123456789012.500` (12 integer digits, within DECIMAL 15,3) and shown as `123456789012.5`.
    - `has_pets` `'0'` is stored as 0 and shown as "No".
    - Field order is Employer 1, Notes 2, Income 3, Visa 4, Pets 5, Source 6, Work letter 7, so `display()` lists them in that order.
  - `AdminTest`:
    - "Plot" is at position 1, so the new field goes to 2.
    - The cap test: Used archived and Unused deleted leaves 0 active; plus 30 is 30. Create is refused and restore is refused; archiving "Field 1" leaves 29, so the restore succeeds and gives 30.
    - Moves: [A, B, C] → C up gives [A, C, B]; A up stays; B up gives [A, B, C].
  - `ListFiltersTest`:
    - From 999999999999.999 matches only u2 (u1 is .998).
    - To 12.500 matches only u3 (12.5).
    - Handover from 01/02/2026 matches u3 only (u1 is 31/01/2026).
    - "Is empty" handover is u2.
    - VAT: o1 yes, o2 no, o3 empty.
    - Title deed empty: only BBB.
  - `ReportBuilderTest`:
    - Leasing sees AAA only, so rows A1 (10.125) and A2 (2.500); the sum is 12.625 (B1's 1000 is out of scope).
    - By month: 05/03 and 28/03/2024 give "Mar 2024" ×2, and 01/07/2024 gives "Jul 2024" ×1.
    - Pets: T2 no (0), T1 yes (1), T3 none (NULL, last as "—"), so [No 1, Yes 1, — 1].
    - Agreements: one agreement (T1, in AAA), tenant pets 1.
  - `ImportTest`:
    - Header is line 1, so the first data row is line 2.
    - `1.2345` has four decimals; the row is refused, and unit 102 (`3`) passes alone.
    - `12.75` is shown as `12.75`.
    - Unit 102's required, blank area shows "—".
    - "زيارة" matches Walk-in; "agent" matches Agent case-insensitively.
    - "Newspaper" lists the visible choices "Walk-in, Agent".
  - `PortalTest`:
    - Sara's second save replaces `old-permit.pdf` with `permit.pdf`, so only `permit.pdf` is the value's document and `old-permit.pdf` gives 404.
    - Ali's managed Tower card comes first (`OwnerPortal::buildings` orders by contract id), so "Ali Owner Tower" precedes its fields.
