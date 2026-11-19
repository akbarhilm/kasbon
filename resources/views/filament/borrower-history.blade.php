@php
    $borrower = $borrower ?? $getRecord()->borrower;
    $isSuperAdmin = auth()->user()?->role === \App\Enums\Role::SuperAdmin;

    $query = \App\Models\Loan::query()->with('payments')->where('borrower_id', $borrower->id)->orderBy('dicairkan_pada');
    if ($isSuperAdmin) {
        $query->withTrashed();
    }
    $loans = $query->get();
    $count = $loans->filter(fn ($loan) => ! $loan->trashed())->count();

    $rp = fn ($n) => \App\Support\Rupiah::format($n);
    $cell = 'padding:6px 10px;border-bottom:1px solid rgba(128,128,128,.35);white-space:nowrap;';
    $head = $cell . 'text-align:left;font-weight:600;';
@endphp

<div style="font-size:.875rem">
    <p style="margin:0 0 .75rem">
        <strong>{{ $borrower->nama }}</strong>
        @if ($borrower->nomor_anggota) ({{ $borrower->nomor_anggota }}) @endif
        sudah meminjam <strong>{{ $count }} kali</strong> di cabang ini.
    </p>

    @if ($loans->isEmpty())
        <p style="margin:0;opacity:.7">Belum ada pinjaman.</p>
    @else
        <div style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse">
                <thead>
                    <tr>
                        <th style="{{ $head }}">Ke-</th>
                        <th style="{{ $head }}">Tanggal cair</th>
                        <th style="{{ $head }}">Jatuh tempo</th>
                        <th style="{{ $head }}text-align:right">Pokok</th>
                        <th style="{{ $head }}">Tenor</th>
                        <th style="{{ $head }}">Status</th>
                        <th style="{{ $head }}">Tanggal lunas</th>
                        <th style="{{ $head }}text-align:right">Denda</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($loans as $i => $loan)
                        <tr>
                            <td style="{{ $cell }}">{{ $i + 1 }}</td>
                            <td style="{{ $cell }}">{{ $loan->dicairkan_pada->format('d M Y H:i') }}</td>
                            <td style="{{ $cell }}">{{ $loan->jatuh_tempo->format('d M Y') }}</td>
                            <td style="{{ $cell }}text-align:right">{{ $rp($loan->pokok) }}</td>
                            <td style="{{ $cell }}">{{ $loan->tenor_bulan }} bulan</td>
                            <td style="{{ $cell }}">
                                @if ($loan->trashed()) Dihapus
                                @else {{ ucfirst($loan->status->value) }}
                                @endif
                            </td>
                            <td style="{{ $cell }}">{{ $loan->dilunasi_pada?->format('d M Y H:i') ?? '-' }}</td>
                            <td style="{{ $cell }}text-align:right">{{ $rp($loan->payments->sum('denda_final')) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
