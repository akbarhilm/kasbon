<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * User dengan password awal atau hasil reset diarahkan ke halaman profil sampai password diganti.
 * Dipasang sebagai authMiddleware panel (bukan persistent), sehingga permintaan Livewire pada halaman profil tidak terblokir.
 *
 * Jika halaman profil belum diaktifkan di panel (->profile()), middleware ini tidak memaksa apa pun.
 */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->password_changed_at !== null) {
            return $next($request);
        }

        // Di dalam rute panel, panel aktif sudah ditetapkan; cadangan: panel bawaan.
        $panel = Filament::getCurrentPanel() ?? Filament::getDefaultPanel();
        $profileUrl = $panel?->getProfileUrl();

        if ($profileUrl === null) {
            return $next($request);
        }

        if ($request->routeIs('filament.*.auth.profile', 'filament.*.auth.logout') || $request->url() === $profileUrl) {
            return $next($request);
        }

        Notification::make()
            ->title('Ganti password awal Anda untuk melanjutkan.')
            ->warning()
            ->send();

        return redirect($profileUrl);
    }
}
