<?php

namespace App\Livewire\Documents;

use App\Actions\Documents\DeleteDocument;
use App\Actions\Documents\StoreDocument;
use App\Enums\DocumentCategory;
use App\Livewire\Concerns\WithActor;
use App\Models\Building;
use App\Models\Document;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Reusable attachments list for any allow-listed record (spec §9.1). */
class Panel extends Component
{
    use WithActor, WithFileUploads;

    /** Record types that may carry documents; later milestones add theirs. */
    public const array ALLOWED = [Building::class];

    #[Locked]
    public string $type = '';

    #[Locked]
    public int $recordId = 0;

    /** @var UploadedFile|null */
    public $upload = null;

    public string $category = 'other';

    public function mount(Model $documentable): void
    {
        abort_unless(in_array($documentable::class, self::ALLOWED, true), 403);

        $this->type = $documentable::class;
        $this->recordId = (int) $documentable->getKey();
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
        ]);

        abort_unless($this->upload instanceof UploadedFile, 422);

        try {
            $store->handle($this->actor(), $this->documentable(), $this->upload, DocumentCategory::from($this->category));
        } catch (AuthorizationException) {
            abort(403);
        }

        $this->reset('upload');
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
            'canUpload' => $this->actor()->can('update', $record),
            'categories' => DocumentCategory::cases(),
        ]);
    }
}
