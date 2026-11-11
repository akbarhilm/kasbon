<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SecurityHeaders;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SecurityMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_headers_are_added(): void
    {
        Route::middleware(SecurityHeaders::class)->get('/_cek-header', fn () => 'ok');

        $this->get('/_cek-header')
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_deactivated_user_is_logged_out_on_next_request(): void
    {
        $user = (new User)->forceFill([
            'name' => 'Admin', 'email' => 'nonaktif@example.test', 'password' => 'rahasia-123',
            'role' => Role::Cabang, 'aktif' => false,
        ]);
        $user->save();

        Route::middleware(['web', EnsureUserIsActive::class])->get('/_cek-aktif', fn () => 'ok');

        $this->actingAs($user)->get('/_cek-aktif')->assertRedirect();
        $this->assertGuest();
    }
}
