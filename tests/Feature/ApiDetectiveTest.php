<?php

namespace Tests\Feature;

use App\Services\ApiDetective\SafeHttp;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApiDetectiveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Resolve every host to a public address without touching real DNS.
        $this->app->bind(SafeHttp::class, fn () => new SafeHttp(fn () => ['93.184.215.14'], allowPrivate: false));
        Http::preventStrayRequests();
    }

    public function test_the_page_renders(): void
    {
        $this->get('/api-detective')->assertOk()->assertSee('API Detective')->assertSee('Every API leaves clues.');
    }

    public function test_the_bundled_demo_spec_is_discovered_and_documented(): void
    {
        $spec = $this->get('/api-detective/demo-spec')->assertOk()->getContent();

        $response = $this->postJson('/api-detective/discover', ['mode' => 'paste', 'input' => $spec])->assertOk();

        $response->assertJsonPath('api.title', 'JSONPlaceholder')
            ->assertJsonPath('api.source.kind', 'openapi')
            ->assertJsonCount(12, 'api.endpoints')
            ->assertJsonPath('api.endpoints.0.id', 'GET /posts')
            ->assertJsonPath('api.endpoints.0.doc.summary', 'List posts')
            ->assertJsonPath('api.authNotes', 'No authentication is documented.')
            ->assertJsonPath('log.0.status', 'hit');
    }

    public function test_pasting_something_that_is_not_a_spec_explains_why(): void
    {
        $this->postJson('/api-detective/discover', ['mode' => 'paste', 'input' => '{"hello":"world"}'])
            ->assertStatus(422)
            ->assertJsonPath('log.0.status', 'miss');
    }

    public function test_a_direct_spec_url_is_found_in_tier_one(): void
    {
        Http::fake([
            'api.example.com/openapi.json' => Http::response(['openapi' => '3.1.0', 'info' => ['title' => 'Ex', 'version' => '1'], 'paths' => ['/ping' => ['get' => ['responses' => ['200' => ['description' => 'pong']]]]]]),
        ]);

        $this->postJson('/api-detective/discover', ['mode' => 'url', 'input' => 'https://api.example.com/openapi.json'])
            ->assertOk()
            ->assertJsonPath('api.endpoints.0.id', 'GET /ping')
            ->assertJsonPath('api.servers.0.url', 'https://api.example.com')
            ->assertJsonPath('log.1.step', 'Tier 1 · Direct spec');
    }

    public function test_a_docs_page_without_a_spec_falls_back_to_scraping(): void
    {
        Http::fake([
            'docs.example.com/guide' => Http::response('<html><title>Guide</title><p>Call GET /v1/widgets to list widgets.</p></html>', 200, ['Content-Type' => 'text/html']),
            '*' => Http::response('Not found', 404),
        ]);

        $this->postJson('/api-detective/discover', ['mode' => 'url', 'input' => 'https://docs.example.com/guide'])
            ->assertOk()
            ->assertJsonPath('api.source.kind', 'scraped')
            ->assertJsonPath('api.endpoints.0.id', 'GET /v1/widgets')
            ->assertJsonPath('api.endpoints.0.doc.summary', 'Lists widgets.');
    }

    public function test_the_request_tester_proxies_and_reports_the_response(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['ok' => true], 201, ['X-Trace' => 'abc'])]);

        $this->postJson('/api-detective/send', [
            'method' => 'POST',
            'url' => 'https://api.example.com/things?x=1',
            'headers' => ['Content-Type' => 'application/json', 'Host' => 'evil.internal'],
            'body' => '{"name":"x"}',
        ])->assertOk()
            ->assertJsonPath('status', 201)
            ->assertJsonPath('body', '{"ok":true}')
            ->assertJsonPath('headers.x-trace', 'abc');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->body() === '{"name":"x"}'
            && ! in_array('evil.internal', $r->header('Host'), true));
    }

    public function test_the_request_tester_refuses_private_addresses(): void
    {
        $this->app->bind(SafeHttp::class, fn () => new SafeHttp(fn () => ['169.254.169.254'], allowPrivate: false));

        $this->postJson('/api-detective/send', ['method' => 'GET', 'url' => 'http://metadata.internal/latest'])
            ->assertStatus(422)
            ->assertJsonPath('error', '"metadata.internal" points to a private or reserved address (169.254.169.254), which is blocked.');

        Http::assertNothingSent();
    }
}
