<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Unit\MlbbDraft\MlbbDraftDataTest;

class MlbbDraftTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    public function test_the_page_renders_with_rank_rules(): void
    {
        $this->get('/mlbb-draft')
            ->assertOk()
            ->assertSee('MLBB')
            ->assertSee('"key":"mythic","label":"Mythic","api":"mythic","bans":5', false)
            ->assertSee('"key":"epic","label":"Epic","api":"epic","bans":3', false);
    }

    public function test_data_endpoint_returns_heroes_and_stats_for_a_rank(): void
    {
        MlbbDraftDataTest::fakeApi();

        $this->getJson('/mlbb-draft/data?rank=legend&days=7')
            ->assertOk()
            ->assertJsonPath('rank', 'legend')
            ->assertJsonPath('days', 7)
            ->assertJsonCount(2, 'heroes')
            ->assertJsonPath('stats.30.wr', 0.55)
            ->assertJsonStructure(['updatedAt']);
    }

    public function test_it_rejects_unknown_ranks_and_windows(): void
    {
        $this->getJson('/mlbb-draft/data?rank=immortal')->assertStatus(422);
        $this->getJson('/mlbb-draft/data?days=2')->assertStatus(422);
    }

    public function test_it_reports_when_the_api_is_unavailable(): void
    {
        Http::fake(['*' => Http::response('down', 503)]);

        $this->getJson('/mlbb-draft/data?rank=mythic')->assertStatus(503)->assertJsonStructure(['message']);
    }

    public function test_matchups_endpoint(): void
    {
        MlbbDraftDataTest::fakeApi();

        $this->getJson('/mlbb-draft/matchups?rank=mythic&days=7&ids=30,abc,-1')
            ->assertOk()
            ->assertJsonPath('matchups.30.counteredBy.0', [114, 2.07]);
    }
}
