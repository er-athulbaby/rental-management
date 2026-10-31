<section class="w-full max-w-6xl space-y-8">
    <flux:heading size="xl" level="1">{{ __('Bank reconciliation') }}</flux:heading>

    <div class="max-w-sm">
        <flux:select wire:model.live="account" :label="__('Money account')">
            <flux:select.option value="">{{ __('Choose…') }}</flux:select.option>
            @foreach ($accounts as $a)
                <flux:select.option :value="(string) $a->id">{{ $a->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:error name="account" />
        <flux:error name="ledger_account_id" />
    </div>

    @if ($selected !== null)
        @if ($canManage)
            <form wire:submit="upload" class="space-y-4">
                <flux:heading size="lg">{{ __('Upload a statement') }}</flux:heading>
                <div class="grid gap-3 sm:grid-cols-4">
                    <flux:input wire:model="header_row" inputmode="numeric" :label="__('Header row (0 for none)')" />
                    <flux:select wire:model="date_column" :label="__('Date column')">
                        @foreach ($columns as $n => $letter)
                            <flux:select.option :value="(string) $n">{{ $letter }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="date_format" :label="__('Date format')">
                        @foreach ($formats as $format => $shown)
                            <flux:select.option :value="$format">{{ $shown }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="description_column" :label="__('Description column')">
                        @foreach ($columns as $n => $letter)
                            <flux:select.option :value="(string) $n">{{ $letter }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="reference_column" :label="__('Reference column')">
                        <flux:select.option value="">—</flux:select.option>
                        @foreach ($columns as $n => $letter)
                            <flux:select.option :value="(string) $n">{{ $letter }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model.live="amount_mode" :label="__('Amounts')">
                        <flux:select.option value="signed">{{ __('One signed column (− is money out)') }}</flux:select.option>
                        <flux:select.option value="split">{{ __('Debit and credit columns') }}</flux:select.option>
                    </flux:select>
                    @if ($amount_mode === 'split')
                        <flux:select wire:model="debit_column" :label="__('Debit column (money out)')">
                            <flux:select.option value="">—</flux:select.option>
                            @foreach ($columns as $n => $letter)
                                <flux:select.option :value="(string) $n">{{ $letter }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:select wire:model="credit_column" :label="__('Credit column (money in)')">
                            <flux:select.option value="">—</flux:select.option>
                            @foreach ($columns as $n => $letter)
                                <flux:select.option :value="(string) $n">{{ $letter }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @else
                        <flux:select wire:model="amount_column" :label="__('Amount column')">
                            <flux:select.option value="">—</flux:select.option>
                            @foreach ($columns as $n => $letter)
                                <flux:select.option :value="(string) $n">{{ $letter }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif
                    <flux:select wire:model="balance_column" :label="__('Balance column')">
                        <flux:select.option value="">—</flux:select.option>
                        @foreach ($columns as $n => $letter)
                            <flux:select.option :value="(string) $n">{{ $letter }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                <x-file-button wire:model="file" accept=".csv,.xlsx" :label="__('Choose statement file')" />
                @foreach (['header_row', 'date_column', 'date_format', 'description_column', 'reference_column', 'amount_mode', 'amount_column', 'debit_column', 'credit_column', 'balance_column', 'file'] as $field)
                    <flux:error name="{{ $field }}" />
                @endforeach
                <flux:button type="submit" variant="primary">{{ __('Upload') }}</flux:button>
            </form>
        @endif

        @if ($result !== null)
            <div class="space-y-1 text-sm">
                <p>{{ __('Imported :imported, skipped as duplicates :skipped, failed :failed.', ['imported' => $result['imported'], 'skipped' => $result['skipped'], 'failed' => count($result['failures'])]) }}</p>
                @foreach ($result['failures'] as $failure)
                    <p>{{ __('Row :row: :reason', ['row' => $failure['row'], 'reason' => $failure['reason']]) }}</p>
                @endforeach
            </div>
        @endif

        <div class="space-y-2">
            <flux:heading size="lg">{{ __('Uploads') }}</flux:heading>
            <flux:error name="upload" />
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('File') }}</flux:table.column>
                    <flux:table.column>{{ __('Uploaded') }}</flux:table.column>
                    <flux:table.column>{{ __('Uploaded by') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Imported') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Skipped') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Failed') }}</flux:table.column>
                    <flux:table.column />
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($uploads as $upload)
                        <flux:table.row :key="'up-'.$upload->id">
                            <flux:table.cell>{{ $upload->file_name }}</flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap">{{ $upload->created_at->timezone('Asia/Bahrain')->format('d/m/Y H:i') }}</flux:table.cell>
                            <flux:table.cell>{{ $upload->uploader->name }}</flux:table.cell>
                            <flux:table.cell class="text-end tabular-nums">{{ $upload->imported }}</flux:table.cell>
                            <flux:table.cell class="text-end tabular-nums">{{ $upload->skipped }}</flux:table.cell>
                            <flux:table.cell class="text-end tabular-nums">{{ $upload->failed }}</flux:table.cell>
                            <flux:table.cell class="text-end">
                                @if ($canManage && ! $upload->matched)
                                    <flux:button size="sm" variant="danger" wire:click="deleteUpload({{ $upload->id }})" wire:confirm="{{ __('Delete this upload and its lines?') }}">{{ __('Delete') }}</flux:button>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>

        <div class="space-y-2">
            <flux:heading size="lg">{{ __('Reconciliations') }}</flux:heading>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('End date') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Opening balance') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Closing balance') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($reconciliations as $r)
                        <flux:table.row :key="'rec-'.$r->id">
                            <flux:table.cell class="whitespace-nowrap">
                                <a href="{{ route('ledger.bank-reconciliation.show', $r->id) }}" wire:navigate class="underline">{{ $r->end_date->format('d/m/Y') }}</a>
                            </flux:table.cell>
                            <flux:table.cell class="text-end tabular-nums">{{ $r->opening_balance }}</flux:table.cell>
                            <flux:table.cell class="text-end tabular-nums">{{ $r->closing_balance }}</flux:table.cell>
                            <flux:table.cell>{{ $r->status === 'open' ? __('Open') : __('Finished') }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>

        @if ($canManage && ! $hasOpen)
            <form wire:submit="start" class="space-y-3">
                <flux:heading size="lg">{{ __('Start a reconciliation') }}</flux:heading>
                <div class="grid gap-3 sm:grid-cols-3">
                    <x-date-input wire:model="end_date" :label="__('Statement end date')" />
                    <flux:input wire:model="closing_balance" inputmode="decimal" :label="__('Statement closing balance')" />
                    @if ($first)
                        <flux:input wire:model="opening_balance" inputmode="decimal" :label="__('Statement opening balance')" />
                    @endif
                </div>
                @foreach (['end_date', 'closing_balance', 'opening_balance'] as $field)
                    <flux:error name="{{ $field }}" />
                @endforeach
                <flux:button type="submit" variant="primary">{{ __('Start') }}</flux:button>
            </form>
        @endif
    @endif
</section>
