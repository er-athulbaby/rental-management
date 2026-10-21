# Custom fields — design

**Status:** sections 1–3 approved in conversation, 21 October 2026; section 4 presented and the user said "please
continue" before leaving for 3 hours, taken as approval. Rank 9 of the Buildium comparison.

## 1. Purpose

Let Admin add their own fields to tenants, units, agreements, owners and buildings (for example meter number, employer,
broker, plot number), fill them in on the record, filter and report on them, import them, and optionally show them in
the portals.

**Out of scope:** calculated / formula fields; fields on money records (invoices, payments, cheques, expenses);
conditional fields; tenants or owners editing values in the portal; the visual design pass.

## 2. Approach

One values table with typed columns (`custom_field_values`), one row per record per field, plus field definitions
(`custom_fields`) and choice lists (`custom_field_choices`). Typed columns keep filters, sorting, report totals and date
grouping real. Rejected: a JSON column on each of the five tables (filters and reports would parse JSON; dates and
numbers sort as text).

Record kinds: `customer` (shown as "Tenant"), `unit`, `agreement`, `owner`, `building`.

## 3. Defining fields (Administration → Custom fields)

- Page for users with `settings.manage`, one tab per record kind.
- A field has: label EN and AR (AR optional, falls back to EN); type — text (≤255), long text (≤2000), number (up to 3
  decimals), date (dd/mm/yyyy), yes/no, choice list, file; choices (choice list only: EN/AR label each, ≤50); required;
  show in portal (tenant portal for customer / unit / agreement fields, owner portal for owner / building fields); help
  text; order (up / down).
- A fixed internal key is made from the EN label on creation (e.g. `meter_number`), unique per kind, never changed.
  Saved reports and import columns use the key.
- At most 30 active fields per record kind.
- After values exist: label, help, order, required and portal can change; choices can be added or renamed; a used
  choice is hidden, not deleted; the type cannot change.
- **Archive** hides the field from forms, lists, import and portals, keeps values, and can be restored. A field with no
  value ever saved can be deleted.
- A new required field does not block existing records until they are next edited.
- Field create / change / archive / restore / delete are audited.

## 4. Values on records

- Tenants, units, owners, buildings: a "More details" section at the bottom of the existing create / edit form, saved
  with the same Save, by the record's save action, in the same transaction.
- Agreements: the section is in the new-agreement form; afterwards the agreement page has an editable **More details**
  panel at any status (agreements lock, custom fields do not), for anyone who can manage agreements.
- Who can edit: whoever can update the record (agreements: manage agreements). Building scope as today.
- Validation per type; required fields must be filled; a choice must be visible (a record already holding a hidden
  choice keeps it); tampered input gives a message, never a 500.
- File fields: jpg, png, webp, pdf, ≤10 MB, stored through the document store on the record with a new document
  category **Custom field**; replacing keeps the old file among the record's documents; download follows the record's
  permissions.
- Detail pages show a read-only **More details** box (empty optional fields and archived fields hidden).
- Value changes are audited with old and new values (file: the file name), on the record, causer the staff member.

## 5. Lists, report builder, import, portals

- **List filters:** Tenants, Units, Agreements, Owners and Buildings lists get "More filters": pick a field and a
  condition — text contains; number / date from–to; yes/no; choice is one of; any type "is empty". Combined with
  building scope; bad filter values are ignored.
- **Report builder:** each active field becomes a column and filter of its subject — Tenants (tenant fields), Units (unit
  fields; building fields in a Building section), Agreements (agreement fields; tenant fields in a Tenant section),
  Owners (owner fields). Typed: numbers sum / average, dates group by month, choices and yes/no group. Files are not
  columns. Saved reports refer to the key: a renamed field keeps working; an archived field's column is empty with a
  note. Report packages and schedules follow.
- **CSV import:** Buildings, Units, Owners, Tenants (customers) and Agreements sheets accept optional `cf_<key>`
  columns, validated by the same rules; choices by EN or AR label; required not enforced on import; files not
  importable; an unknown `cf_` column is an error.
- **Portals:** fields with **Show in portal** appear read-only, in the viewer's language — tenant portal: agreement and
  unit fields on Agreements, tenant fields on Home; owner portal: owner fields on Home, building fields on the building
  cards. Empty fields hidden. File fields download through the portal's own ownership checks.

## 6. Tables

- `custom_fields`: id, entity (`customer|unit|agreement|owner|building`), key (unique with entity), label_en, label_ar,
  type (`text|long_text|number|date|bool|choice|file`), help, required, in_portal, position, archived_at, created_by,
  timestamps.
- `custom_field_choices`: id, custom_field_id, label_en, label_ar, position, hidden_at, timestamps.
- `custom_field_values`: id, custom_field_id, entity, entity_id, value_text (text), value_number (decimal 15,3),
  value_date, value_bool, choice_id, document_id, updated_by, timestamps. Unique (custom_field_id, entity_id). A CHECK
  allows at most one value column set.
- No money tables touched; `EXPECTED_TRIGGERS` unchanged.

## 7. Safety

- Actions are the only write path and re-check permission.
- Values are accepted only for active fields of the record's kind; tampered field or record ids give a validation
  message or 404.
- Long text is escaped, never rendered as HTML.
- Building scope applies in lists, reports and downloads; portals show only `in_portal` fields of the signed-in
  person's own records.
- All strings through `__()`; "tenant", never "customer", in UI copy.

## 8. Testing

- Admin field CRUD; type locked once values exist; used choices hidden not deleted; archive / restore; 30-field cap.
- Validation per type; required; tampered input → messages, never 500.
- Values saved on all five kinds; agreement panel editable after approval; permissions and building scope.
- File fields through the document store; download checks.
- List filters per type and scope.
- Report builder columns, filters, groups, totals; rename keeps saved reports; archived field empty.
- Import `cf_` columns, per-row errors, unknown column error.
- Portal shows only `in_portal` fields, for the right person, EN / AR.
- Audits.
