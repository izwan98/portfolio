<?php

namespace Tests\Unit\ApiDetective;

use App\Services\ApiDetective\BlockedUrlException;
use App\Services\ApiDetective\SafeHttp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SafeHttpTest extends TestCase
{
    #[DataProvider('privateAddresses')]
    public function test_private_and_reserved_addresses_are_not_public(string $ip): void
    {
        $this->assertFalse(SafeHttp::isPublicIp($ip));
    }

    public static function privateAddresses(): array
    {
        return array_map(fn ($ip) => [$ip], [
            '127.0.0.1', '10.1.2.3', '172.16.0.1', '192.168.1.1', '169.254.169.254',
            '0.0.0.0', '100.64.0.1', '::1', 'fd00::1', 'fe80::1', '::ffff:127.0.0.1',
        ]);
    }

    public function test_public_addresses_are_allowed(): void
    {
        $this->assertTrue(SafeHttp::isPublicIp('93.184.215.14'));
        $this->assertTrue(SafeHttp::isPublicIp('2606:4700::6810:85e5'));
    }

    public function test_hosts_resolving_to_private_addresses_are_blocked(): void
    {
        // Even one private record is enough to refuse (DNS rebinding).
        $http = new SafeHttp(fn () => ['93.184.215.14', '10.0.0.5'], allowPrivate: false);

        $this->expectException(BlockedUrlException::class);
        $http->assertAllowed('https://sneaky.example.com/');
    }

    public function test_it_rejects_bad_schemes_and_credentials(): void
    {
        $http = new SafeHttp(fn () => ['93.184.215.14'], allowPrivate: false);

        foreach (['file:///etc/passwd', 'gopher://x', 'ftp://example.com', 'https://user:pw@example.com/', '/relative'] as $url) {
            try {
                $http->assertAllowed($url);
                $this->fail("{$url} should be blocked");
            } catch (BlockedUrlException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_it_pins_the_validated_address(): void
    {
        $http = new SafeHttp(fn () => ['93.184.215.14'], allowPrivate: false);

        $this->assertSame(['host' => 'api.example.com', 'port' => 8443, 'ip' => '93.184.215.14'], $http->assertAllowed('https://api.example.com:8443/x'));
    }

    public function test_private_hosts_can_be_allowed_explicitly(): void
    {
        $http = new SafeHttp(fn () => ['127.0.0.1'], allowPrivate: true);

        $this->assertSame('127.0.0.1', $http->assertAllowed('http://localhost:8000')['ip']);
    }

    public function test_it_resolves_relative_urls(): void
    {
        $this->assertSame('https://a.com/docs/spec.json', SafeHttp::resolveUrl('https://a.com/docs/index.html', 'spec.json'));
        $this->assertSame('https://a.com/spec.json', SafeHttp::resolveUrl('https://a.com/docs/', '../spec.json'));
        $this->assertSame('https://a.com/v3/api-docs', SafeHttp::resolveUrl('https://a.com/docs/', '/v3/api-docs'));
        $this->assertSame('https://cdn.b.com/x.yaml', SafeHttp::resolveUrl('https://a.com/', '//cdn.b.com/x.yaml'));
    }
}
