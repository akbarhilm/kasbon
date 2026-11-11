@php
    $showBranch = $statement->branch === null;
    $rp = fn ($n) => \App\Support\Rupiah::format($n);
    $cell = 'padding:6px 10px;border-bottom:1px solid rgba(128,128,128,.35);';
    $head = $cell . 'text-align:left;font-weight:600;white-space:nowrap;';
    $num = $cell . 'text-align:right;white-space:nowrap;';
    $labelSpan = $showBranch ? 4 : 3;
@endphp

<table style="width:100%;border-collapse:collapse;font-size:14px">
    <thead>
        <tr>
            <th style="{{ $head }}">Tanggal dan jam</th>
            @if ($showBranch)<th style="{{ $head }}">Cabang</th>@endif
            <th style="{{ $head }}">Pos</th>
            <th style="{{ $head }}">Keterangan</th>
            <th style="{{ $head }}text-align:right">Masuk</th>
            <th style="{{ $head }}text-align:right">Keluar</th>
            <th style="{{ $head }}text-align:right">Saldo</th>
        </tr>
    </thead>
    <tbody>
        <tr style="font-weight:600">
            <td style="{{ $cell }}" colspan="{{ $labelSpan }}">Saldo awal ({{ $statement->from->format('d M Y H:i') }})</td>
            <td style="{{ $num }}"></td>
            <td style="{{ $num }}"></td>
            <td style="{{ $num }}">{{ $rp($statement->opening) }}</td>
        </tr>

        @forelse ($statement->rows as $row)
            <tr>
                <td style="{{ $cell }}white-space:nowrap">{{ $row['waktu']->format('d M Y H:i') }}</td>
                @if ($showBranch)<td style="{{ $cell }}">{{ $row['cabang'] }}</td>@endif
                <td style="{{ $cell }}">{{ $row['pos'] }}</td>
                <td style="{{ $cell }}">{{ $row['keterangan'] }}</td>
                <td style="{{ $num }}">{{ $row['masuk'] ? $rp($row['masuk']) : '' }}</td>
                <td style="{{ $num }}">{{ $row['keluar'] ? $rp($row['keluar']) : '' }}</td>
                <td style="{{ $num }}">{{ $rp($row['saldo']) }}</td>
            </tr>
        @empty
            <tr>
                <td style="{{ $cell }}" colspan="{{ $labelSpan + 3 }}">Tidak ada mutasi pada periode ini.</td>
            </tr>
        @endforelse

        <tr style="font-weight:600">
            <td style="{{ $cell }}" colspan="{{ $labelSpan }}">Total mutasi</td>
            <td style="{{ $num }}">{{ $rp($statement->totalMasuk) }}</td>
            <td style="{{ $num }}">{{ $rp($statement->totalKeluar) }}</td>
            <td style="{{ $num }}"></td>
        </tr>
        <tr style="font-weight:700">
            <td style="{{ $cell }}" colspan="{{ $labelSpan }}">Saldo akhir ({{ $statement->to->format('d M Y H:i') }})</td>
            <td style="{{ $num }}"></td>
            <td style="{{ $num }}"></td>
            <td style="{{ $num }}">{{ $rp($statement->closing) }}</td>
        </tr>
    </tbody>
</table>

<h3 style="margin:1.5rem 0 .5rem;font-size:1rem;font-weight:600">Ringkasan per pos (cash flow)</h3>
<table style="width:100%;max-width:640px;border-collapse:collapse;font-size:14px">
    <thead>
        <tr>
            <th style="{{ $head }}">Pos</th>
            <th style="{{ $head }}text-align:right">Masuk</th>
            <th style="{{ $head }}text-align:right">Keluar</th>
            <th style="{{ $head }}text-align:right">Neto</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($statement->perPos as $p)
            <tr>
                <td style="{{ $cell }}">{{ $p['pos'] }}</td>
                <td style="{{ $num }}">{{ $rp($p['masuk']) }}</td>
                <td style="{{ $num }}">{{ $rp($p['keluar']) }}</td>
                <td style="{{ $num }}">{{ $rp($p['masuk'] - $p['keluar']) }}</td>
            </tr>
        @endforeach
        <tr style="font-weight:600">
            <td style="{{ $cell }}">Total</td>
            <td style="{{ $num }}">{{ $rp($statement->totalMasuk) }}</td>
            <td style="{{ $num }}">{{ $rp($statement->totalKeluar) }}</td>
            <td style="{{ $num }}">{{ $rp($statement->totalMasuk - $statement->totalKeluar) }}</td>
        </tr>
    </tbody>
</table>

@if ($statement->perBranch !== [])
    <h3 style="margin:1.5rem 0 .5rem;font-size:1rem;font-weight:600">Ringkasan per cabang</h3>
    <table style="width:100%;max-width:760px;border-collapse:collapse;font-size:14px">
        <thead>
            <tr>
                <th style="{{ $head }}">Cabang</th>
                <th style="{{ $head }}text-align:right">Saldo awal</th>
                <th style="{{ $head }}text-align:right">Masuk</th>
                <th style="{{ $head }}text-align:right">Keluar</th>
                <th style="{{ $head }}text-align:right">Saldo akhir</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($statement->perBranch as $b)
                <tr>
                    <td style="{{ $cell }}">{{ $b['cabang'] }}</td>
                    <td style="{{ $num }}">{{ $rp($b['awal']) }}</td>
                    <td style="{{ $num }}">{{ $rp($b['masuk']) }}</td>
                    <td style="{{ $num }}">{{ $rp($b['keluar']) }}</td>
                    <td style="{{ $num }}">{{ $rp($b['akhir']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
