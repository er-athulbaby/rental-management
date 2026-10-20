<?php

namespace App\Livewire\Documents;

use App\Actions\Documents\DeleteDocument;
use App\Actions\Documents\ShareDocumentWithTenant;
use App\Actions\Documents\StoreDocument;
use App\Enums\DocumentCategory;
use App\Livewire\Concerns\WithActor;
use App\Models\Agreement;
use App\Models\AgreementUnit;
use App\Models\Building;
use App\Models\Cheque;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Expense;
use App\Models\MaintenanceRequest;
use App\Models\Owner;
use App\Models\OwnerContract;
use App\Models\Payment;
use App\Models\Unit;
use App\Models\WorkOrder;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Reusable attachments list for any allow-listed record (spec §9.1). */
class Panel extends Component
{
    use WithActor, WithFileUploads;

    /** Record types that may carry documents; later milestones add theirs. */
    public const array ALLOWED = [Agreement::class, AgreementUnit::class, Building::class, Cheque::class, Customer::class, Expense::class,
        MaintenanceRequest::class, Owner::class, OwnerContract::class, Payment::class, Unit::class, WorkOrder::class];

    #[Locked]
    public string $type = '';

    #[Locked]
    public int $recordId = 0;

    /** @var UploadedFile|null */
    public $upload = null;

    public string $category = 'other';

    public ?string $expiresOn = null;

    public function mount(Model $documentable): void
    {
        abort_unless(in_array($documentable::class, self::ALLOWED, true), 403);

        $this->type = $documentable::class;
        $this->recordId = (int) $documentable->getKey();
        // Spec 2026-10-17 maintenance §3.2-§3.3: before- and after-photos (plan ruling 10).
        $this->category = in_array($documentable::class, [MaintenanceRequest::class, WorkOrder::class], true) ? DocumentCategory::Maintenance->value : 'other';
    }

    private function documentable(): Model
    {
        abort_unless(in_array($this->type, self::ALLOWED, true), 403);

        /** @var class-string<Model> $class */
        $class = $this->type;

        return $class::query()->findOrFail($this->recordId);
    }

    public function save(StoreDocument $store): void
    {
        $this->validate([
            'upload' => ['required', 'file'],
            'category' => ['required', Rule::enum(DocumentCategory::class)],
            'expiresOn' => ['nullable', 'date_format:Y-m-d'],
        ]);

        abort_unless($this->upload instanceof UploadedFile, 422);

        try {
            $store->handle($this->actor(), $this->documentable(), $this->upload, DocumentCategory::from($this->category),
                $this->expiresOn ? CarbonImmutable::parse($this->expiresOn) : null);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => [$k === 'file' ? 'upload' : $k => $m])->all());
        }

        $this->reset('upload', 'expiresOn');
    }

    public function delete(int $documentId, DeleteDocument $delete): void
    {
        $document = Document::query()
            ->where('documentable_type', $this->documentable()->getMorphClass())
            ->where('documentable_id', $this->recordId)
            ->findOrFail($documentId);

        try {
            $delete->handle($this->actor(), $document);
        } catch (AuthorizationException) {
            abort(403);
        }
    }

    /** Spec 2026-10-18 tenant portal §4: flip "Share with tenant". The id is looked up inside this record (never a 500). */
    public function toggleShare(mixed $documentId, ShareDocumentWithTenant $share): void
    {
        $record = $this->documentable();
        $document = is_int($documentId) || (is_string($documentId) && ctype_digit($documentId))
            ? Document::query()->where('documentable_type', $record->getMorphClass())->where('documentable_id', $this->recordId)->find((int) $documentId)
            : null;
        if ($document === null) {
            $this->addError('share', __('Choose a document from the list.'));

            return;
        }

        try {
            $share->handle($this->actor(), $document, ! $document->shared_with_tenant);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $e) {
            $this->addError('share', (string) collect($e->errors())->flatten()->first());
        }
    }

    public function render(): View
    {
        $record = $this->documentable();
        abort_unless($this->actor()->can('view', $record), 403);

        return view('livewire.documents.panel', [
            'documents' => Document::query()
                ->where('documentable_type', $record->getMorphClass())
                ->where('documentable_id', $record->getKey())
                ->latest()
                ->get(),
            'shareable' => in_array($this->type, Document::SHAREABLE, true),
            'canUpload' => $this->actor()->can('update', $record),
            // The generated contract is written only by StoreApprovedContract (spec §5.6).
            'categories' => array_filter(DocumentCategory::cases(), fn ($c) => $c !== DocumentCategory::GeneratedPdf),
        ]);
    }
}
