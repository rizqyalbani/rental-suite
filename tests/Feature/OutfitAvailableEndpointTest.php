<?php

namespace Tests\Feature;

use App\Models\Rental;
use App\Models\Outfit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

class OutfitAvailableEndpointTest extends TestCase
{
    use RefreshDatabase, SeedsDefaultStores;

    public function test_outfits_available_returns_json_without_server_error(): void
    {
        $user = User::factory()->create();
        Outfit::create([
            'code' => 'AVAIL-001',
            'description' => 'Test',
            'price' => 100000,
            'stock' => 1,
        ]);

        $response = $this->actingAs($user)->getJson(route('outfits.available', [
            'start' => '2026-05-09',
            'end' => '2026-05-11',
        ]));

        $response->assertOk();
        $response->assertJsonStructure(['outfits']);
        $this->assertNotEmpty($response->json('outfits'));
    }

    public function test_outfits_available_accepts_dd_mm_yyyy_input_and_marks_overlaps_unavailable(): void
    {
        $user = User::factory()->create();
        $outfit = Outfit::create([
            'code' => 'BR 05',
            'description' => 'Test',
            'price' => 100000,
            'stock' => 1,
        ]);

        $rental = Rental::create([
            'customer_name' => 'Existing',
            'phone' => '081200000000',
            'notes' => 'X',
            'start_date' => '2026-06-07',
            'end_date' => '2026-06-09',
            'status' => 'active',
        ]);
        $rental->outfits()->attach($outfit->id);

        $response = $this->actingAs($user)->getJson(route('outfits.available', [
            'start' => '07/06/2026',
            'end' => '09/06/2026',
        ]));

        $response->assertOk();
        $row = collect($response->json('outfits'))->firstWhere('id', $outfit->id);
        $this->assertNotNull($row);
        $this->assertFalse((bool) ($row['available'] ?? true));
    }

    /**
     * A digit-shaped but impossible date (month 13, day 40, Feb 30...) used to
     * reach Carbon::parse() unvalidated and throw a 500 — the frontend showed
     * that as "Failed to load item availability. Please retry."
     */
    public function test_outfits_available_survives_an_impossible_calendar_date(): void
    {
        $user = User::factory()->create();
        Outfit::create(['code' => 'AVAIL-BAD', 'description' => 'Test', 'price' => 100000, 'stock' => 1]);

        foreach ([
            ['start' => '2026-13-40', 'end' => '2026-09-25'],
            ['start' => '2026-09-20', 'end' => '2026-02-30'],
            ['start' => '40/13/2026', 'end' => '2026-09-25'],
            ['start' => 'not-a-date', 'end' => '2026-09-25'],
        ] as $params) {
            $response = $this->actingAs($user)->getJson(route('outfits.available', $params));
            $response->assertOk();
            $response->assertJsonStructure(['outfits']);
        }
    }
}
