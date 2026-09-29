<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function validRegistrationPayload(string $email = 'jane@example.com'): array
    {
        return [
            'name' => 'Jane Doe',
            'email' => $email,
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
        ];
    }

    public function test_user_can_register(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->validRegistrationPayload());

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Registration successful.')
            ->assertJsonPath('data.user.email', 'jane@example.com')
            ->assertJsonPath('data.user.name', 'Jane Doe')
            ->assertJsonMissingPath('data.user.password')
            ->assertJsonMissingPath('data.user.remember_token');

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertDatabaseHas('users', [
            'email' => 'jane@example.com',
            'name' => 'Jane Doe',
        ]);
    }

    public function test_registration_normalizes_email_and_hashes_password(): void
    {
        $this->postJson('/api/v1/auth/register', $this->validRegistrationPayload('Jane@Example.COM'))
            ->assertCreated();

        $user = User::query()->where('email', 'jane@example.com')->first();

        $this->assertNotNull($user);
        $this->assertSame('jane@example.com', $user->email);
        $this->assertNotSame('Password1', $user->password);
        $this->assertTrue(Hash::check('Password1', $user->password));
    }

    public function test_registration_rejects_invalid_payload(): void
    {
        $this->postJson('/api/v1/auth/register', [])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_registration_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'jane@example.com']);

        $this->postJson('/api/v1/auth/register', $this->validRegistrationPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_registration_rejects_password_confirmation_mismatch(): void
    {
        $payload = $this->validRegistrationPayload();
        $payload['password_confirmation'] = 'Different1';

        $this->postJson('/api/v1/auth/register', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_registration_rejects_weak_password(): void
    {
        $payload = $this->validRegistrationPayload();
        $payload['password'] = 'short';
        $payload['password_confirmation'] = 'short';

        $this->postJson('/api/v1/auth/register', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_registration_ignores_mass_assignment_of_protected_fields(): void
    {
        $payload = $this->validRegistrationPayload();
        $payload['id'] = 999;
        $payload['email_verified_at'] = now()->toIso8601String();
        $payload['remember_token'] = 'forged-token';

        $this->postJson('/api/v1/auth/register', $payload)
            ->assertCreated();

        $user = User::query()->where('email', 'jane@example.com')->first();

        $this->assertNotNull($user);
        $this->assertNotSame(999, $user->id);
        $this->assertNull($user->email_verified_at);
        $this->assertNotSame('forged-token', $user->remember_token);
    }

    public function test_user_can_login(): void
    {
        $user = User::factory()->create([
            'email' => 'jane@example.com',
            'password' => 'Password1',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'jane@example.com',
            'password' => 'Password1',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Login successful.')
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.email', 'jane@example.com')
            ->assertJsonMissingPath('data.user.password')
            ->assertJsonMissingPath('data.user.remember_token');

        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_login_fails_with_generic_message_for_unknown_email(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'missing@example.com',
            'password' => 'Password1',
        ]);

        $response->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Invalid credentials.')
            ->assertJsonMissingPath('errors')
            ->assertJsonMissingPath('data.token');
    }

    public function test_login_fails_with_generic_message_for_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'jane@example.com',
            'password' => 'Password1',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'jane@example.com',
            'password' => 'WrongPass1',
        ]);

        $response->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Invalid credentials.');
    }

    public function test_login_is_rate_limited(): void
    {
        $payload = [
            'email' => 'jane@example.com',
            'password' => 'WrongPass1',
        ];

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/auth/login', $payload)->assertUnauthorized();
        }

        $this->postJson('/api/v1/auth/login', $payload)
            ->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Too many attempts. Please try again later.');
    }

    public function test_authenticated_user_can_logout_and_token_is_revoked(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_unauthenticated_user_cannot_logout(): void
    {
        $this->postJson('/api/v1/auth/logout')
            ->assertUnauthorized();
    }

    public function test_authenticated_user_can_view_profile(): void
    {
        $user = User::factory()->create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.name', 'Jane Doe')
            ->assertJsonPath('data.email', 'jane@example.com')
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token')
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.tokens');
    }

    public function test_unauthenticated_user_cannot_view_profile(): void
    {
        $this->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated.');
    }
}
