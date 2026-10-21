<?php

namespace Tests\Unit\ApiDetective;

use App\Services\ApiDetective\SpecParser;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SpecParserTest extends TestCase
{
    public function test_it_decodes_json_and_yaml_but_not_html(): void
    {
        $this->assertSame(['a' => 1], SpecParser::decode('{"a":1}'));
        $this->assertSame(['openapi' => '3.0.0'], SpecParser::decode("openapi: 3.0.0\n"));
        $this->assertNull(SpecParser::decode('<!doctype html><title>x</title>'));
        $this->assertNull(SpecParser::decode(''));
    }

    public function test_it_normalizes_openapi_3(): void
    {
        $api = (new SpecParser)->parse([
            'openapi' => '3.0.3',
            'info' => ['title' => 'Pets', 'version' => '2'],
            'servers' => [['url' => '/v2']],
            'components' => [
                'schemas' => ['Pet' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]],
                'parameters' => ['PetId' => ['name' => 'petId', 'in' => 'path', 'schema' => ['type' => 'integer']]],
                'securitySchemes' => ['key' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Key']],
            ],
            'security' => [['key' => []]],
            'paths' => [
                '/pets/{petId}' => [
                    'parameters' => [['$ref' => '#/components/parameters/PetId']],
                    'get' => [
                        'tags' => ['pets'],
                        'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Pet']]]]],
                    ],
                    'put' => [
                        'requestBody' => ['required' => true, 'content' => [
                            'application/xml' => ['schema' => ['type' => 'string']],
                            'application/json' => ['schema' => ['$ref' => '#/components/schemas/Pet']],
                        ]],
                        'responses' => ['204' => ['description' => 'done']],
                    ],
                ],
            ],
        ], 'https://api.example.com/spec/openapi.json');

        $this->assertSame('Pets', $api['title']);
        $this->assertSame('https://api.example.com/v2', $api['servers'][0]['url']);
        $this->assertCount(2, $api['endpoints']);

        [$get, $put] = $api['endpoints'];
        $this->assertSame('GET /pets/{petId}', $get['id']);
        $this->assertSame('petId', $get['parameters'][0]['name']);
        $this->assertTrue($get['parameters'][0]['required'], 'path params are always required');
        $this->assertSame(['$ref' => '#/components/schemas/Pet'], $get['responses'][0]['schema']);
        $this->assertSame(['key'], $get['security']);

        $this->assertSame('application/json', $put['requestBody']['contentType'], 'JSON media is preferred');
        $this->assertTrue($put['requestBody']['required']);

        $this->assertArrayHasKey('#/components/schemas/Pet', $api['refs']);
        $this->assertSame('X-Key', $api['auth'][0]['paramName']);
    }

    public function test_it_normalizes_swagger_2(): void
    {
        $api = (new SpecParser)->parse([
            'swagger' => '2.0',
            'info' => ['title' => 'Old', 'version' => '1'],
            'host' => 'old.example.com',
            'basePath' => '/api/',
            'schemes' => ['http', 'https'],
            'definitions' => ['User' => ['type' => 'object']],
            'paths' => [
                '/users' => [
                    'post' => [
                        'parameters' => [
                            ['name' => 'body', 'in' => 'body', 'required' => true, 'schema' => ['$ref' => '#/definitions/User']],
                            ['name' => 'dryRun', 'in' => 'query', 'type' => 'boolean'],
                        ],
                        'responses' => ['201' => ['description' => 'Created', 'schema' => ['$ref' => '#/definitions/User']]],
                    ],
                ],
                '/upload' => [
                    'post' => [
                        'consumes' => ['multipart/form-data'],
                        'parameters' => [['name' => 'file', 'in' => 'formData', 'type' => 'file', 'required' => true]],
                        'responses' => ['200' => ['description' => 'ok']],
                    ],
                ],
            ],
        ]);

        $this->assertSame('https://old.example.com/api', $api['servers'][0]['url'], 'https is preferred and trailing slash trimmed');

        [$create, $upload] = $api['endpoints'];
        $this->assertSame(['$ref' => '#/definitions/User'], $create['requestBody']['schema']);
        $this->assertSame(['dryRun'], array_column($create['parameters'], 'name'), 'body param moves out of parameters');
        $this->assertSame(['type' => 'boolean'], $create['parameters'][0]['schema']);

        $this->assertSame('multipart/form-data', $upload['requestBody']['contentType']);
        $this->assertSame(['file'], $upload['requestBody']['schema']['required']);
        $this->assertArrayHasKey('#/definitions/User', $api['refs']);
    }

    public function test_it_rejects_non_specs(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SpecParser)->parse(['hello' => 'world']);
    }
}
