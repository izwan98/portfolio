<?php

namespace Tests\Feature;

use Tests\TestCase;

class HomeTest extends TestCase
{
    public function test_the_landing_page_links_every_project(): void
    {
        $response = $this->get('/')->assertOk();

        foreach (['/me', '/api-detective', '/mlbb-draft', '/triple-t'] as $path) {
            $response->assertSee('href="'.url($path).'"', false);
        }
    }

    public function test_every_linked_project_page_loads(): void
    {
        foreach (['/me', '/api-detective', '/mlbb-draft', '/triple-t'] as $path) {
            $this->get($path)->assertOk();
        }
    }
}
