<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Akun yang dinonaktifkan super admin langsung dikeluarkan, termasuk dari sesi yang sedang berjalan.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->aktif) {
            Auth::guard()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $panel = Filament::getCurrentPanel() ?? Filament::getDefaultPanel();

            return redirect($panel?->getLoginUrl() ?? '/');
        }

        return $next($request);
    }
}
