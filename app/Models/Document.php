<?php

namespace App\Models;

use App\Enums\DocumentCategory;
use Carbon\CarbonImmutable;
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
