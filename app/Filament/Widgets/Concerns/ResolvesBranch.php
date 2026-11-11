<?php

namespace App\Filament\Widgets\Concerns;

use App\Enums\Role;

/**
 * Menentukan cabang yang datanya ditampilkan widget.
 * User cabang SELALU memakai cabangnya sendiri (properti Livewire publik tidak dipercaya);
 * super admin memakai cabang yang dikirim dari halaman dashboard.
 */
trait ResolvesBranch
{
    public ?int $branchId = null;

    protected function branch(): int
    {
        $user = auth()->user();

        if ($user->role === Role::Cabang) {
            return (int) $user->branch_id;
        }

        abort_unless($user->role === Role::SuperAdmin && $this->branchId, 403);

        return (int) $this->branchId;
    }
}
