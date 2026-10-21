<?php

namespace App\Pdf;

use App\Billing\OwnerStatementCalculator;
use App\Enums\DepositsHeldBy;
use App\Enums\OwnerStatementStatus;
use App\Jobs\StoreOwnerStatement;
use App\Models\CompanySetting;
use App\Models\OwnerStatement;
use App\Support\Fils;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** Spec §9.3: the owner statement, English. A statement not yet finalised carries a DRAFT watermark. */
final class OwnerStatementPdf
{
    public function __construct(private PdfRenderer $renderer) {}

    public function render(OwnerStatement $s): string
    {
        $s->loadMissing(['contract.owner', 'contract.building']);
        $settings = CompanySetting::current();
        $figures = OwnerStatementCalculator::stored($s);
        $balance = $figures['opening'];
        $rows = $figures['entries']->map(function (array $e) use (&$balance) {
            $balance += $e['amount'];

            return [...$e, 'balance' => $balance];
        });

        // Spec §7.9: deposits the company holds are shown for information only.
        $heldByCompany = $s->contract->deposits_held_by === DepositsHeldBy::Company
            ? Fils::fromDecimal((string) (DB::table('deposit_movements')->where('owner_contract_id', $s->owner_contract_id)->where('posted_at', '<=', $s->cutoff_at)->sum('amount') ?: '0'))
            : null;

        return $this->renderer->render('pdf.owner-statement', [
            'statement' => $s,
            'company' => $settings,
            'logo' => $settings->logo_path && Storage::disk('local')->exists($settings->logo_path) ? Storage::disk('local')->path($settings->logo_path) : null,
            'figures' => $figures,
            'rows' => $rows,
            'heldByCompany' => $heldByCompany,
        ], [
            'footer' => '<div style="text-align:center; font-size:8pt; color:#555">'.e($s->label()).' · {PAGENO} / {nbpg}</div>',
            'watermark' => $s->status === OwnerStatementStatus::Finalised ? null : 'DRAFT',
        ]);
    }

    /**
     * Spec §7.9: a finalised statement from its stored copy; otherwise rendered now (DRAFT unless finalised). Staff and
     * the owner portal both serve it this way (owner portal plan ruling 11).
     */
    public function response(OwnerStatement $s): Response
    {
        $stored = StoreOwnerStatement::stored($s);
        if ($stored !== null) {
            return Storage::disk($stored->disk)->response($stored->path, $stored->original_name, ['Content-Type' => 'application/pdf']);
        }

        return response($this->render($s), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.($s->number ?? 'statement-draft').'.pdf"']);
    }
}
