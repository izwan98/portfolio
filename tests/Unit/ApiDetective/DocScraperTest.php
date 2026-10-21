<?php

namespace Tests\Unit\ApiDetective;

use App\Services\ApiDetective\DocScraper;
use PHPUnit\Framework\TestCase;

class DocScraperTest extends TestCase
{
    public function test_it_finds_spec_links_and_prefers_the_same_host(): void
    {
        $html = <<<'HTML'
            <link href="./swagger-ui.css" rel="stylesheet">
            <a href="/docs/openapi.yaml">Download spec</a>
            <script>const other = "https://elsewhere.io/v2/swagger.json"; SwaggerUIBundle({ url: "/v3/api-docs" })</script>
            HTML;

        $links = (new DocScraper)->specLinks($html, 'https://api.example.com/docs/');

        $this->assertSame('https://api.example.com/docs/openapi.yaml', $links[0]);
        $this->assertContains('https://api.example.com/v3/api-docs', $links);
        $this->assertContains('https://elsewhere.io/v2/swagger.json', $links);
        $this->assertNotContains('https://api.example.com/docs/swagger-ui.css', $links);
    }

    public function test_it_finds_swagger_ui_initializer_scripts(): void
    {
        $html = '<script src="./swagger-ui-bundle.js"></script><script src="./swagger-initializer.js" charset="UTF-8"></script>';

        $this->assertSame(
            ['https://petstore.example.com/swagger-initializer.js'],
            (new DocScraper)->initializerScripts($html, 'https://petstore.example.com/'),
        );
    }

    public function test_it_scrapes_endpoints_from_prose_curl_and_fetch(): void
    {
        $html = <<<'HTML'
            <h2>Users</h2>
            <p>Use <code>GET /v1/users/:userId</code> to fetch one user.</p>
            <pre>curl -X DELETE https://api.example.com/v1/users/42 -H "Authorization: Bearer x"</pre>
            <pre>curl https://api.example.com/v1/teams -d '{"name":"x"}'</pre>
            <pre>fetch('https://api.example.com/v1/posts/1', { method: 'PATCH', body: '{}' })</pre>
            <p>See GET /about.html for more.</p>
            HTML;

        $result = (new DocScraper)->endpoints($html, 'https://example.com/docs');
        $ids = array_column($result['endpoints'], 'id');

        $this->assertContains('GET /v1/users/{userId}', $ids, ':param is converted');
        $this->assertContains('DELETE /v1/users/{userId}', $ids, 'numeric IDs become parameters');
        $this->assertContains('POST /v1/teams', $ids, 'curl with -d defaults to POST');
        $this->assertContains('PATCH /v1/posts/{postId}', $ids);
        $this->assertNotContains('GET /about.html', $ids);
        $this->assertSame(['https://api.example.com'], $result['servers']);

        $delete = collect($result['endpoints'])->firstWhere('id', 'DELETE /v1/users/{userId}');
        $this->assertSame('42', $delete['parameters'][0]['example']);
        $this->assertSame('integer', $delete['parameters'][0]['schema']['type']);
        $this->assertSame('scraped:curl', $delete['origin']);
        $this->assertStringContainsString('curl -X DELETE', $delete['evidence']);
    }

    public function test_it_templatizes_ids(): void
    {
        $this->assertSame(['/orders/{orderId}/items/{itemId}', ['orderId' => '7', 'itemId' => '3']], DocScraper::templatize('/orders/7/items/3'));
        $this->assertSame(['/v1/status', []], DocScraper::templatize('/v1/status'));
    }
}
