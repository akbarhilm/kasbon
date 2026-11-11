<?php

namespace App\Enums;

enum LedgerPos: string
{
    case SaldoAwal = 'saldo_awal';
    case PenambahanModal = 'penambahan_modal';
    case Penarikan = 'penarikan'; // lama: diperlakukan sebagai penarikan saldo kas
    case PenarikanKas = 'penarikan_kas';
    case PenarikanAdministrasi = 'penarikan_administrasi';
    case Pinjaman = 'pinjaman';
    case Administrasi = 'administrasi';
    case PelunasanPokok = 'pelunasan_pokok';
    case Denda = 'denda';
    case Koreksi = 'koreksi';
    case KoreksiAdministrasi = 'koreksi_administrasi';

    public function label(): string
    {
        return match ($this) {
            self::SaldoAwal => 'Saldo awal',
            self::PenambahanModal => 'Penambahan modal',
            self::Penarikan => 'Penarikan saldo',
            self::PenarikanKas => 'Penarikan saldo kas',
            self::PenarikanAdministrasi => 'Penarikan administrasi',
            self::Pinjaman => 'Pinjaman',
            self::Administrasi => 'Administrasi',
            self::PelunasanPokok => 'Pelunasan pokok',
            self::Denda => 'Denda',
            self::Koreksi => 'Koreksi',
            self::KoreksiAdministrasi => 'Koreksi administrasi',
        };
    }
}
