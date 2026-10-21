<?php

namespace App\Actions\Portal;

use App\Audit\Audit;
use App\Mail\PortalInvitation;
use App\Models\Customer;
use App\Models\Owner;
use App\Models\PortalAccount;
use App\Models\User;
use App\Reminders\EmailChannel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Spec 2026-10-18 tenant portal §3 and 2026-10-21 owner portal §3: "Invite to portal" and "Re-send invite" on the
 * tenant and owner pages. Creates or re-enables the record's account, or links the record to the account of the other
 * kind that has its email, then queues the invite for that kind (owner portal plan ruling 5).
 */
final class InviteToPortal
{
    public function handle(User $actor, Customer|Owner $record): PortalAccount
    {
        if (! $actor->can('update', $record)) {
            throw new AuthorizationException;
        }
        if (! (new EmailChannel)->ready()) {
            throw ValidationException::withMessages(['portal' => __('Portal email is not set up.')]);
        }
        $email = Str::lower(trim((string) $record->email));
        if ($email === '' || $record->trashed()) {
            throw ValidationException::withMessages(['portal' => $record instanceof Customer
                ? __('Add the tenant\'s email before inviting them.')
                : __('Add the owner\'s email before inviting them.')]);
        }

        try {
            // Two invites for the two kinds of one new email can both insert; the loser's second try links to the winner's row.
            $account = retry(2, fn () => DB::transaction(fn () => $this->write($actor, $record, $email)), 0,
                self::retryable(...));
        } catch (QueryException $e) {
            if (! self::retryable($e)) {
                throw $e;
            }

            throw ValidationException::withMessages(['portal' => self::takenMessage($record)]);
        }

        try {
            Mail::to(new Address($email, $record->name_en))->queue(new PortalInvitation($account, $record instanceof Customer ? 'tenant' : 'owner'));
        } catch (Throwable $e) {
            report($e);

            throw ValidationException::withMessages(['portal' => __('The invite could not be sent. Please try again.')]);
        }

        return $account;
    }

    /** A losing race: a unique violation, or the deadlock (1213) / lock wait timeout (1205) two gap-locked inserts of one new email end in. */
    public static function retryable(Throwable $e): bool
    {
        return $e instanceof UniqueConstraintViolationException
            || ($e instanceof QueryException && in_array($e->errorInfo[1] ?? 0, [1213, 1205], true));
    }

    /** "Another tenant's / owner's portal account uses this email." */
    public static function takenMessage(Customer|Owner $record): string
    {
        return $record instanceof Customer
            ? __('Another tenant\'s portal account uses this email.')
            : __('Another owner\'s portal account uses this email.');
    }

    private function write(User $actor, Customer|Owner $record, string $email): PortalAccount
    {
        $column = PortalAccount::column($record);
        $account = PortalAccount::query()->where($column, $record->getKey())->lockForUpdate()->first();
        $byEmail = PortalAccount::query()->where('email', $email)->lockForUpdate()->first();
        $linked = false;

        if ($byEmail !== null && ! $byEmail->is($account)) {
            if ($account !== null || $byEmail->getAttribute($column) !== null) {
                throw ValidationException::withMessages(['portal' => self::takenMessage($record)]);
            }
            if ($byEmail->disabled_at !== null) {
                throw ValidationException::withMessages(['portal' => __('The portal account for this email is disabled. Enable it on :name\'s page first.', ['name' => $byEmail->personName('en')])]);
            }
            $account = $byEmail;
            $linked = true;
        }

        $account ??= new PortalAccount;
        $resent = $account->exists && ! $linked && $account->disabled_at === null;
        $account->forceFill([
            $column => $record->getKey(), 'email' => $email, 'disabled_at' => null, 'invited_at' => now(), 'invited_by' => $actor->id,
        ])->save();
        Audit::log('portal.invited', $account, properties: [
            'record' => $record instanceof Customer ? 'tenant' : 'owner', $column => $record->getKey(), 'email' => $email, 'resent' => $resent, 'linked' => $linked,
        ], causer: $actor);

        return $account;
    }
}
