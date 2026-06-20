<?php

use App\ReservationServiceStage;

it('returns the full status icon catalog', function (): void {
    $response = $this->getJson('/api/v1/reference/status-icons');

    $response->assertOk()
        ->assertJsonStructure([
            'status_icons' => [
                ['key', 'group', 'label', 'color', 'icon'],
            ],
        ]);

    $keys = collect($response->json('status_icons'))->pluck('key');

    expect($keys)->toContain('confirmed', 'seated', 'paid', 'finished', 'bussing_needed', 'no_show', 'notify', 'remove');
});

it('exposes the new finished and bussing_needed service stages', function (): void {
    expect(ReservationServiceStage::tryFrom('finished'))->toBe(ReservationServiceStage::Finished)
        ->and(ReservationServiceStage::tryFrom('bussing_needed'))->toBe(ReservationServiceStage::BussingNeeded);
});

it('keeps every service stage represented in the icon catalog', function (): void {
    $catalogKeys = collect(config('status_icons'))->pluck('key');

    foreach (ReservationServiceStage::cases() as $stage) {
        expect($catalogKeys)->toContain($stage->value);
    }
});
