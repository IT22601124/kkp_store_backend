<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = User::updateOrCreate(
    ['phone' => '0771234567'],
    [
        'name' => 'Agent Test User',
        'email' => 'agent@kkp.com',
        'password' => Hash::make('password123'),
        'role' => 'AGENT',
        'status' => 'ACTIVE'
    ]
);

$token = $user->createToken('auth_token')->plainTextToken;
echo "TOKEN:" . $token;
