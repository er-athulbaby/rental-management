<?php

namespace App\Jobs;

use App\Audit\Audit;
use App\Enums\DocumentCategory;
use App\Models\Document;
use App\Models\Payment;
use App\Pdf\ReceiptPdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/** Spec §7.1: the receipt is generated and stored on recording; never regenerated (same pattern as StoreApprovedContract). */
class StoreReceipt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $paymentId, public int $userId) {}

    public function handle(ReceiptPdf $pdf): void
    {
        DB::transaction(function () use ($pdf) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($this->paymentId);

            if (self::stored($payment)) {
                return;
            }

            $bytes = $pdf->render($payment);
            $path = sprintf('documents/%s/%s.pdf', now()->format('Y/m'), Str::uuid());
            Storage::disk('local')->put($path, $bytes);

            try {
                $document = new Document([
                    'category' => DocumentCategory::GeneratedPdf,
                    'disk' => 'local',
                    'path' => $path,
                    'original_name' => $payment->number.'.pdf',
                    'mime' => 'application/pdf',
                    'size' => strlen($bytes),
                    'uploaded_by' => $this->userId,
                ]);
                $document->documentable()->associate($payment);
                $document->save();

                Audit::log('document.generated', $document, properties: ['payment' => $payment->number]);
            } catch (Throwable $e) {
                Storage::disk('local')->delete($path);
                throw $e;
            }
        });
    }

    public static function stored(Payment $payment): ?Document
    {
        return Document::withTrashed()
            ->where('documentable_type', $payment->getMorphClass())
            ->where('documentable_id', $payment->id)
            ->where('category', DocumentCategory::GeneratedPdf)
            ->orderBy('id')
            ->first();
    }
}
