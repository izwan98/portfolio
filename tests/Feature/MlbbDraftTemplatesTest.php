<?php

namespace Tests\Feature;

use App\Services\MlbbDraft\DraftTemplates;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MlbbDraftTemplatesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function snakePicks(): array
    {
        return collect([['first', 1], ['second', 1], ['second', 2], ['first', 2], ['first', 3], ['second', 3], ['second', 4], ['first', 4], ['first', 5], ['second', 5]])
            ->map(fn ($p) => ['action' => 'pick', 'team' => $p[0], 'player' => $p[1]])->all();
    }

    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Scrim — 1 ban each',
            'author' => 'Izwan',
            'description' => 'Quick scrims',
            'blindBans' => false,
            'steps' => [
                ['action' => 'ban', 'team' => 'first', 'player' => 1],
                ['action' => 'ban', 'team' => 'second', 'player' => 1],
                ...$this->snakePicks(),
            ],
            ...$overrides,
        ];
    }

    public function test_every_built_in_template_is_valid(): void
    {
        foreach (DraftTemplates::builtIns() as $t) {
            $clean = DraftTemplates::validate($t);
            $this->assertSame($t['steps'], $clean['steps'], $t['id']);
        }

        $bans = fn ($id) => collect(DraftTemplates::builtIns())->firstWhere('id', $id)['steps'];
        $this->assertNull(collect(DraftTemplates::builtIns())->firstWhere('id', 'ranked-10'), 'Mythic+ uses the MPL draft instead');
        $legend = $bans('ranked-8');
        $this->assertSame(
            'first:1 first:2 second:1 second:2 first:3 first:4 second:3 second:4',
            implode(' ', array_map(fn ($s) => $s['team'].':'.$s['player'], array_slice($legend, 0, 8))),
            'Legend: two blind rounds of 2 bans per team',
        );
        $this->assertTrue($legend[4]['newRound'] ?? false, 'round 2 starts with the first team\'s player 3');
        $this->assertSame(['first', 'second', 'second', 'first', 'first', 'second', 'second', 'first', 'first', 'second'], array_column(array_slice($legend, 8), 'team'), '1-2-2-2-2-1 picks');

        $mpl = $bans('tournament');
        $this->assertCount(10, array_filter($mpl, fn ($s) => $s['action'] === 'ban'));
        $this->assertSame(
            'ban ban ban ban ban ban pick pick pick pick pick pick ban ban ban ban pick pick pick pick',
            implode(' ', array_column($mpl, 'action')),
            'MPL: 3 bans each, 3 picks each, 2 bans each, 2 picks each',
        );
        $this->assertSame(['first', 'second', 'first', 'second'], array_column(array_slice($mpl, 0, 4), 'team'), 'bans alternate, first team starts');
        $this->assertSame('second', $mpl[12]['team'], 'second ban phase starts with the second team');
        $this->assertCount(6, array_filter($bans('ranked-6'), fn ($s) => $s['action'] === 'ban'));
        $this->assertSame(['first', 'second', 'second', 'first'], array_column(array_slice($bans('ranked-6'), 0, 4), 'team'), 'first-pick team bans first, snaking');

    }

    public function test_sequences_must_give_each_team_five_picks_one_per_player(): void
    {
        $problems = DraftTemplates::sequenceProblems([
            ['action' => 'pick', 'team' => 'first', 'player' => 1],
            ['action' => 'pick', 'team' => 'first', 'player' => 1],
            ['action' => 'ban', 'team' => 'second', 'player' => 2],
            ['action' => 'ban', 'team' => 'second', 'player' => 2],
        ]);

        $this->assertContains('First team: player 1 has more than one pick.', $problems);
        $this->assertContains('First team must pick 5 times (one per player) — it has 2.', $problems);
        $this->assertContains('Second team: player 2 has more than one ban.', $problems);
        $this->assertContains('Second team must pick 5 times (one per player) — it has 0.', $problems);
    }

    public function test_the_page_ships_the_templates(): void
    {
        $this->get('/mlbb-draft')->assertOk()->assertSee('ranked-6', false)->assertSee('MPL style (10 bans)');
    }

    public function test_templates_are_shared_and_only_the_creator_can_change_them(): void
    {
        $created = $this->postJson('/mlbb-draft/templates', $this->payload())->assertCreated();
        $id = $created->json('template.id');
        $key = $created->json('key');

        $this->assertStringStartsWith('scrim-1-ban-each-', $id);
        $this->assertSame(40, strlen($key));
        $this->assertArrayNotHasKey('keyHash', $created->json('template'));

        // Everyone sees it (after the built-ins), without the secret.
        $list = $this->getJson('/mlbb-draft/templates')->assertOk()->json('templates');
        $this->assertSame($id, collect($list)->firstWhere('builtIn', false)['id']);
        $this->assertStringNotContainsString('keyHash', json_encode($list));

        // A stranger can't edit or delete it.
        $this->putJson("/mlbb-draft/templates/{$id}", $this->payload(['name' => 'Hijacked']), ['X-Template-Key' => 'nope'])->assertForbidden();
        $this->deleteJson("/mlbb-draft/templates/{$id}")->assertForbidden();

        // The creator can.
        $steps = $this->payload()['steps'];
        $steps[1]['newRound'] = true;
        $steps[2]['newRound'] = true; // ignored on picks
        $this->putJson("/mlbb-draft/templates/{$id}", $this->payload(['name' => 'Scrim v2', 'blindBans' => true, 'steps' => $steps]), ['X-Template-Key' => $key])
            ->assertOk()
            ->assertJsonPath('template.steps.1.newRound', true)
            ->assertJsonMissingPath('template.steps.2.newRound')
            ->assertJsonPath('template.name', 'Scrim v2')
            ->assertJsonPath('template.blindBans', true);

        $this->deleteJson("/mlbb-draft/templates/{$id}", [], ['X-Template-Key' => $key])->assertOk();
        $this->assertCount(count(DraftTemplates::builtIns()), $this->getJson('/mlbb-draft/templates')->json('templates'));
    }

    public function test_invalid_templates_are_rejected_with_reasons(): void
    {
        $this->postJson('/mlbb-draft/templates', $this->payload(['name' => 'x', 'steps' => [['action' => 'pick', 'team' => 'first', 'player' => 9]]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'steps']);

        $this->postJson('/mlbb-draft/templates', $this->payload(['name' => '<b>Bold</b> name']))->assertCreated()->assertJsonPath('template.name', 'Bold name');
    }

    public function test_built_in_templates_cannot_be_edited(): void
    {
        $this->putJson('/mlbb-draft/templates/tournament', $this->payload(), ['X-Template-Key' => 'x'])->assertNotFound();
    }

    public function test_store_survives_concurrent_style_writes(): void
    {
        $store = app(DraftTemplates::class);
        for ($i = 0; $i < 5; $i++) {
            $store->create($this->payload(['name' => "Template {$i}"]));
        }

        $this->assertCount(5, array_filter($store->all(), fn ($t) => ! $t['builtIn']));
        $this->expectException(ValidationException::class);
        $store->create($this->payload(['steps' => []]));
    }
}
