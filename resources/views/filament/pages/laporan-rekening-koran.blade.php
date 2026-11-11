<x-filament-panels::page>
    <form method="get" style="display:flex;flex-wrap:wrap;gap:1rem;align-items:flex-end">
        <label style="display:flex;flex-direction:column;gap:.25rem;font-size:.875rem">
            Dari
            <x-filament::input.wrapper>
                <x-filament::input type="date" name="dari" :value="$params->from->toDateString()" />
            </x-filament::input.wrapper>
        </label>

        <label style="display:flex;flex-direction:column;gap:.25rem;font-size:.875rem">
            Sampai
            <x-filament::input.wrapper>
                <x-filament::input type="date" name="sampai" :value="$params->to->toDateString()" />
            </x-filament::input.wrapper>
        </label>

        @if ($isSuperAdmin)
            <label style="display:flex;flex-direction:column;gap:.25rem;font-size:.875rem">
                Cabang
                <x-filament::input.wrapper>
                    <x-filament::input.select name="cabang">
                        <option value="">Semua cabang (konsolidasi)</option>
                        @foreach ($branches as $id => $nama)
                            <option value="{{ $id }}" @selected($params->branchId === $id)>{{ $nama }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
        @endif

        <x-filament::button type="submit">Tampilkan</x-filament::button>
        <x-filament::button tag="a" :href="$excelUrl" color="success">Export Excel</x-filament::button>
        <x-filament::button tag="a" :href="$printUrl" target="_blank" color="gray">Cetak</x-filament::button>
    </form>

    <div style="overflow-x:auto">
        @include('reports.partials.statement-table', ['statement' => $statement])
    </div>
</x-filament-panels::page>
