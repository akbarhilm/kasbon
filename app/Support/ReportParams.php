<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Throwable;

/**
 * Parameter laporan dari query string, divalidasi di server.
 * User cabang selalu dikunci ke cabangnya sendiri; super admin boleh memilih cabang atau konsolidasi (null).
 */
final class ReportParams
{
    public function __construct(
        public readonly ?int $branchId,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
    ) {
    }

    public static function resolve(Request $request, User $user): self
    {
        $tz = config('pinjaman.timezone');
        $now = CarbonImmutable::now($tz);

        $parse = function (mixed $value, CarbonImmutable $default) use ($tz): CarbonImmutable {
            if (! is_string($value) || $value === '') {
                return $default;
            }

            try {
                return CarbonImmutable::createFromFormat('Y-m-d', $value, $tz)->startOfDay();
            } catch (Throwable) {
                return $default;
            }
        };

        $from = $parse($request->query('dari'), $now->startOfMonth());
        $to = $parse($request->query('sampai'), $now->startOfDay())->endOfDay();

        if ($to->lessThan($from)) {
            $to = $from->endOfDay();
        }

        if ($user->role === Role::Cabang) {
            $branchId = (int) $user->branch_id;
        } else {
            $requested = $request->integer('cabang');
            $branchId = $requested > 0 && Branch::query()->whereKey($requested)->exists() ? $requested : null;
        }

        return new self($branchId, $from, $to);
    }

    /** @return array<string, mixed> */
    public function query(): array
    {
        return array_filter([
            'dari' => $this->from->toDateString(),
            'sampai' => $this->to->toDateString(),
            'cabang' => $this->branchId,
        ], fn ($value) => $value !== null);
    }
}
