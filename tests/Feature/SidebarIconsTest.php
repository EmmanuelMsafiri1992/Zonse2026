<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

class SidebarIconsTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    public function test_sidebar_icons_are_drawn_once_and_referenced_by_every_link(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules(['banking', 'expenses', 'petty-cash', 'payroll', 'veterinary'], $owner);

        $html = $this->actingAs($owner)->get(route('dashboard'))->assertOk()->getContent();
        preg_match('/<aside class="z-sidebar">.*?<\/aside>/s', $html, $sidebar);

        $this->assertNotEmpty($sidebar);
        preg_match_all('/<use href="#(zi-[a-z0-9-]+)"/', $sidebar[0], $references);
        preg_match_all('/<symbol id="(zi-[a-z0-9-]+)"/', $sidebar[0], $symbols);

        $this->assertNotEmpty($references[1]);
        $this->assertSame(count($symbols[1]), count(array_unique($symbols[1])), 'Each icon is defined once.');
        $this->assertEqualsCanonicalizing(array_values(array_unique($references[1])), $symbols[1], 'Every referenced icon has a symbol.');
        $this->assertStringContainsString('zi-chevron-right', $sidebar[0]);
        $this->assertStringNotContainsString('<path', preg_replace('/<symbol.*?<\/symbol>/s', '', $sidebar[0]), 'Links carry no icon paths of their own.');
    }
}
