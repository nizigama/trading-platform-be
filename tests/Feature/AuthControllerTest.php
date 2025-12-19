<?php

use App\Models\User;

it('can register a user', function () {
    $response = $this->postJson('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertStatus(200)
        ->assertJson(['message' => 'Registration successful']);

    $this->assertDatabaseHas('users', [
        'email' => 'test@example.com',
        'name' => 'Test User',
    ]);

    // Verify initial balance is set
    $user = User::where('email', 'test@example.com')->first();
    expect($user->balance)->toBe('1000000.000000000000000000');

    $this->assertAuthenticated();
});

it('requires valid data for registration', function () {
    $response = $this->postJson('/register', [
        'name' => '',
        'email' => 'invalid-email',
        'password' => 'short',
        'password_confirmation' => 'different',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'email', 'password']);
});

it('requires unique email for registration', function () {
    User::factory()->create(['email' => 'existing@example.com']);

    $response = $this->postJson('/register', [
        'name' => 'Test User',
        'email' => 'existing@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

it('can login a user', function () {
    $user = User::factory()->create([
        'email' => 'test@example.com',
        'password' => 'password123',
    ]);

    $response = $this->postJson('/login', [
        'email' => 'test@example.com',
        'password' => 'password123',
    ]);

    $response->assertStatus(200)
        ->assertJson(['message' => 'Login successful']);

    $this->assertAuthenticatedAs($user);
});

it('fails login with invalid credentials', function () {
    User::factory()->create([
        'email' => 'test@example.com',
        'password' => 'password123',
    ]);

    $response = $this->postJson('/login', [
        'email' => 'test@example.com',
        'password' => 'wrongpassword',
    ]);

    $response->assertStatus(401)
        ->assertJson(['message' => 'Invalid credentials']);

    $this->assertGuest();
});

it('requires valid data for login', function () {
    $response = $this->postJson('/login', [
        'email' => '',
        'password' => '',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['email', 'password']);
});

it('can logout an authenticated user', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'web')->postJson('/logout');

    $response->assertStatus(200)
        ->assertJson(['message' => 'Logout successful']);

    $this->assertGuest('web');
});

it('prevents unauthenticated user from logging out', function () {
    $response = $this->postJson('/logout');

    $response->assertStatus(401);
});
