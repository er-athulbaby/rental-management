<?php

namespace App\Actions\Maintenance;

use App\Actions\Documents\StoreDocument;
use App\Actions\NextDocumentNumber;
use App\Audit\Audit;
use App\Enums\AgreementStatus;
use App\Enums\MaintenanceChannel;
use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceStatus;
use App\Enums\NumberSequenceKey;
use App\Enums\Trade;
use App\Mail\MaintenanceUpdate;
use App\Models\Building;
use App\Models\Customer;
use App\Models\MaintenanceEvent;
use App\Models\MaintenanceRequest;
use App\Models\PortalAccount;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Spec 2026-10-17 maintenance §4 step 1: staff log a request (maintenance.manage, in their buildings). Spec 2026-10-18
 * tenant portal §4: forPortal() is the tenant's entry, scoped to their current agreements. Both end in create(), the one
 * write path (plan ruling 14).
 */
final class LogMaintenanceRequest
{
    /** Spec 2026-10-18 tenant portal §4: requests per portal account per day (Asia/Bahrain). */
    public const int PORTAL_DAILY_LIMIT = 10;

    public function __construct(private NextDocumentNumber $next, private StoreDocument $documents) {}

    /** @param  array<array-key, mixed>  $data */
    public function handle(User $actor, array $data): MaintenanceRequest
    {
        if (! $actor->can('create', MaintenanceRequest::class)) {
            throw new AuthorizationException;
        }

        $data = array_map(fn (mixed $v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v, $data);
        $buildingId = is_numeric($data['building_id'] ?? null) ? (int) $data['building_id'] : 0;

        $v = Validator::make($data, [
            'building_id' => ['bail', 'required', 'integer', Rule::in(Building::visibleTo($actor)->pluck('id')->all())],
            'unit_id' => ['bail', 'nullable', 'integer', Rule::exists('units', 'id')->where('building_id', $buildingId)->whereNull('deleted_at')],
            'customer_id' => ['bail', 'nullable', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'reported_by_name' => ['required', 'string', 'max:150'],
            'channel' => ['required', Rule::enum(MaintenanceChannel::class)->except([MaintenanceChannel::Portal])],
            'category' => ['required', Rule::enum(Trade::class)],
            'priority' => ['required', Rule::enum(MaintenancePriority::class)],
            'description' => ['required', 'string', 'max:2000'],
            'entry_permitted' => ['required', 'boolean'],
        ])->validate();

        if (isset($v['customer_id']) && ! Customer::visibleTo($actor)->whereKey($v['customer_id'])->exists()) {
            throw ValidationException::withMessages(['customer_id' => __('Choose a tenant from the list.')]);
        }

        return $this->create([
            ...Arr::only($v, ['building_id', 'unit_id', 'customer_id', 'reported_by_name', 'channel', 'category', 'priority', 'description']),
            'entry_permitted' => (bool) $v['entry_permitted'],
        ], $actor);
    }

    /**
     * Spec 2026-10-18 tenant portal §4: no staff permission or building scope; the place must be one of the tenant's own.
     * Customer = the tenant, reported by the tenant's name, priority normal, channel portal.
     *
     * @param  array<array-key, mixed>  $data  place ("unit:ID" | "building:ID"), category, description, entry_permitted
     */
    public function forPortal(PortalAccount $account, array $data, mixed $photos = []): MaintenanceRequest
    {
        if (! $account->isActive()) {
            throw new AuthorizationException;
        }
        $sentToday = MaintenanceRequest::query()->where('portal_account_id', $account->id)->where('created_at', '>=', now('Asia/Bahrain')->startOfDay())->count();
        if ($sentToday >= self::PORTAL_DAILY_LIMIT) {
            throw ValidationException::withMessages(['request' => __('You have sent :count requests today. Please call the office for anything else.', ['count' => self::PORTAL_DAILY_LIMIT])]);
        }

        $data = array_map(fn (mixed $v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v, $data);
        $places = self::portalPlaces($account->customer_id);
        $v = Validator::make([...$data, 'photos' => $photos], [
            'place' => ['bail', 'required', 'string', Rule::in(array_keys($places))],
            'category' => ['required', Rule::enum(Trade::class)],
            'description' => ['required', 'string', 'max:2000'],
            'entry_permitted' => ['required', 'boolean'],
            'photos' => ['nullable', 'array', 'max:5'],
            'photos.*' => ['file', 'mimes:'.StoreDocument::PHOTO_MIMES, 'max:'.StoreDocument::MAX_KB],
        ], [
            // In the tenant's language; a "*" stands for one segment, so "photos.*" is the list and "photos.*.*" one photo.
            'place.*' => __('Choose one of your units or a building common area.'),
            'category.*' => __('Choose a category.'),
            'description.*' => __('Describe the problem in up to 2000 characters.'),
            'entry_permitted.*' => __('Say whether the vendor may enter.'),
            'photos.*' => __('Send up to 5 photos.'),
            'photos.*.*' => __('Each photo must be a JPG, PNG or WEBP picture of up to 10 MB.'),
        ])->validate();

        [$kind, $id] = explode(':', (string) $v['place']);
        $unit = $kind === 'unit' ? Unit::query()->findOrFail((int) $id) : null;
        $tenant = $account->customer;

        return $this->create([
            'building_id' => $unit !== null ? $unit->building_id : (int) $id,
            'unit_id' => $unit?->id,
            'customer_id' => $tenant->id,
            'reported_by_name' => Str::limit($tenant->name_en, 150, ''),
            'channel' => MaintenanceChannel::Portal->value,
            'category' => $v['category'],
            'priority' => MaintenancePriority::Normal->value,
            'description' => $v['description'],
            'entry_permitted' => (bool) $v['entry_permitted'],
        ], $account, array_values($v['photos'] ?? []));
    }

    /**
     * Spec 2026-10-18 tenant portal §4: the tenant's units on a current agreement (MaintenanceRequest::currentTenantId's
     * rule), then each of those buildings' common area. "unit:ID" / "building:ID" => label.
     *
     * @return array<string, string>
     */
    public static function portalPlaces(int $customerId): array
    {
        $today = now('Asia/Bahrain')->toDateString();
        $units = Unit::query()->with('building:id,name')->whereHas('building')
            ->whereHas('agreementUnits', fn (Builder $au) => $au->where('start_date', '<=', $today)
                ->whereRaw('COALESCE(move_out_date, end_date) >= ?', [$today])
                ->whereHas('agreement', fn (Builder $a) => $a->where('customer_id', $customerId)->where('status', AgreementStatus::Active)))
            ->orderBy('building_id')->orderBy('code')->get(['id', 'code', 'building_id']);

        $places = [];
        foreach ($units as $unit) {
            $places['unit:'.$unit->id] = $unit->building->name.' '.$unit->code;
        }
        foreach ($units->unique('building_id') as $unit) {
            $places['building:'.$unit->building_id] = __('Building common area — :building', ['building' => $unit->building->name]);
        }

        return $places;
    }

    /**
     * The one write path: number, request, first timeline row, photos and the "received" email, in one transaction.
     * StoreDocument writes each file before its insert, so a rollback can leave an unreferenced file (plan ruling 14).
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<UploadedFile>  $photos
     */
    private function create(array $attributes, User|PortalAccount $by, array $photos = []): MaintenanceRequest
    {
        return DB::transaction(function () use ($attributes, $by, $photos) {
            $request = new MaintenanceRequest($attributes);
            $request->forceFill([
                'number' => ($this->next)(NumberSequenceKey::MaintenanceRequest), 'status' => MaintenanceStatus::Open,
                'created_by' => $by instanceof User ? $by->id : null, 'portal_account_id' => $by instanceof PortalAccount ? $by->id : null,
            ])->save();
            MaintenanceEvent::record($request, $by, to: MaintenanceStatus::Open->value);

            if ($by instanceof PortalAccount) {
                foreach ($photos as $photo) {
                    $this->documents->forPortal($by, $request, $photo);
                }
                Audit::log('portal.maintenance_requested', $request, properties: ['number' => $request->number, 'photos' => count($photos)], causer: $by);
            }

            MaintenanceUpdate::sendFor($request, MaintenanceUpdate::RECEIVED); // spec §4 step 1; after commit

            return $request;
        });
    }
}
