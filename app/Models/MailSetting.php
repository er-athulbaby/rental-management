<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

/**
 * The one email (SMTP) settings row. The password is encrypted at rest and write-only: it is hidden, and no audit entry or
 * page ever carries it.
 *
 * @property int $id
 * @property bool $enabled
 * @property string|null $host
 * @property int $port
 * @property string $encryption
 * @property string|null $username
 * @property string|null $password
 * @property string|null $from_address
 * @property string|null $from_name
 */
class MailSetting extends Model
{
    public const array ENCRYPTIONS = ['none', 'tls', 'ssl'];

    /** The screen's fields: everything but the password, which has its own rule (blank keeps the saved one). */
    public const array EDITABLE = ['enabled', 'host', 'port', 'encryption', 'username', 'from_address', 'from_name'];

    protected $fillable = [...self::EDITABLE, 'password', 'updated_by'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'port' => 'integer', 'password' => 'encrypted'];
    }

    public static function current(): ?self
    {
        return self::query()->first();
    }

    /**
     * A mailer config for these values, with the password given (the form's, or the saved one). Laravel 13 smtp schemes:
     * `smtps` is TLS from the first byte (ssl); `smtp` upgrades with STARTTLS when offered, and tls makes that mandatory.
     *
     * @param  array<string, mixed>  $v  host, port, encryption, username
     * @return array<string, mixed>
     */
    public static function smtpConfig(array $v, ?string $password): array
    {
        return [
            'transport' => 'smtp',
            'scheme' => $v['encryption'] === 'ssl' ? 'smtps' : 'smtp',
            'host' => $v['host'],
            'port' => (int) $v['port'],
            'username' => filled($v['username'] ?? null) ? $v['username'] : null,
            'password' => filled($password) ? $password : null,
            'require_tls' => $v['encryption'] === 'tls',
            'auto_tls' => $v['encryption'] !== 'none',
        ];
    }

    /**
     * Lays the saved row over config (spec: none → `.env` untouched; off → log, so mail reads "not set up"; on → smtp).
     * One query; quietly nothing while the table is not there yet (fresh install, mid-migration).
     */
    public static function apply(): void
    {
        try {
            $row = self::current();
        } catch (QueryException) {
            return;
        }
        if ($row === null) {
            return;
        }

        config(['mail.default' => $row->enabled ? 'smtp' : 'log']);
        if ($row->enabled) {
            config(['mail.mailers.smtp' => array_merge((array) config('mail.mailers.smtp'), self::smtpConfig($row->attributesToArray(), $row->password))]);
        }
        if (filled($row->from_address)) {
            config(['mail.from.address' => $row->from_address, 'mail.from.name' => $row->from_name ?: config('mail.from.name')]);
        }

        // A long-running worker may hold a mailer built from the old settings.
        app('mail.manager')->purge('smtp');
        app('mail.manager')->purge('log');
    }
}
