<x-filament-panels::page>
    @include('filament.reports.styles')

    {{ $this->content }}

    <x-filament::section compact>
        <div wire:loading.class="opacity-50">
            @include('filament.reports.annual', ['annual' => $this->annual()])
        </div>
    </x-filament::section>
</x-filament-panels::page>
