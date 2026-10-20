<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Spec 2026-10-18 tenant portal §3: one emailed sign-in code, stored hashed (plan ruling 5). Written only by
 * App\Actions\Portal\SendLoginCode and VerifyLoginCode.
 *
 * @property int $id
 * @property int $portal_account_id
 * @property string $code_hash
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $used_at
 * @property int $attempts
 * @property CarbonImmutable $created_at
 */
class PortalLoginCode extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['portal_account_id', 'code_hash', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'used_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'attempts' => 'integer'];
    }

    /** HMAC-SHA256 with the app key, bound to the account; compared with hash_equals (spec §6). */
    public static function hash(PortalAccount $account, string $code): string
    {
        return hash_hmac('sha256', $account->id.'|'.$code, (string) config('app.key'));
    }
}
