<section class="w-full max-w-4xl space-y-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="space-y-1">
            <flux:heading size="xl" level="1">{{ $request->number }}</flux:heading>
            <div class="flex flex-wrap items-center gap-2">
                <x-status-badge :status="$request->status" />
                <flux:badge size="sm" :color="$request->priority->color()">{{ $request->priority->label() }}</flux:badge>
                <flux:text class="text-sm">{{ $request->category->label() }} · {{ $request->place() }}</flux:text>
            </div>
        </div>
        @if ($canManage && $open)
            <div class="flex flex-wrap gap-2">
                <flux:modal.trigger name="add-work-order"><flux:button variant="primary">{{ __('Add work order') }}</flux:button></flux:modal.trigger>
                @if ($canResolve)
                    <flux:button wire:click="resolve" wire:confirm="{{ __('Mark this request resolved?') }}">{{ __('Resolve') }}</flux:button>
                @endif
                <flux:modal.trigger name="cancel-request"><flux:button variant="ghost">{{ __('Cancel request') }}</flux:button></flux:modal.trigger>
            </div>
        @endif
    </div>
    <flux:error name="request" />

    <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
        <div><dt class="text-sm text-zinc-500">{{ __('Building / unit') }}</dt><dd>{{ $request->building->code }} / {{ $request->unit?->code ?? __('Common area') }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Tenant') }}</dt><dd>{{ $request->customer ? $request->customer->name_en.' · '.$request->customer->mobile : '—' }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Reported by') }}</dt><dd>{{ $request->reported_by_name }} · {{ $request->channel->label() }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Logged') }}</dt><dd>{{ $request->created_at->timezone('Asia/Bahrain')->format('d/m/Y H:i') }} · {{ $request->creator->name }}</dd></div>
        <div><dt class="text-sm text-zinc-500">{{ __('Entry permitted') }}</dt><dd>{{ $request->entry_permitted ? __('Yes') : __('No') }}</dd></div>
        @if ($request->resolved_at)
            <div><dt class="text-sm text-zinc-500">{{ __('Resolved') }}</dt><dd>{{ $request->resolved_at->timezone('Asia/Bahrain')->format('d/m/Y') }}</dd></div>
        @endif
        @if ($request->cancel_reason)
            <div class="sm:col-span-2"><dt class="text-sm text-zinc-500">{{ __('Cancelled') }}</dt><dd>{{ $request->cancel_reason }}</dd></div>
        @endif
        <div class="sm:col-span-2"><dt class="text-sm text-zinc-500">{{ __('Problem') }}</dt><dd class="whitespace-pre-line">{{ $request->description }}</dd></div>
    </dl>

    <livewire:documents.panel :documentable="$request" :key="'docs-request-'.$request->id" />

    <div class="space-y-3">
        <flux:heading size="lg" level="2">{{ __('Work orders') }}</flux:heading>
        <flux:error name="workOrders" />
        @forelse ($request->workOrders as $wo)
            <div wire:key="wo-{{ $wo->id }}" class="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-medium">{{ $wo->number }}</span>
                        <x-status-badge :status="$wo->status" />
                        @if ($wo->isOverdue())
                            <flux:badge size="sm" color="red">{{ __('Overdue') }}</flux:badge>
                        @endif
                        <span class="text-sm text-zinc-500">{{ $wo->vendor->name }}</span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <flux:button size="sm" variant="ghost" icon="printer" :href="route('maintenance.work-orders.sheet', $wo)" target="_blank" :aria-label="__('Sheet :number', ['number' => $wo->number])">{{ __('Sheet') }}</flux:button>
                        <div x-data="{ text: @js($wo->sheetText()) }">
                            <flux:button size="sm" variant="ghost" icon="clipboard-document" x-on:click="navigator.clipboard.writeText(text); $flux.toast(@js(__('Copied. Paste it into WhatsApp.')))" :aria-label="__('Copy :number for WhatsApp', ['number' => $wo->number])">{{ __('Copy for WhatsApp') }}</flux:button>
                        </div>
                        @if ($canManage && $wo->status->isOpen())
                            @if ($wo->status->value === 'assigned')
                                <flux:button size="sm" wire:click="startWorkOrder({{ $wo->id }})" :aria-label="__('Start :number', ['number' => $wo->number])">{{ __('Start') }}</flux:button>
                            @endif
                            <flux:button size="sm" variant="primary" wire:click="openCompletion({{ $wo->id }})" :aria-label="__('Complete :number', ['number' => $wo->number])">{{ __('Complete') }}</flux:button>
                            <flux:button size="sm" variant="ghost" wire:click="openCancel({{ $wo->id }})" :aria-label="__('Cancel :number', ['number' => $wo->number])">{{ __('Cancel') }}</flux:button>
                        @endif
                    </div>
                </div>
                <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-4">
                    <div><dt class="text-zinc-500">{{ __('Scheduled') }}</dt><dd>{{ $wo->scheduled_on?->format('d/m/Y') ?? '—' }}</dd></div>
                    <div><dt class="text-zinc-500">{{ __('Estimate (BHD)') }}</dt><dd class="tabular-nums">{{ $wo->estimate ?? '—' }}</dd></div>
                    <div><dt class="text-zinc-500">{{ __('Actual (BHD)') }}</dt><dd class="tabular-nums">{{ $wo->actual_net !== null ? __(':net + :vat VAT', ['net' => $wo->actual_net, 'vat' => $wo->actual_tax]) : '—' }}</dd></div>
                    <div><dt class="text-zinc-500">{{ __('Completed') }}</dt><dd>{{ $wo->completed_on?->format('d/m/Y') ?? '—' }}</dd></div>
                    @if ($wo->instructions)
                        <div class="sm:col-span-4"><dt class="text-zinc-500">{{ __('Instructions') }}</dt><dd class="whitespace-pre-line">{{ $wo->instructions }}</dd></div>
                    @endif
                    @if ($wo->completion_note)
                        <div class="sm:col-span-4"><dt class="text-zinc-500">{{ __('Completion note') }}</dt><dd class="whitespace-pre-line">{{ $wo->completion_note }}</dd></div>
                    @endif
                    @if ($wo->cancel_reason)
                        <div class="sm:col-span-4"><dt class="text-zinc-500">{{ __('Cancelled') }}</dt><dd>{{ $wo->cancel_reason }}</dd></div>
                    @endif
                </dl>
                <livewire:documents.panel :documentable="$wo" :key="'docs-wo-'.$wo->id" />
            </div>
        @empty
            <flux:text>{{ __('No work orders yet.') }}</flux:text>
        @endforelse
    </div>

    @if ($canSeeExpenses && $expenses->isNotEmpty())
        <div class="space-y-2">
            <flux:heading size="lg" level="2">{{ __('Expenses') }}</flux:heading>
            <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($expenses as $expense)
                    <li wire:key="exp-{{ $expense->id }}" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                        <flux:link :href="route('expenses.show', $expense)" wire:navigate>{{ $expense->description }}</flux:link>
                        <span class="flex items-center gap-2 tabular-nums">{{ $expense->total }} <x-status-badge :status="$expense->status" /></span>
                    </li>
                @endforeach
            </ul>
            <flux:text class="text-sm font-medium">{{ __('Total: :amount BHD', ['amount' => $expenseTotal]) }}</flux:text>
            @if ($expenses->contains(fn ($e) => $e->status !== \App\Enums\ExpenseStatus::Recorded))
                <flux:text class="text-sm">{{ __('Reversed expenses are not counted in the total.') }}</flux:text>
            @endif
        </div>
    @endif

    <div class="space-y-3">
        <flux:heading size="lg" level="2">{{ __('Timeline') }}</flux:heading>
        <ol class="space-y-2">
            @foreach ($request->events as $event)
                <li wire:key="ev-{{ $event->id }}" class="text-sm">
                    <span class="text-zinc-500"><time datetime="{{ $event->created_at->toIso8601String() }}">{{ $event->created_at->timezone('Asia/Bahrain')->format('d/m/Y H:i') }}</time> · {{ $event->user->name }}</span>
                    <span class="whitespace-pre-line">{{ $event->describe() }}</span>
                </li>
            @endforeach
        </ol>
        @if ($canManage)
            <form wire:submit="addNote" class="space-y-2">
                <flux:textarea wire:model="note" :label="__('Note')" rows="2" />
                <flux:button type="submit" size="sm">{{ __('Add note') }}</flux:button>
            </form>
        @endif
    </div>

    @if ($canManage && $open)
        <flux:modal name="add-work-order" class="md:w-[32rem]">
            <form wire:submit="addWorkOrder" class="space-y-4">
                <flux:heading size="lg">{{ __('Add work order') }}</flux:heading>
                <flux:select wire:model="workOrder.vendor_id" :label="__('Vendor')">
                    <option value="">{{ __('Choose…') }}</option>
                    @foreach ($vendors as $v)<option value="{{ $v->id }}">{{ $v->name }}</option>@endforeach
                </flux:select>
                @if ($vendors->isEmpty())
                    <flux:text class="text-sm">{{ __('No active vendor does this work yet. Add one under Vendors.') }}</flux:text>
                @endif
                <x-date-input wire:model="workOrder.scheduled_on" :label="__('Scheduled for')" />
                <flux:textarea wire:model="workOrder.instructions" :label="__('Instructions for the vendor')" rows="3" />
                <flux:input wire:model="workOrder.estimate" inputmode="decimal" :label="__('Estimate (BHD)')" />
                <flux:button type="submit" variant="primary">{{ __('Add') }}</flux:button>
            </form>
        </flux:modal>

        <flux:modal name="cancel-request" class="md:w-96">
            <form wire:submit="cancelRequest" class="space-y-4">
                <flux:heading size="lg">{{ __('Cancel request') }}</flux:heading>
                <flux:text class="text-sm">{{ __('Its open work orders are cancelled too.') }}</flux:text>
                <flux:textarea wire:model="cancelReason" :label="__('Reason')" rows="2" />
                <flux:button type="submit" variant="danger">{{ __('Cancel request') }}</flux:button>
            </form>
        </flux:modal>
    @endif

    @if ($canManage)
        <flux:modal name="cancel-work-order" class="md:w-96">
            <form wire:submit="cancelWorkOrder" class="space-y-4">
                <flux:heading size="lg">{{ __('Cancel work order') }}</flux:heading>
                <flux:textarea wire:model="cancelling.reason" :label="__('Reason')" rows="2" />
                <flux:button type="submit" variant="danger">{{ __('Cancel work order') }}</flux:button>
            </form>
        </flux:modal>

        <flux:modal name="complete-work-order" class="md:w-[36rem]">
            <form wire:submit="complete" class="space-y-4">
                <flux:heading size="lg">{{ __('Complete work order') }}</flux:heading>
                <flux:textarea wire:model="completion.completion_note" :label="__('What was done')" rows="3" />
                <x-date-input wire:model="completion.completed_on" :label="__('Completed on')" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model.live.debounce.500ms="completion.actual_net" inputmode="decimal" :label="__('Actual cost, net (BHD)')" />
                    <flux:input wire:model.live.debounce.500ms="completion.actual_tax" inputmode="decimal" :label="__('VAT (BHD)')" />
                </div>
                <flux:text class="text-sm">{{ __('Leave the cost empty for work with no charge, such as warranty work or own staff.') }}</flux:text>

                @if ($hasCost)
                    <flux:fieldset>
                        <flux:legend>{{ __('Record expense') }}</flux:legend>
                        <div class="space-y-4">
                            @unless ($canRecordCost)
                                <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('Recording a cost needs expenses.manage. Complete it with no cost, or ask a property manager.')" />
                            @endunless
                            <flux:text class="text-sm">{{ __('For :place, dated the completion date.', ['place' => $request->place()]) }}</flux:text>
                            <flux:select wire:model="completion.category" :label="__('Category')">
                                @foreach ($expenseCategories as $c)<option value="{{ $c->value }}">{{ __(str($c->value)->headline()->toString()) }}</option>@endforeach
                            </flux:select>
                            <flux:input wire:model="completion.description" :label="__('Description')" />
                            <flux:radio.group wire:model.live="completion.charge_to" :label="__('Charge to')" variant="segmented">
                                <flux:radio value="company" :label="__('Company')" />
                                <flux:radio value="owner" :label="__('Owner')" />
                                @if ($canChargeTenant)
                                    <flux:radio value="tenant" :label="__('Tenant')" />
                                @endif
                            </flux:radio.group>
                            <flux:error name="completion.charge_to" />
                            @if (($completion['charge_to'] ?? '') === 'owner')
                                <flux:textarea wire:model="completion.owner_approval_note" :label="__('Owner approval note (needed above the contract limit)')" rows="2" />
                                <flux:field>
                                    <flux:label>{{ __('Owner approval document') }}</flux:label>
                                    <x-file-button wire:model="ownerApproval" />
                                    <flux:error name="ownerApproval" />
                                </flux:field>
                            @endif
                            @if (($completion['charge_to'] ?? '') === 'tenant')
                                <flux:text class="text-sm">{{ __('The unit\'s current tenant is invoiced for the net amount.') }}</flux:text>
                            @endif
                        </div>
                    </flux:fieldset>
                @endif

                <flux:button type="submit" variant="primary">{{ __('Complete') }}</flux:button>
            </form>
        </flux:modal>
    @endif
</section>
