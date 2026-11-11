<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Rekening koran {{ $statement->branch?->nama ?? 'Konsolidasi' }}</title>
    <style>
        @page { size: A4 landscape; margin: 12mm; }
        body { font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; color: #111; margin: 0; padding: 16px; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .meta { color: #444; font-size: 13px; margin-bottom: 16px; }
        .toolbar { margin-bottom: 16px; }
        button { font-size: 14px; padding: 8px 16px; }
        @media print { .toolbar { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
    <div class="toolbar"><button type="button" onclick="window.print()">Cetak</button></div>

    <h1>Laporan Rekening Koran</h1>
    <div class="meta">
        Cabang: {{ $statement->branch?->nama ?? 'Semua cabang (konsolidasi)' }}<br>
        Periode: {{ $statement->from->format('d M Y') }} - {{ $statement->to->format('d M Y') }}<br>
        Dicetak: {{ now()->format('d M Y H:i') }}
    </div>

    @include('reports.partials.statement-table', ['statement' => $statement])
</body>
</html>
