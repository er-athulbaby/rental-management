<section class="w-full max-w-4xl space-y-8">
    <x-ui.summary :title="$request->number" :status="$request->status" :back="route('maintenance.requests.index')">
        <x-slot:meta>
            <div class="flex flex-wrap items-center gap-2">
                <flux:badge size="sm" :color="$request->priority->color()">{{ $request->priority->label() }}</flux:badge>
                @if ($request->channel === \App\Enums\MaintenanceChannel::Portal)
                    <flux:badge size="sm" color="blue">{{ __('Portal') }}</flux:badge>
                @endif
                <flux:text class="text-sm">{{ $request->category->label() }} · {{ $request->place() }}</flux:text>
            </div>
        </x-slot:meta>
        <x-slot:actions>
            @if ($canManage && $open)
                <flux:modal.trigger name="add-work-order"><flux:button variant="primary">{{ __('Add work order') }}</flux:button></flux:modal.trigger>
                @if ($canResolve)
                    <flux:button wire:click="resolve" wire:confirm="{{ __('Mark this request resolved?') }}">{{ __('Resolve') }}</flux:button>
                @endif
                <flux:modal.trigger name="cancel-request"><flux:button variant="ghost">{{ __('Cancel request') }}</flux:button></flux:modal.trigger>
            @endif
        </x-slot:actions>
        <x-slot:facts>
            <x-ui.fact :label="__('Building / unit')">{{ $request->building->code }} / {{ $request->unit?->code ?? __('Common area') }}</x-ui.fact>
            <x-ui.fact :label="__('Tenant')">{{ $request->customer ? $request->customer->name_en.' · '.$request->customer->mobile : '—' }}</x-ui.fact>
            <x-ui.fact :label="__('Reported by')">{{ $request->reported_by_name }} · {{ $request->channel->label() }}</x-ui.fact>
            <x-ui.fact :label="__('Logged')">{{ $request->created_at->timezone('Asia/Bahrain')->format('d/m/Y H:i') }} · {{ $request->creatorName() }}</x-ui.fact>
            <x-ui.fact :label="__('Entry permitted')">{{ $request->entry_permitted ? __('Yes') : __('No') }}</x-ui.fact>
            @if ($request->resolved_at)
                <x-ui.fact :label="__('Resolved')">{{ $request->resolved_at->timezone('Asia/Bahrain')->format('d/m/Y') }}</x-ui.fact>
            @endif
            @if ($request->cancel_reason)
                <x-ui.fact :label="__('Cancelled')" class="sm:col-span-2 lg:col-span-3">{{ $request->cancel_reason }}</x-ui.fact>
            @endif
            <x-ui.fact :label="__('Problem')" class="sm:col-span-2 lg:col-span-3"><span class="whitespace-pre-line">{{ $request->description }}</span></x-ui.fact>
        </x-slot:facts>
    </x-ui.summary>
    <flux:error name="request" />

    <x-ui.section :title="__('Work orders')" :hint="__('Vendor jobs raised for this request.')">
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
                <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-4">
                    <div><dt class="text-zinc-500 dark:text-zinc-400">{{ __('Scheduled') }}</dt><dd>{{ $wo->scheduled_on?->format('d/m/Y') ?? '—' }}</dd></div>
                    <div><dt class="text-zinc-500 dark:text-zinc-400">{{ __('Estimate (BHD)') }}</dt><dd class="tabular-nums">{{ $wo->estimate ?? '—' }}</dd></div>
                    <div><dt class="text-zinc-500 dark:text-zinc-400">{{ __('Actual (BHD)') }}</dt><dd class="tabular-nums">{{ $wo->actual_net !== null ? __(':net + :vat VAT', ['net' => $wo->actual_net, 'vat' => $wo->actual_tax]) : '—' }}</dd></div>
                    <div><dt class="text-zinc-500 dark:text-zinc-400">{{ __('Completed') }}</dt><dd>{{ $wo->completed_on?->format('d/m/Y') ?? '—' }}</dd></div>
                    @if ($wo->instructions)
                        <div class="col-span-2 sm:col-span-4"><dt class="text-zinc-500 dark:text-zinc-400">{{ __('Instructions') }}</dt><dd class="whitespace-pre-line">{{ $wo->instructions }}</dd></div>
                    @endif
                    @if ($wo->completion_note)
                        <div class="col-span-2 sm:col-span-4"><dt class="text-zinc-500 dark:text-zinc-400">{{ __('Completion note') }}</dt><dd class="whitespace-pre-line">{{ $wo->completion_note }}</dd></div>
                    @endif
                    @if ($wo->cancel_reason)
                        <div class="col-span-2 sm:col-span-4"><dt class="text-zinc-500 dark:text-zinc-400">{{ __('Cancelled') }}</dt><dd>{{ $wo->cancel_reason }}</dd></div>
                    @endif
                </dl>
                <div class="ui-inline-section border-t border-zinc-100 pt-3 dark:border-zinc-800">
                    <livewire:documents.panel :documentable="$wo" :key="'docs-wo-'.$wo->id" />
                </div>
            </div>
        @empty
            <x-ui.empty :title="__('No work orders yet.')" />
        @endforelse
    </x-ui.section>

    @if ($canSeeExpenses && $expenses->isNotEmpty())
        <x-ui.section :title="__('Expenses')" :hint="__('What this request has cost so far.')">
            <ul class="-my-2 divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($expenses as $expense)
                    <li wire:key="exp-{{ $expense->id }}" class="flex flex-wrap items-center justify-between gap-2 py-3 text-sm">
                        <flux:link :href="route('expenses.show', $expense)" wire:navigate>{{ $expense->description }}</flux:link>
                        <span class="flex items-center gap-2 tabular-nums">{{ $expense->total }} <x-status-badge :status="$expense->status" /></span>
                    </li>
                @endforeach
            </ul>
            <flux:text class="border-t border-zinc-200 pt-3 text-sm font-semibold tabular-nums dark:border-zinc-700">{{ __('Total: :amount BHD', ['amount' => $expenseTotal]) }}</flux:text>
            @if ($expenses->contains(fn ($e) => $e->status !== \App\Enums\ExpenseStatus::Recorded))
                <flux:text class="text-sm">{{ __('Reversed expenses are not counted in the total.') }}</flux:text>
            @endif
        </x-ui.section>
    @endif

    <x-ui.section :title="__('Timeline')" :hint="__('Everything that has happened on this request.')">
        <ol class="space-y-3 border-s border-zinc-200 ps-4 dark:border-zinc-700">
            @foreach ($request->events as $event)
                <li wire:key="ev-{{ $event->id }}" class="relative text-sm before:absolute before:-start-[1.3rem] before:top-1.5 before:size-2 before:rounded-full before:bg-accent">
                    <span class="block text-xs text-zinc-500 dark:text-zinc-400"><time datetime="{{ $event->created_at->toIso8601String() }}">{{ $event->created_at->timezone('Asia/Bahrain')->format('d/m/Y H:i') }}</time> · {{ $event->byName() }}</span>
                    <span class="block whitespace-pre-line">{{ $event->describe() }}</span>
                </li>
            @endforeach
        </ol>
        @if ($canManage)
            <form wire:submit="addNote" class="space-y-3 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <flux:textarea wire:model="note" :label="__('Note')" rows="2" />
                <div class="flex justify-end"><flux:button type="submit" size="sm">{{ __('Add note') }}</flux:button></div>
            </form>
        @endif
    </x-ui.section>

    <livewire:documents.panel :documentable="$request" :key="'docs-request-'.$request->id" />

    @if ($canManage && $open)
        <flux:modal name="add-work-order" class="md:w-[32rem]">
            <form wire:submit="addWorkOrder" class="space-y-4">
                <div>
                    <flux:heading size="lg">{{ __('Add work order') }}</flux:heading>
                    <flux:text size="sm" class="mt-1">{{ __('Send a vendor to fix this.') }}</flux:text>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <flux:select wire:model="workOrder.vendor_id" :label="__('Vendor')">
                            <option value="">{{ __('Choose…') }}</option>
                            @foreach ($vendors as $v)<option value="{{ $v->id }}">{{ $v->name }}</option>@endforeach
                        </flux:select>
                        @if ($vendors->isEmpty())
                            <flux:text class="mt-2 text-sm">{{ __('No active vendor does this work yet. Add one under Vendors.') }}</flux:text>
                        @endif
                    </div>
                    <x-date-input wire:model="workOrder.scheduled_on" :label="__('Scheduled for')" />
                    <flux:input wire:model="workOrder.estimate" inputmode="decimal" :label="__('Estimate (BHD)')" />
                    <div class="sm:col-span-2">
                        <flux:textarea wire:model="workOrder.instructions" :label="__('Instructions for the vendor')" rows="3" />
                    </div>
                </div>
                <div class="flex flex-wrap justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">{{ __('Add') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="cancel-request" class="md:w-96">
            <form wire:submit="cancelRequest" class="space-y-4">
                <div>
                    <flux:heading size="lg">{{ __('Cancel request') }}</flux:heading>
                    <flux:text size="sm" class="mt-1">{{ __('Its open work orders are cancelled too.') }}</flux:text>
                </div>
                <flux:textarea wire:model="cancelReason" :label="__('Reason')" rows="2" />
                <div class="flex flex-wrap justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="danger">{{ __('Cancel request') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    @if ($canManage)
        <flux:modal name="cancel-work-order" class="md:w-96">
            <form wire:submit="cancelWorkOrder" class="space-y-4">
                <div>
                    <flux:heading size="lg">{{ __('Cancel work order') }}</flux:heading>
                    <flux:text size="sm" class="mt-1">{{ __('The vendor is no longer needed for this job.') }}</flux:text>
                </div>
                <flux:textarea wire:model="cancelling.reason" :label="__('Reason')" rows="2" />
                <div class="flex flex-wrap justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="danger">{{ __('Cancel work order') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="complete-work-order" class="md:w-[36rem]">
            <form wire:submit="complete" class="space-y-4">
                <div>
                    <flux:heading size="lg">{{ __('Complete work order') }}</flux:heading>
                    <flux:text size="sm" class="mt-1">{{ __('Record what was done and what it cost.') }}</flux:text>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <flux:textarea wire:model="completion.completion_note" :label="__('What was done')" rows="3" />
                    </div>
                    <div class="sm:col-span-2"><x-date-input wire:model="completion.completed_on" :label="__('Completed on')" /></div>
                    <flux:input wire:model.live.debounce.500ms="completion.actual_net" inputmode="decimal" :label="__('Actual cost, net (BHD)')" />
                    <flux:input wire:model.live.debounce.500ms="completion.actual_tax" inputmode="decimal" :label="__('VAT (BHD)')" />
                    <flux:text class="text-sm sm:col-span-2">{{ __('Leave the cost empty for work with no charge, such as warranty work or own staff.') }}</flux:text>
                </div>

                @if ($hasCost)
                    <flux:fieldset class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <flux:legend>{{ __('Record expense') }}</flux:legend>
                        <div class="grid gap-4 sm:grid-cols-2">
                            @unless ($canRecordCost)
                                <flux:callout class="sm:col-span-2" variant="warning" icon="exclamation-triangle" :heading="__('Recording a cost needs expenses.manage. Complete it with no cost, or ask a property manager.')" />
                            @endunless
                            <flux:text class="text-sm sm:col-span-2">{{ __('For :place, dated the completion date.', ['place' => $request->place()]) }}</flux:text>
                            <flux:select wire:model="completion.category" :label="__('Category')">
                                @foreach ($expenseCategories as $c)<option value="{{ $c->value }}">{{ __(str($c->value)->headline()->toString()) }}</option>@endforeach
                            </flux:select>
                            <flux:input wire:model="completion.description" :label="__('Description')" />
                            <div class="sm:col-span-2">
                                <flux:radio.group wire:model.live="completion.charge_to" :label="__('Charge to')" variant="segmented">
                                    <flux:radio value="company" :label="__('Company')" />
                                    <flux:radio value="owner" :label="__('Owner')" />
                                    @if ($canChargeTenant)
                                        <flux:radio value="tenant" :label="__('Tenant')" />
                                    @endif
                                </flux:radio.group>
                            </div>
                            <flux:error name="completion.charge_to" class="sm:col-span-2" />
                            @if (($completion['charge_to'] ?? '') === 'owner')
                                <div class="sm:col-span-2">
                                    <flux:textarea wire:model="completion.owner_approval_note" :label="__('Owner approval note (needed above the contract limit)')" rows="2" />
                                </div>
                                <flux:field class="sm:col-span-2">
                                    <flux:label>{{ __('Owner approval document') }}</flux:label>
                                    <x-file-button wire:model="ownerApproval" />
                                    <flux:error name="ownerApproval" />
                                </flux:field>
                            @endif
                            @if (($completion['charge_to'] ?? '') === 'tenant')
                                <flux:text class="text-sm sm:col-span-2">{{ __('The unit\'s tenant on the completion date is invoiced for the net amount.') }}</flux:text>
                            @endif
                        </div>
                    </flux:fieldset>
                @endif

                <div class="flex flex-wrap justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">{{ __('Complete') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</section>
