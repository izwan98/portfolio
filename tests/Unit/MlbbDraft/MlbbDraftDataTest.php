<?php

namespace Tests\Unit\MlbbDraft;

use App\Services\MlbbDraft\DraftData;
use App\Services\MlbbDraft\RoneArena;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class MlbbDraftDataTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    public static function fakeApi(float $winRate = 0.55): void
    {
        $lane = fn ($title) => ['data' => ['road_sort_title' => $title]];
        $role = fn ($title) => ['data' => ['sort_title' => $title]];

        Http::fake([
            '*/api/heroes/positions*' => Http::response(['code' => 0, 'data' => ['records' => [
                ['data' => ['hero_id' => 30, 'hero' => ['data' => ['name' => 'Yi Sun-shin', 'sortid' => [$role('assassin'), $role('Marksman'), ''], 'roadsort' => [$lane('Jungle'), '']]], 'relation' => ['strong' => ['target_hero_id' => [2, 0]], 'weak' => ['target_hero_id' => [11]], 'assist' => ['target_hero_id' => []]]]],
                ['data' => ['hero_id' => 2, 'hero' => ['data' => ['name' => 'Balmond', 'sortid' => [$role('fighter')], 'roadsort' => [$lane('Exp Lane'), $lane('Jungle')]]], 'relation' => []]],
            ]]]),
            '*/api/heroes/rank*' => Http::response(['code' => 0, 'data' => ['records' => [
                ['data' => ['main_heroid' => 30, 'main_hero_win_rate' => $winRate, 'main_hero_appearance_rate' => 0.011, 'main_hero_ban_rate' => 0.05]],
                ['data' => ['main_heroid' => 2, 'main_hero_win_rate' => 0.47, 'main_hero_appearance_rate' => 0.004, 'main_hero_ban_rate' => 0.01]],
            ]]]),
            '*/api/heroes/30/counters*' => Http::response(['code' => 0, 'data' => ['records' => [['data' => [
                'sub_hero' => [['heroid' => 114, 'increase_win_rate' => 0.020727]],
                'sub_hero_last' => [['heroid' => 59, 'increase_win_rate' => -0.020588]],
            ]]]]]),
            '*/api/heroes/30/compatibility*' => Http::response(['code' => 0, 'data' => ['records' => [['data' => [
                'sub_hero' => [['heroid' => 115, 'increase_win_rate' => 0.0261]],
                'sub_hero_last' => [['heroid' => 47, 'increase_win_rate' => -0.1298]],
            ]]]]]),
            '*/api/heroes?*' => Http::response(['code' => 0, 'data' => ['records' => [
                ['data' => ['hero_id' => 30, 'hero' => ['data' => ['head' => 'https://wsrv.nl/?url=yss.png']]]],
            ]]]),
        ]);
    }

    public function test_heroes_are_normalized(): void
    {
        self::fakeApi();

        $heroes = collect(app(RoneArena::class)->heroes())->keyBy('id');

        $this->assertSame(['Balmond', 'Yi Sun-shin'], $heroes->pluck('name')->all(), 'sorted by name');
        $this->assertSame(['assassin', 'marksman'], $heroes[30]['roles'], 'lower-cased, empty padding dropped');
        $this->assertSame(['jungle'], $heroes[30]['lanes']);
        $this->assertSame(['exp', 'jungle'], $heroes[2]['lanes']);
        $this->assertSame([2], $heroes[30]['strong'], 'zero ids dropped');
        $this->assertSame('https://wsrv.nl/?url=yss.png', $heroes[30]['head']);
    }

    public function test_rank_stats_and_matchups_are_normalized(): void
    {
        self::fakeApi();
        $api = app(RoneArena::class);

        $this->assertSame(['wr' => 0.55, 'pr' => 0.011, 'br' => 0.05], $api->rankStats('mythic', 7)[30]);

        $m = $api->matchups([30], 'mythic', 7)[30];
        $this->assertSame([[114, 2.07]], $m['counteredBy']);
        $this->assertSame([[59, -2.06]], $m['beats']);
        $this->assertSame([[115, 2.61]], $m['goodWith']);
        $this->assertSame([[47, -12.98]], $m['badWith']);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/api/heroes/rank') && $r['rank'] === 'mythic' && $r['days'] === '7');
    }

    public function test_snapshots_are_reused_within_a_day_and_refreshed_after(): void
    {
        self::fakeApi(0.55);
        $data = app(DraftData::class);

        $this->assertSame(0.55, $data->stats('mythic', 7)[30]['wr']);

        // A new day's numbers arrive… (fresh factory: earlier fakes would otherwise still match first)
        Http::swap(new Factory);
        self::fakeApi(0.6);
        $this->assertSame(0.55, $data->stats('mythic', 7)[30]['wr'], 'still fresh — served from the snapshot');

        $this->travel(25)->hours();
        $this->assertSame(0.6, $data->stats('mythic', 7)[30]['wr'], 'older than 24h — refetched');
        $this->assertNotNull($data->statsUpdatedAt('mythic', 7));
    }

    public function test_stale_snapshot_is_served_when_the_api_is_down(): void
    {
        self::fakeApi(0.55);
        $data = app(DraftData::class);
        $data->stats('glory', 7);

        $this->travel(2)->days();
        Http::swap(new Factory);
        Http::fake(['*' => Http::response('down', 503)]);

        $this->assertSame(0.55, $data->stats('glory', 7)[30]['wr']);
    }

    public function test_matchups_are_cached_per_hero(): void
    {
        self::fakeApi();
        $data = app(DraftData::class);

        $data->matchups([30], 'mythic', 7);
        $data->matchups([30], 'mythic', 7);

        Http::assertSentCount(2); // counters + compatibility, once
    }

    public function test_the_daily_command_refreshes_every_rank(): void
    {
        self::fakeApi();

        $this->artisan('mlbb:refresh')->assertSuccessful()->expectsOutputToContain('refreshed');

        foreach (['all', 'epic', 'legend', 'mythic', 'honor', 'glory'] as $rank) {
            Storage::disk('local')->assertExists("mlbb/stats/{$rank}-7.json");
        }
        Storage::disk('local')->assertExists('mlbb/heroes.json');

        $this->artisan('mlbb:refresh --days=2')->assertExitCode(2);
    }

    public function test_temporary_server_errors_are_retried(): void
    {
        Sleep::fake();
        Http::fake([
            '*/api/heroes/rank*' => Http::sequence()
                ->push('busy', 503)
                ->push(['code' => 0, 'data' => ['records' => [['data' => ['main_heroid' => 30, 'main_hero_win_rate' => 0.5, 'main_hero_appearance_rate' => 0.01, 'main_hero_ban_rate' => 0.1]]]]]),
        ]);

        $this->assertSame(0.5, app(RoneArena::class)->rankStats('mythic', 7)[30]['wr']);
        Http::assertSentCount(2);
    }

    public function test_one_failing_rank_does_not_stop_the_others(): void
    {
        Sleep::fake();
        self::fakeApi();
        // Glory keeps answering 503; everything else works.
        Http::swap(new Factory);
        Http::fake(['*rank=glory*' => Http::response('down', 503)]);
        self::fakeApi();

        $result = app(DraftData::class)->refreshAll(7);

        $this->assertSame(['stats:glory'], array_keys($result['failed']));
        $this->assertStringContainsString('HTTP 503', $result['failed']['stats:glory']);
        $this->assertSame(2, $result['done']['heroes']);
        $this->assertCount(6, $result['done'], 'heroes + 5 ranks still refreshed');

        $this->artisan('mlbb:refresh')->assertFailed()->expectsOutputToContain('FAILED');
    }

    public function test_the_refresh_is_scheduled_daily(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('mlbb:refresh');
    }
}
