<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Membutuhkan UserObserver terdaftar di AppServiceProvider (lihat README-M6). */
class PasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $email, Role $role = Role::Cabang): User
    {
        $user = (new User)->forceFill([
            'name' => $email, 'email' => $email, 'password' => 'rahasia-123', 'role' => $role,
        ]);
        $user->save();

        return $user->fresh();
    }

    public function test_new_user_must_change_initial_password(): void
    {
        $this->assertNull($this->makeUser('a@example.test')->password_changed_at);
    }

    public function test_changing_own_password_clears_the_requirement(): void
    {
        $user = $this->makeUser('a@example.test');

        $this->actingAs($user);
        $user->update(['password' => 'password-baru-123']);

        $this->assertNotNull($user->fresh()->password_changed_at);
    }

    public function test_password_reset_by_another_user_requires_change_again(): void
    {
        $user = $this->makeUser('a@example.test');
        $sa = $this->makeUser('sa@example.test', Role::SuperAdmin);

        $this->actingAs($user);
        $user->update(['password' => 'password-baru-123']);
        $this->assertNotNull($user->fresh()->password_changed_at);

        $this->actingAs($sa);
        $user->fresh()->update(['password' => 'reset-oleh-admin-1']);

        $this->assertNull($user->fresh()->password_changed_at);
    }

    public function test_other_changes_do_not_touch_the_flag(): void
    {
        $user = $this->makeUser('a@example.test');

        $this->actingAs($user);
        $user->update(['password' => 'password-baru-123']);
        $changedAt = $user->fresh()->password_changed_at;

        $user->fresh()->update(['name' => 'Nama baru']);

        $this->assertEquals($changedAt, $user->fresh()->password_changed_at);
    }
}
