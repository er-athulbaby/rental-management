<?php

namespace App\Actions\Digests;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\Digest;
use App\Reports\Queries;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/** Spec §12: the 07:00 digests, each counted for its recipient's own buildings (plan ruling 6: none when all counts are zero). */
final class SendDigests
{
    public function handle(string $kind): int
    {
        [$permission, $subject] = match ($kind) {
            'finance' => [PermissionName::ChequesManage, __('Cheques today')],
            'management' => [PermissionName::ApprovalsDecide, __('Approvals and expiring agreements')],
            'documents' => [PermissionName::CustomersManage, __('ID documents expiring in the next 30 days')],
            default => throw new InvalidArgumentException("Unknown digest: {$kind}"),
        };

        $sent = 0;
        $today = now('Asia/Bahrain')->toDateString();
        foreach (self::recipients($permission) as $user) {
            $items = $this->items($kind, $user);
            if (collect($items)->sum('count') === 0) {
                continue;
            }
            // §12: idempotent. Once per recipient per day; Cache::add is atomic, so a re-run or an overlap skips them.
            if (! Cache::add("digest:{$kind}:{$user->id}:{$today}", true, now('Asia/Bahrain')->endOfDay())) {
                continue;
            }
            $user->notify(new Digest($subject, $items));
            $sent++;
        }

        return $sent;
    }

    /**
     * Active holders of the permission, never Vendor Support (spec §12) and never the System user (inactive).
     *
     * @return Collection<int, User>
     */
    private static function recipients(PermissionName $permission): Collection
    {
        return User::query()->where('active', true)->where('is_system', false)->permission($permission->value)->get()
            ->reject(fn (User $user) => $user->hasRole(RoleName::VendorSupport))->values();
    }

    /** @return list<array{label: string, count: int, url: string}> */
    private function items(string $kind, User $user): array
    {
        $today = now('Asia/Bahrain')->toDateString();

        return match ($kind) {
            'finance' => [
                ['label' => __('Cheques to deposit today'), 'count' => Queries::chequesToDeposit($user, $today)->count(), 'url' => route('reports.cheques', ['kind' => 'today'])],
                ['label' => __('Cheques to deposit this week'), 'count' => Queries::chequesToDeposit($user, Queries::weekEnd(), Queries::weekStart())->count(), 'url' => route('reports.cheques', ['kind' => 'week'])],
                ['label' => __('Bounced cheques awaiting action'), 'count' => Queries::bouncedCheques($user)->count(), 'url' => route('reports.cheques', ['kind' => 'bounced'])],
            ],
            'management' => [
                ['label' => __('Pending approvals'), 'count' => Queries::approvalsToDecide($user)->count(), 'url' => route('approvals.index')],
                ...array_map(fn (int $days) => ['label' => __('Agreements expiring in :d days', ['d' => $days]), 'count' => Queries::expiringAgreements($user, $days)->count(),
                    'url' => route('reports.expiring', ['window' => (string) $days])], [30, 60, 90]),
            ],
            default => (function () use ($user) {
                $documents = Queries::expiringIdDocuments($user, 30);
                $customers = $documents->where('documentable_type', (new Customer)->getMorphClass())->count();

                return [
                    ['label' => __('Customer ID documents'), 'count' => $customers, 'url' => route('reports.id-documents')],
                    ['label' => __('Owner ID documents'), 'count' => $documents->count() - $customers, 'url' => route('reports.id-documents')],
                ];
            })(),
        };
    }
}
