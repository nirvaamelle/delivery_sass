{{--
    The close-out report.

    "The close-out report names who cleared each item and when" — the phase exit
    gate, and this page is where that sentence is met. An uncleared line reads
    "Not cleared" rather than showing an empty cell: a blank reads as no data,
    and this one is somebody's outstanding work.
--}}
<x-filament-panels::page>
    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="p-6 space-y-1">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Project {{ $project->code }} — {{ $project->name }}
            </p>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Opened {{ $checklist->opened_at->toDayDateTimeString() }}
            </p>

            @if ($complete)
                <p class="text-sm font-medium text-success-600 dark:text-success-400">
                    Every line cleared. The project may be closed.
                </p>
            @else
                <p class="text-sm font-medium text-warning-600 dark:text-warning-400">
                    {{ count($outstanding) }} {{ Str::plural('line', count($outstanding)) }} still to clear.
                </p>
            @endif
        </div>
    </div>

    @foreach ($panels as $panel => $lines)
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="px-6 pt-6">
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">
                    {{ Str::headline($panel) }}
                </h2>
            </div>

            <div class="p-6 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 dark:text-gray-400">
                            <th class="pb-2 pr-4 font-medium">Item</th>
                            <th class="pb-2 pr-4 font-medium">Cleared by</th>
                            <th class="pb-2 pr-4 font-medium">When</th>
                            <th class="pb-2 font-medium">Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($lines as $line)
                            <tr class="border-t border-gray-100 dark:border-white/10">
                                <td class="py-2 pr-4 text-gray-950 dark:text-white">{{ $line['item'] }}</td>
                                <td class="py-2 pr-4">
                                    @if ($line['cleared_by'] === null)
                                        <span class="text-warning-600 dark:text-warning-400">Not cleared</span>
                                    @else
                                        {{ $line['cleared_by'] }}
                                    @endif
                                </td>
                                <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">
                                    {{ $line['cleared_at'] ?? '—' }}
                                </td>
                                <td class="py-2 text-gray-500 dark:text-gray-400">
                                    {{ $line['note'] ?? '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach
</x-filament-panels::page>
