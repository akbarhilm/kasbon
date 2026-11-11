<?php

namespace App\Observers;

use App\Models\User;
use App\Services\AuditLogger;

class UserObserver
{
    private const TRACKED = ['name', 'email', 'role', 'branch_id', 'aktif'];

    public function __construct(private AuditLogger $audit)
    {
    }

    /**
     * Password yang diubah oleh pemiliknya sendiri dianggap sudah diganti.
     * Password awal (seeder) atau yang di-reset oleh admin membuat user wajib menggantinya lagi.
     */
    public function saving(User $user): void
    {
        if ($user->isDirty('password')) {
            $user->password_changed_at = auth()->id() === $user->id ? now() : null;
        }
    }

    public function created(User $user): void
    {
        $this->audit->record('pengguna.dibuat', $user, $user->branch_id, null, null, $user->only(self::TRACKED));
    }

    public function updated(User $user): void
    {
        $changes = $user->getChanges();
        $fields = array_values(array_intersect(array_keys($changes), self::TRACKED));
        $passwordChanged = array_key_exists('password', $changes);

        if ($fields === [] && ! $passwordChanged) {
            return;
        }

        $before = [];
        $after = [];
        foreach ($fields as $field) {
            $before[$field] = $user->getOriginal($field);
            $after[$field] = $user->getAttribute($field);
        }

        // Nilai password tidak pernah disimpan di log.
        if ($passwordChanged) {
            $after['password'] = '[diubah]';
        }

        $this->audit->record('pengguna.diubah', $user, $user->branch_id, null, $before, $after);
    }
}
