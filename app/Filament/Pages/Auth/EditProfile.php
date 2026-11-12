<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

/**
 * Halaman profil bawaan Filament tetap di halaman yang sama setelah menyimpan.
 * Untuk user yang wajib mengganti password awal, setelah password berhasil diganti
 * user diarahkan ke beranda (dashboard cabang atau konsolidasi SA).
 */
class EditProfile extends BaseEditProfile
{
    private bool $passwordJustChanged = false;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $wasForced = $record->getAttribute('password_changed_at') === null;

        $updated = parent::handleRecordUpdate($record, $data);

        $this->passwordJustChanged = $wasForced && filled($data['password'] ?? null);

        return $updated;
    }

    protected function getRedirectUrl(): ?string
    {
        if (! $this->passwordJustChanged) {
            return null;
        }

        return Filament::getCurrentPanel()?->getUrl() ?? url('/');
    }
}
