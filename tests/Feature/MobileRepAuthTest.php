<?php

namespace Tests\Feature;

use App\Models\DsrProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MobileRepAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_rep_health_check()
    {
        $response = $this->getJson('/api/v1/mobile/rep/health');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'healthy',
                    'service' => 'KKP Mobile Rep Auth Controller (v1)',
                ],
            ]);
    }

    public function test_rep_can_login_with_phone()
    {
        $user = User::create([
            'name' => 'Sales Rep 1',
            'email' => 'rep1@kkp.com',
            'phone' => '0771234567',
            'password' => Hash::make('secret123'),
            'role' => 'DSR_REP',
            'status' => 'ACTIVE',
        ]);

        DsrProfile::create([
            'user_id' => $user->id,
            'rep_code' => 'REP-001',
            'dsr_status' => 'ACTIVE',
        ]);

        $response = $this->postJson('/api/v1/mobile/rep/login', [
            'phone' => '0771234567',
            'password' => 'secret123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Rep login successful',
            ])
            ->assertJsonStructure([
                'data' => [
                    'user' => [
                        'id',
                        'name',
                        'phone',
                        'role',
                        'dsr_profile',
                    ],
                    'token',
                ],
            ]);
    }

    public function test_rep_can_login_with_rep_code()
    {
        $user = User::create([
            'name' => 'Sales Rep 2',
            'email' => 'rep2@kkp.com',
            'phone' => '0777654321',
            'password' => Hash::make('secret123'),
            'role' => 'DSR_REP',
            'status' => 'ACTIVE',
        ]);

        DsrProfile::create([
            'user_id' => $user->id,
            'rep_code' => 'REP-002',
            'dsr_status' => 'ACTIVE',
        ]);

        $response = $this->postJson('/api/v1/mobile/rep/login', [
            'rep_code' => 'REP-002',
            'password' => 'secret123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'user' => [
                        'email' => 'rep2@kkp.com',
                    ],
                ],
            ]);
    }

    public function test_login_fails_with_invalid_password()
    {
        User::create([
            'name' => 'Sales Rep 3',
            'email' => 'rep3@kkp.com',
            'phone' => '0711112222',
            'password' => Hash::make('secret123'),
            'role' => 'DSR_REP',
            'status' => 'ACTIVE',
        ]);

        $response = $this->postJson('/api/v1/mobile/rep/login', [
            'phone' => '0711112222',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid credentials',
            ]);
    }

    public function test_non_dsr_rep_role_cannot_login()
    {
        User::create([
            'name' => 'Agent User',
            'email' => 'agent@kkp.com',
            'phone' => '0755555555',
            'password' => Hash::make('secret123'),
            'role' => 'AGENT',
            'status' => 'ACTIVE',
        ]);

        $response = $this->postJson('/api/v1/mobile/rep/login', [
            'phone' => '0755555555',
            'password' => 'secret123',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_inactive_rep_cannot_login()
    {
        User::create([
            'name' => 'Inactive Rep',
            'email' => 'inactive@kkp.com',
            'phone' => '0788888888',
            'password' => Hash::make('secret123'),
            'role' => 'DSR_REP',
            'status' => 'INACTIVE',
        ]);

        $response = $this->postJson('/api/v1/mobile/rep/login', [
            'phone' => '0788888888',
            'password' => 'secret123',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_authenticated_rep_can_get_profile_and_logout()
    {
        $user = User::create([
            'name' => 'Sales Rep Auth',
            'email' => 'authrep@kkp.com',
            'phone' => '0799999999',
            'password' => Hash::make('secret123'),
            'role' => 'DSR_REP',
            'status' => 'ACTIVE',
        ]);

        DsrProfile::create([
            'user_id' => $user->id,
            'rep_code' => 'REP-999',
            'dsr_status' => 'ACTIVE',
        ]);

        $token = $user->createToken('test_token')->plainTextToken;

        // Test GET /api/v1/mobile/rep/me
        $meResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/mobile/rep/me');

        $meResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'user' => [
                        'email' => 'authrep@kkp.com',
                    ],
                ],
            ]);

        // Test GET /api/v1/mobile/rep/check-token
        $checkTokenResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/mobile/rep/check-token');

        $checkTokenResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'valid' => true,
                    'user' => [
                        'email' => 'authrep@kkp.com',
                    ],
                ],
            ]);

        // Test POST /api/v1/mobile/rep/logout
        $logoutResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/mobile/rep/logout');

        $logoutResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Logged out successfully',
            ]);
    }
}
