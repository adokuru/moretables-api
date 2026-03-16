<?php

use App\Models\ExpoPushToken;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('registers an expo push token for an authenticated customer', function () {
    $customer = User::factory()->create();

    Sanctum::actingAs($customer);

    $response = $this->postJson('/api/v1/me/expo-push-tokens', [
        'expo_token' => 'ExponentPushToken[customer-device-token-001]',
        'device_id' => 'customer-device-001',
        'device_name' => 'iPhone 15 Pro',
        'platform' => 'ios',
        'app_version' => '1.0.0',
    ]);

    $response->assertCreated()
        ->assertJsonPath('expo_push_token.expo_token', 'ExponentPushToken[customer-device-token-001]')
        ->assertJsonPath('expo_push_token.platform', 'ios')
        ->assertJsonPath('expo_push_token.device_id', 'customer-device-001');

    $this->assertDatabaseHas('expo_push_tokens', [
        'user_id' => $customer->id,
        'expo_token' => 'ExponentPushToken[customer-device-token-001]',
        'platform' => 'ios',
    ]);
});

it('replaces a stale expo push token when the same device receives a new token', function () {
    $customer = User::factory()->create();
    $staleToken = ExpoPushToken::factory()->create([
        'user_id' => $customer->id,
        'expo_token' => 'ExponentPushToken[stale-device-token]',
        'device_id' => 'customer-device-rotate',
        'platform' => 'android',
    ]);

    Sanctum::actingAs($customer);

    $response = $this->postJson('/api/v1/me/expo-push-tokens', [
        'expo_token' => 'ExponentPushToken[fresh-device-token]',
        'device_id' => 'customer-device-rotate',
        'device_name' => 'Pixel 8',
        'platform' => 'android',
        'app_version' => '1.1.0',
    ]);

    $response->assertCreated()
        ->assertJsonPath('expo_push_token.expo_token', 'ExponentPushToken[fresh-device-token]')
        ->assertJsonPath('expo_push_token.device_id', 'customer-device-rotate');

    expect(ExpoPushToken::query()->where('user_id', $customer->id)->count())->toBe(1);
    expect(ExpoPushToken::query()->whereKey($staleToken->id)->exists())->toBeFalse();
});

it('updates an existing expo push token without creating duplicates', function () {
    $customer = User::factory()->create();
    $expoPushToken = ExpoPushToken::factory()->create([
        'user_id' => $customer->id,
        'expo_token' => 'ExponentPushToken[stable-device-token]',
        'device_id' => 'stable-device-id',
        'device_name' => 'Old Device Name',
        'platform' => 'ios',
        'app_version' => '1.0.0',
    ]);

    Sanctum::actingAs($customer);

    $response = $this->postJson('/api/v1/me/expo-push-tokens', [
        'expo_token' => 'ExponentPushToken[stable-device-token]',
        'device_id' => 'stable-device-id',
        'device_name' => 'Updated Device Name',
        'platform' => 'ios',
        'app_version' => '1.2.0',
    ]);

    $response->assertOk()
        ->assertJsonPath('message', 'Expo push token updated successfully.')
        ->assertJsonPath('expo_push_token.device_name', 'Updated Device Name')
        ->assertJsonPath('expo_push_token.app_version', '1.2.0');

    $expoPushToken->refresh();

    expect($expoPushToken->device_name)->toBe('Updated Device Name');
    expect($expoPushToken->app_version)->toBe('1.2.0');
    expect(ExpoPushToken::query()->where('user_id', $customer->id)->count())->toBe(1);
});

it('revokes only the authenticated customer expo push token', function () {
    $customer = User::factory()->create();
    $otherCustomer = User::factory()->create();

    $customerToken = ExpoPushToken::factory()->create([
        'user_id' => $customer->id,
        'expo_token' => 'ExponentPushToken[current-customer-token]',
    ]);
    $otherToken = ExpoPushToken::factory()->create([
        'user_id' => $otherCustomer->id,
        'expo_token' => 'ExponentPushToken[other-customer-token]',
    ]);

    Sanctum::actingAs($customer);

    $response = $this->deleteJson('/api/v1/me/expo-push-tokens', [
        'expo_token' => $customerToken->expo_token,
    ]);

    $response->assertOk()
        ->assertJsonPath('message', 'Expo push token revoked successfully.');

    expect(ExpoPushToken::query()->whereKey($customerToken->id)->exists())->toBeFalse();
    expect(ExpoPushToken::query()->whereKey($otherToken->id)->exists())->toBeTrue();
});
