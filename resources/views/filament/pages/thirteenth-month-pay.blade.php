{{--
    13th month pay for a year: one twelfth of the basic pay and paid leave on
    approved and released registers. The computation and the report, not the
    payout.
--}}
<x-filament-panels::page>
    <div class="flex flex-wrap items-end gap-4">
        <label class="text-sm">
            <span class="block mb-1">Year</span>
            <x-filament::input.wrapper>
                <x-filament::input type="number" wire:model.live.debounce.500ms="year" min="2000" max="2100" />
            </x-filament::input.wrapper>
        </label>

        <p class="fi-section-header-description text-sm">
            Basis: basic pay and paid leave on approved and released registers whose cutoff ends in {{ $year }}.
            Overtime, night differential and allowances are excluded. Tax-exempt up to {{ number_format((float) $ceiling, 2) }}.
            Placeholder rules &mdash; see DECISIONS-PENDING.md.
        </p>
    </div>

    @forelse ($companies as $company)
        <x-filament::section>
            <x-slot name="heading">{{ $company['organization']->name }}</x-slot>

            <div class="fi-ta-ctn overflow-x-auto">
                <table class="fi-ta-table w-full text-sm">
                    <thead>
                        <tr>
                            <th class="py-1 text-left">Employee</th>
                            <th class="py-1 text-right">Registers</th>
                            <th class="py-1 text-right">Basis</th>
                            <th class="py-1 text-right">13th month</th>
                            <th class="py-1 text-right">Tax-exempt</th>
                            <th class="py-1 text-right">Taxable</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($company['rows'] as $row)
                            <tr>
                                <td class="py-1">{{ $row['employee_number'] }} &mdash; {{ $row['name'] }}</td>
                                <td class="py-1 text-right">{{ $row['lines'] }}</td>
                                <td class="py-1 text-right">{{ number_format((float) $row['basis'], 2) }}</td>
                                <td class="py-1 text-right font-semibold">{{ number_format((float) $row['amount'], 2) }}</td>
                                <td class="py-1 text-right">{{ number_format((float) $row['tax_exempt'], 2) }}</td>
                                <td class="py-1 text-right">{{ number_format((float) $row['taxable'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @empty
        <x-filament::section>
            <p class="text-sm">No approved or released payroll register ends in {{ $year }}.</p>
        </x-filament::section>
    @endforelse
</x-filament-panels::page>
