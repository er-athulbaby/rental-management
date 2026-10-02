<?php

namespace App\Jobs;

use App\Audit\Audit;
use App\Enums\DocumentCategory;
use App\Models\Document;
use App\Models\OwnerStatement;
use App\Pdf\OwnerStatementPdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/** Spec §7.1: the finalised statement PDF is generated and stored once, never regenerated. */
class StoreOwnerStatement implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $statementId, public int $userId) {}

    public function handle(OwnerStatementPdf $pdf): void
    {
        DB::transaction(function () use ($pdf) {
            $statement = OwnerStatement::query()->lockForUpdate()->findOrFail($this->statementId);

            if (self::stored($statement)) {
                return;
            }

            $bytes = $pdf->render($statement);
            $path = sprintf('documents/%s/%s.pdf', now()->format('Y/m'), Str::uuid());
            Storage::disk('local')->put($path, $bytes);

            try {
                $document = new Document([
                    'category' => DocumentCategory::GeneratedPdf,
                    'disk' => 'local',
                    'path' => $path,
                    'original_name' => $statement->number.'.pdf',
                    'mime' => 'application/pdf',
                    'size' => strlen($bytes),
                    'uploaded_by' => $this->userId,
                ]);
                $document->documentable()->associate($statement);
                $document->save();

                Audit::log('document.generated', $document, properties: ['owner_statement' => $statement->number]);
            } catch (Throwable $e) {
                Storage::disk('local')->delete($path);
                throw $e;
            }
        });
    }

    public static function stored(OwnerStatement $statement): ?Document
    {
        return Document::withTrashed()
            ->where('documentable_type', $statement->getMorphClass())
            ->where('documentable_id', $statement->id)
            ->where('category', DocumentCategory::GeneratedPdf)
            ->orderBy('id')
            ->first();
    }
}
