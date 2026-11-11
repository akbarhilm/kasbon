<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Loan;
use App\Models\User;

class LoanPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Loan $loan): bool
    {
        return $user->role === Role::SuperAdmin || $user->branch_id === $loan->branch_id;
    }

    // Pinjaman dibuat lewat LoanService; SA hanya melihat.
    public function create(User $user): bool
    {
        return $user->role === Role::Cabang;
    }

    // Pinjaman tidak pernah diedit setelah tersimpan.
    public function update(User $user, Loan $loan): bool
    {
        return false;
    }

    // Hanya super admin yang boleh menghapus (soft delete + entri koreksi, tahap berikutnya).
    public function delete(User $user, Loan $loan): bool
    {
        return $user->role === Role::SuperAdmin;
    }

    public function restore(User $user, Loan $loan): bool
    {
        return false;
    }

    public function forceDelete(User $user, Loan $loan): bool
    {
        return false;
    }
}
