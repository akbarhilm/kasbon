<?php

namespace App\Services;

use App\Models\Branch;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Nomor anggota per cabang berformat XNNNN (X = huruf cabang, NNNN = urutan), dialokasikan dengan lock baris cabang. */
final class MemberNumberService
{

    public function preview(int $branchId): string
    {
        return DB::transaction(function () use ($branchId): string {
            $branch = Branch::query()->lockForUpdate()->findOrFail($branchId);

            if (blank($branch->kode_huruf)) {
                throw new RuntimeException('Kode huruf cabang belum diatur.');
            }

           $nextSeq = (int) $branch->member_seq + 1;

            return sprintf('%s%04d', $branch->kode_huruf, $nextSeq);
        });
    }
    public function next(int $branchId): string
    {
        return DB::transaction(function () use ($branchId): string {
            $branch = Branch::query()->lockForUpdate()->findOrFail($branchId);

            if (blank($branch->kode_huruf)) {
                throw new RuntimeException('Kode huruf cabang belum diatur.');
            }

            $branch->increment('member_seq');

            return sprintf('%s%04d', $branch->kode_huruf, $branch->member_seq);
        });
    }
}
