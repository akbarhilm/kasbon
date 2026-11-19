@php
    $loan = $getRecord();
    $paths = $loan->foto ?? [];
@endphp

@if ($paths === [])
    <span style="font-size:.875rem;opacity:.7">Tidak ada foto.</span>
@else
    <div style="display:flex;flex-wrap:wrap;gap:12px">
        @foreach ($paths as $i => $path)
            @php $url = route('loans.foto', ['loan' => $loan->id, 'index' => $i]); @endphp
            <a href="{{ $url }}" target="_blank" rel="noopener">
                <img src="{{ $url }}" alt="Foto pinjaman {{ $i + 1 }}" loading="lazy"
                     style="height:140px;width:auto;border-radius:8px;border:1px solid rgba(128,128,128,.35)">
            </a>
        @endforeach
    </div>
@endif
