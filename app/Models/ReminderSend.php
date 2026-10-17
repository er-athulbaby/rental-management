<?php

namespace App\Models;

use App\Enums\PermissionName;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One item of one reminder message (spec 2026-10-17 §8); `batch` groups the items of one message. Automatic rows are
 * unique per tenant, type, item, rule day and date through the generated `auto_key` (plan ruling 3); manual rows never
 * collide. invoice(), cheque() and agreement() all read item_id: use only the one item_type names.
 *
 * @property int $id
 * @property int $customer_id
 * @property string $type
 * @property string $item_type 'invoice' | 'cheque' | 'agreement'
 * @property int $item_id
 * @property int|null $rule_day
 * @property CarbonImmutable $sent_on
 * @property string $channel
 * @property string $status 'sending' | 'sent' | 'failed' | 'skipped'
 * @property string|null $reason a skip code below, or the error of a failed send
 * @property bool $manual
 * @property int|null $sent_by
 * @property string $batch
 */
class ReminderSend extends Model
{
    public const array STATUSES = ['sending', 'sent', 'failed', 'skipped'];

    /** Skip reasons, stored in English and shown through reasonText(). */
    public const string OPTED_OUT = 'Opted out';

    public const string NO_EMAIL = 'No email';

    public const string MAIL_NOT_SET_UP = 'Email is not set up';

    protected $fillable = ['customer_id', 'type', 'item_type', 'item_id', 'rule_day', 'sent_on', 'channel', 'status', 'reason', 'manual', 'sent_by', 'batch'];

    protected function casts(): array
    {
        return ['sent_on' => 'immutable_date', 'manual' => 'boolean', 'rule_day' => 'integer', 'item_id' => 'integer'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /** @return BelongsTo<Invoice, $this> only when item_type is 'invoice' */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'item_id');
    }

    /** @return BelongsTo<Cheque, $this> only when item_type is 'cheque' */
    public function cheque(): BelongsTo
    {
        return $this->belongsTo(Cheque::class, 'item_id');
    }

    /** @return BelongsTo<Agreement, $this> only when item_type is 'agreement' */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class, 'item_id');
    }

    /**
     * Rows whose record the user may open (plan ruling 14): invoices and cheques need finance.view, agreements need
     * agreements.view; building scope is each record's own visibleTo.
     *
     * @param  Builder<ReminderSend>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $finance = $user->can(PermissionName::FinanceView);
        $agreements = $user->can(PermissionName::AgreementsView);

        $query->where(function (Builder $q) use ($user, $finance, $agreements) {
            $q->whereRaw('1 = 0');
            if ($finance) {
                $q->orWhere(fn (Builder $i) => $i->where('item_type', 'invoice')->whereIn('item_id', Invoice::query()->visibleTo($user)->select('id')))
                    ->orWhere(fn (Builder $c) => $c->where('item_type', 'cheque')->whereIn('item_id', Cheque::query()->visibleTo($user)->select('id')));
            }
            if ($agreements) {
                $q->orWhere(fn (Builder $a) => $a->where('item_type', 'agreement')->whereIn('item_id', Agreement::query()->visibleTo($user)->select('id')));
            }
        });
    }

    /**
     * Rows whose record is in the building, by each record's own inBuilding.
     *
     * @param  Builder<ReminderSend>  $query
     */
    #[Scope]
    protected function inBuilding(Builder $query, int $buildingId): void
    {
        $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $i) => $i->where('item_type', 'invoice')->whereIn('item_id', Invoice::query()->inBuilding($buildingId)->select('id')))
            ->orWhere(fn (Builder $c) => $c->where('item_type', 'cheque')->whereIn('item_id', Cheque::query()->inBuilding($buildingId)->select('id')))
            ->orWhere(fn (Builder $a) => $a->where('item_type', 'agreement')->whereIn('item_id', Agreement::query()->inBuilding($buildingId)->select('id'))));
    }

    public function itemLabel(): string
    {
        return match ($this->item_type) {
            'invoice' => $this->invoice?->label() ?? '#'.$this->item_id,
            'cheque' => __('Cheque :number', ['number' => $this->cheque ? $this->cheque->cheque_no : (string) $this->item_id]),
            default => $this->agreement?->label() ?? '#'.$this->item_id,
        };
    }

    public function itemUrl(): string
    {
        return match ($this->item_type) {
            'invoice' => route('invoices.show', $this->item_id),
            'cheque' => route('cheques.show', $this->item_id),
            default => route('agreements.show', $this->item_id),
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'sending' => __('Sending'),
            'sent' => __('Sent'),
            'failed' => __('Failed'),
            default => __('Skipped'),
        };
    }

    /** A Flux badge colour. */
    public function statusColor(): string
    {
        return match ($this->status) {
            'sending' => 'amber',
            'sent' => 'green',
            'failed' => 'red',
            default => 'zinc',
        };
    }

    /** A stored reason in words: skip codes are translated; a failed send's error is shown as it is. */
    public static function reasonText(?string $reason): string
    {
        return match ($reason) {
            null => '',
            self::OPTED_OUT => __('The tenant has opted out of reminders.'),
            self::NO_EMAIL => __('The tenant has no email address.'),
            self::MAIL_NOT_SET_UP => __('Email is not set up.'),
            default => $reason,
        };
    }
}
