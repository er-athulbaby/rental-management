<?php

namespace App\Models;

use App\Enums\AgreementStatus;
use App\Enums\DocumentCategory;
use App\Enums\InvoiceStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property DocumentCategory $category
 * @property CarbonImmutable|null $expires_on
 * @property bool $shared_with_tenant
 * @property int|null $portal_account_id
 */
class Document extends Model
{
    use LogsActivity, SoftDeletes;

    /** Spec 2026-10-18 tenant portal §4: records whose documents staff may share with the tenant (plan ruling 13). */
    public const array SHAREABLE = [Customer::class, Agreement::class, Invoice::class];

    /**
     * Shared documents on the tenant, their agreements or their invoices; deleted ones are left out by SoftDeletes.
     *
     * @param  Builder<Document>  $query
     */
    #[Scope]
    protected function sharedWithTenant(Builder $query, int $customerId): void
    {
        $query->where('shared_with_tenant', true)->where(fn (Builder $q) => $q
            ->where(fn (Builder $c) => $c->where('documentable_type', (new Customer)->getMorphClass())->where('documentable_id', $customerId))
            ->orWhere(fn (Builder $a) => $a->where('documentable_type', (new Agreement)->getMorphClass())
                ->whereIn('documentable_id', Agreement::query()->select('id')->where('customer_id', $customerId)
                    ->whereNotIn('status', [AgreementStatus::Draft, AgreementStatus::PendingApproval])))
            ->orWhere(fn (Builder $i) => $i->where('documentable_type', (new Invoice)->getMorphClass())
                ->whereIn('documentable_id', Invoice::query()->select('id')->where('customer_id', $customerId)->where('status', InvoiceStatus::Issued))));
    }

    protected $fillable = ['category', 'disk', 'path', 'original_name', 'mime', 'size', 'expires_on', 'uploaded_by', 'portal_account_id'];

    protected function casts(): array
    {
        return ['category' => DocumentCategory::class, 'expires_on' => 'date', 'size' => 'integer', 'shared_with_tenant' => 'boolean'];
    }

    /** Upload, download and delete are explicit entries; this only catches later edits (e.g. expiry). */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['category', 'expires_on', 'shared_with_tenant'])->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return MorphTo<Model, $this> */
    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return BelongsTo<PortalAccount, $this> set for a photo a tenant sent with a portal request */
    public function portalAccount(): BelongsTo
    {
        return $this->belongsTo(PortalAccount::class);
    }

    /** The type a browser can show in place (PDF or picture), or null for Word and Excel files, which download. */
    public function previewType(): ?string
    {
        return match (strtolower(pathinfo($this->original_name, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => null,
        };
    }
}
