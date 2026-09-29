<?php

namespace App\Models;

use App\Enums\DocumentCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Document extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = ['category', 'disk', 'path', 'original_name', 'mime', 'size', 'expires_on', 'uploaded_by'];

    protected function casts(): array
    {
        return ['category' => DocumentCategory::class, 'expires_on' => 'date', 'size' => 'integer'];
    }

    /** Upload, download and delete are explicit entries; this only catches later edits (e.g. expiry). */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['category', 'expires_on'])->logOnlyDirty()->dontLogEmptyChanges();
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
