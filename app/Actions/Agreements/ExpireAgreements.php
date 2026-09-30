<?php

namespace App\Actions\Agreements;

use App\Enums\AgreementStatus;
use App\Models\Agreement;
use Illuminate\Support\Facades\DB;

/**
 * The 02:00 job (spec §12). M2 only has active → expired; ponytail: M3 adds renewed / closed / terminated,
 * which need renewals and move-outs, and the draft deposit settlements.
 */
final class ExpireAgreements
{
    public function __invoke(): int
    {
        $today = now('Asia/Bahrain')->toDateString();

        return DB::transaction(fn () => Agreement::query()
            ->where('status', AgreementStatus::Active)
            ->where('end_date', '<', $today)
            ->lockForUpdate()
            ->get()
            ->each(fn (Agreement $agreement) => $agreement->forceFill(['status' => AgreementStatus::Expired])->save())
            ->count(), attempts: 3);
    }
}
