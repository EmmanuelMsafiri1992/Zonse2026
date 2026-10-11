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

    public function test_menu_and_app_grid_icons_are_drawn_once_per_page_and_referenced_by_every_link(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules(['banking', 'expenses', 'petty-cash', 'payroll', 'veterinary'], $owner);

        // A second page in the same process must draw its icons again.
        foreach ([route('dashboard'), route('dashboard'), route('apps.show', 'banking')] as $url) {
            $html = $this->actingAs($owner)->get($url)->assertOk()->getContent();
            preg_match_all('/<use href="#(zi-[a-z0-9-]+)"/', $html, $references);
            preg_match_all('/<symbol id="(zi-[a-z0-9-]+)"/', $html, $symbols);

            $this->assertNotEmpty($references[1]);
            $this->assertSame(count($symbols[1]), count(array_unique($symbols[1])), 'Each icon is defined once.');
            $this->assertEqualsCanonicalizing(array_values(array_unique($references[1])), $symbols[1], 'Every referenced icon has a symbol.');
            $this->assertStringContainsString('zi-chevron-right', $html);
        }

        preg_match('/<aside class="z-sidebar">.*?<\/aside>/s', $html, $sidebar);
        $this->assertStringNotContainsString('<path', preg_replace('/<symbol.*?<\/symbol>/s', '', $sidebar[0]), 'Menu links carry no icon paths of their own.');
    }
}
