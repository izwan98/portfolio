<?php

namespace Tests\Unit\ApiDetective;

use App\Services\ApiDetective\Summarizer;
use Tests\TestCase;

class SummarizerTest extends TestCase
{
    private function endpoint(string $method, string $path, array $extra = []): array
    {
        return $extra + [
            'id' => "{$method} {$path}", 'method' => $method, 'path' => $path, 'summary' => '', 'description' => '',
            'tags' => [], 'deprecated' => false, 'parameters' => [], 'requestBody' => null, 'responses' => [], 'security' => [], 'origin' => 'spec',
        ];
    }

    public function test_it_derives_summaries_from_rest_conventions(): void
    {
        $s = new Summarizer;

        $this->assertSame('Lists orders.', $s->describe($this->endpoint('GET', '/v1/orders'))['summary']);
        $this->assertSame('Retrieves a single order by orderId.', $s->describe($this->endpoint('GET', '/v1/orders/{orderId}'))['summary']);
        $this->assertSame('Creates a new order.', $s->describe($this->endpoint('POST', '/orders'))['summary']);
        $this->assertSame('Deletes order orderId.', $s->describe($this->endpoint('DELETE', '/orders/{orderId}'))['summary']);
        $this->assertSame('Lists items for a order.', $s->describe($this->endpoint('GET', '/orders/{orderId}/items'))['summary']);
    }

    public function test_the_spec_summary_wins(): void
    {
        $this->assertSame('Find pets', (new Summarizer)->describe($this->endpoint('GET', '/pets', ['summary' => 'Find pets']))['summary']);
    }

    public function test_details_and_tips_use_parameters_bodies_and_responses(): void
    {
        $doc = (new Summarizer)->describe($this->endpoint('GET', '/pets', [
            'deprecated' => true,
            'security' => ['apiKey'],
            'parameters' => [
                ['name' => 'status', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ['available', 'sold']]],
                ['name' => 'limit', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']],
            ],
            'responses' => [
                ['status' => '200', 'description' => 'ok', 'schema' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Pet']]],
                ['status' => '400', 'description' => 'bad', 'schema' => null],
            ],
        ]), ['#/components/schemas/Pet' => ['type' => 'object', 'properties' => ['id' => [], 'name' => []]]]);

        $this->assertStringContainsString('Requires status.', $doc['details']);
        $this->assertStringContainsString('Optional: limit.', $doc['details']);
        $this->assertStringContainsString('Returns 200 with a list of {id, name}.', $doc['details']);
        $this->assertSame([
            'Deprecated — avoid it in new integrations.',
            'Needs auth: apiKey.',
            'Paginated — loop over pages to get everything.',
            '"status" accepts: available, sold.',
        ], $doc['tips']);
    }

    public function test_annotate_adds_overview_and_auth_notes(): void
    {
        $api = (new Summarizer)->annotate([
            'title' => 'Shop', 'description' => 'Sells things.', 'refs' => [],
            'auth' => [['name' => 'bearer', 'type' => 'http', 'scheme' => 'bearer', 'in' => '', 'paramName' => '']],
            'endpoints' => [$this->endpoint('GET', '/items', ['tags' => ['items']]), $this->endpoint('POST', '/items', ['tags' => ['items']])],
        ]);

        $this->assertSame('Sells things. Shop exposes 2 endpoints (1 GET, 1 POST). Main areas: items.', $api['overview']);
        $this->assertSame('Authenticate with a Bearer token in the Authorization header.', $api['authNotes']);
        $this->assertSame('Creates a new item.', $api['endpoints'][1]['doc']['summary']);
    }
}
