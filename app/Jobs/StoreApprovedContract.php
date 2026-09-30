<?php

namespace App\Jobs;

use App\Audit\Audit;
use App\Enums\DocumentCategory;
use App\Models\Agreement;
use App\Models\Document;
use App\Pdf\ContractPdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Spec §5.6: the approved contract is generated once, stored privately, and never regenerated. */
class StoreApprovedContract implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $agreementId, public int $approverId) {}

    public function handle(ContractPdf $pdf): void
    {
        $agreement = Agreement::query()->findOrFail($this->agreementId);

        if (self::stored($agreement)) {
            return;
        }

        $bytes = $pdf->render($agreement);
        $path = sprintf('documents/%s/%s.pdf', now()->format('Y/m'), Str::uuid()); // random name (spec §13.3)
        Storage::disk('local')->put($path, $bytes);

        DB::transaction(function () use ($agreement, $path, $bytes) {
            $document = new Document([
                'category' => DocumentCategory::GeneratedPdf,
                'disk' => 'local',
                'path' => $path,
                'original_name' => $agreement->number.'.pdf',
                'mime' => 'application/pdf',
                'size' => strlen($bytes),
                'uploaded_by' => $this->approverId,
            ]);
            $document->documentable()->associate($agreement);
            $document->save();

            Audit::log('document.generated', $document, properties: ['agreement' => $agreement->number]);
        });
    }

    public static function stored(Agreement $agreement): ?Document
    {
        return Document::query()
            ->where('documentable_type', $agreement->getMorphClass())
            ->where('documentable_id', $agreement->id)
            ->where('category', DocumentCategory::GeneratedPdf)
            ->first();
    }
}
