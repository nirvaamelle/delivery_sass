{{--
    What somebody has to act on. An empty list says so explicitly rather than
    hiding the panel: a panel that vanishes when empty teaches people that its
    absence means nothing was checked.
--}}
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Needs attention</x-slot>

        @forelse ($items as $item)
            <div class="flex items-center justify-between gap-4 py-2 border-b last:border-b-0 border-gray-200 dark:border-white/10">
                <div class="flex items-center gap-3">
                    <x-filament::badge :color="$item['severity']">
                        {{ $item['count'] }}
                    </x-filament::badge>

                    <span class="text-sm">{{ $item['label'] }}</span>
                </div>
            </div>
        @empty
            <p class="text-sm fi-color-gray">
                Nothing needs attention: no aged receivables, no unacknowledged escalations,
                and no variance blocking a period close.
            </p>
        @endforelse
    </x-filament::section>
</x-filament-widgets::widget>
