<x-filament-panels::page>
    @include('filament.reports.styles')

    {{ $this->content }}

    <x-filament::section compact>
        <div class="fin-scroll" wire:loading.class="opacity-50">
            @include('filament.reports.statement', ['report' => $this->report()])
        </div>
    </x-filament::section>
</x-filament-panels::page>
