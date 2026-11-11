@php
    $json = fn ($data) => $data === null || $data === []
        ? '-'
        : json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pre = 'margin:0;padding:.75rem;border:1px solid rgba(128,128,128,.35);border-radius:.5rem;font-size:.8125rem;white-space:pre-wrap;word-break:break-word';
@endphp

<div style="display:flex;flex-direction:column;gap:.75rem;font-size:.875rem">
    <div><strong>{{ \App\Models\AuditLog::label($log->aksi) }}</strong> &middot; {{ $log->created_at->format('d M Y H:i') }} &middot; {{ $log->user?->name ?? 'Sistem' }}</div>

    @if ($log->keterangan)
        <div><strong>Keterangan:</strong> {{ $log->keterangan }}</div>
    @endif

    <div>
        <div style="font-weight:600;margin-bottom:.25rem">Sebelum</div>
        <pre style="{{ $pre }}">{{ $json($log->data_sebelum) }}</pre>
    </div>

    <div>
        <div style="font-weight:600;margin-bottom:.25rem">Sesudah</div>
        <pre style="{{ $pre }}">{{ $json($log->data_sesudah) }}</pre>
    </div>
</div>
