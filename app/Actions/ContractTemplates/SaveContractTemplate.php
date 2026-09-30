<?php

namespace App\Actions\ContractTemplates;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Models\ContractTemplate;
use App\Models\ContractTemplateClause;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidatorInstance;

/** Spec §5.6: Admin edits clauses; no raw HTML. Agreements snapshot clauses on submit, so edits never reach them. */
final class SaveContractTemplate
{
    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, ?ContractTemplate $template, array $data): ContractTemplate
    {
        if (! $actor->can(PermissionName::TemplatesManage)) {
            throw new AuthorizationException;
        }

        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:100', Rule::unique('contract_templates', 'name')->ignore($template?->id)],
            'active' => ['boolean'],
            'is_default' => ['boolean'],
            'clauses' => ['required', 'array', 'min:1'],
            'clauses.*.heading_en' => ['required', 'string', 'max:150'],
            'clauses.*.heading_ar' => ['required', 'string', 'max:150'],
            'clauses.*.body_en' => ['required', 'string'],
            'clauses.*.body_ar' => ['required', 'string'],
        ])->after(fn (ValidatorInstance $v) => $this->checkClauses($v, $data))->validate();

        return DB::transaction(function () use ($actor, $template, $validated) {
            $template ??= new ContractTemplate;
            $old = $template->exists ? $template->clauses->map(fn ($c) => self::text($c))->all() : [];

            if (($validated['is_default'] ?? false) === true) {
                ContractTemplate::query()->where('is_default', true)->whereKeyNot($template->id ?? 0)->get()
                    ->each(fn (ContractTemplate $other) => $other->update(['is_default' => false]));
            }

            $template->fill([
                'name' => $validated['name'],
                'active' => (bool) ($validated['active'] ?? true),
                'is_default' => (bool) ($validated['is_default'] ?? false),
            ])->save();

            $template->clauses()->delete();
            foreach (array_values($validated['clauses']) as $i => $clause) {
                $template->clauses()->create([
                    'position' => $i + 1,
                    'heading_en' => trim($clause['heading_en']),
                    'heading_ar' => trim($clause['heading_ar']),
                    'body_en' => trim($clause['body_en']),
                    'body_ar' => trim($clause['body_ar']),
                ]);
            }

            $template->load('clauses');
            Audit::log('contract_template.clauses_saved', $template,
                ['clauses' => $old],
                ['clauses' => $template->clauses->map(fn ($c) => self::text($c))->all()],
                causer: $actor,
            );

            return $template;
        });
    }

    /** @return array{heading_en: string, heading_ar: string, body_en: string, body_ar: string} */
    private static function text(ContractTemplateClause $c): array
    {
        return ['heading_en' => $c->heading_en, 'heading_ar' => $c->heading_ar, 'body_en' => $c->body_en, 'body_ar' => $c->body_ar];
    }

    /** @param  array<string, mixed>  $data */
    private function checkClauses(ValidatorInstance $validator, array $data): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        if ((bool) ($data['is_default'] ?? false) && ! (bool) ($data['active'] ?? true)) {
            $validator->errors()->add('is_default', __('The default template must be active.'));
        }

        foreach (array_values((array) $data['clauses']) as $i => $clause) {
            foreach (['body_en', 'body_ar'] as $body) {
                $text = (string) $clause[$body];
                preg_match_all('/\{(\w+)\}/', $text, $m);
                if ($unknown = array_diff($m[1], ContractTemplate::MERGE_FIELDS)) {
                    $validator->errors()->add("clauses.$i.$body", __('Unknown merge field: :f', ['f' => '{'.implode('}, {', $unknown).'}']));
                }
                if (str_contains($text, '{units_table}') && trim($text) !== '{units_table}') {
                    $validator->errors()->add("clauses.$i.$body", __('{units_table} must be the whole clause body.'));
                }
                foreach (ContractTemplate::paragraphs($text) as $paragraph) {
                    if (mb_strlen($paragraph) > ContractTemplate::MAX_PARAGRAPH) {
                        $validator->errors()->add("clauses.$i.$body", __('Each paragraph can have at most :n characters; split it with a blank line.', ['n' => ContractTemplate::MAX_PARAGRAPH]));
                    }
                }
            }

            // EN and AR sit side by side, one row per paragraph pair (spec §9.2).
            if (count(ContractTemplate::paragraphs((string) $clause['body_en'])) !== count(ContractTemplate::paragraphs((string) $clause['body_ar']))) {
                $validator->errors()->add("clauses.$i.body_ar", __('The English and Arabic texts must have the same number of paragraphs.'));
            }
        }
    }
}
