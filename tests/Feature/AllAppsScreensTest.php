<?php

namespace Tests\Feature;

use App\Blueprints\BlueprintRegistry;
use App\Blueprints\Field;
use App\Models\Record;
use App\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

/**
 * Opens every screen of every app, with a sparse record in each status and one linking to deleted records,
 * so a page that breaks on missing details or an unusual status is caught even when no test for that app touches it.
 */
class AllAppsScreensTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    /** @return array<string, array{0: string}> */
    public static function suites(): array
    {
        $suites = array_map(fn (string $file) => pathinfo($file, PATHINFO_FILENAME), glob(__DIR__.'/../../app/Blueprints/definitions/*.php'));
        sort($suites);

        return array_combine($suites, array_map(fn (string $suite) => [$suite], $suites));
    }

    #[DataProvider('suites')]
    public function test_every_screen_of_every_app_in_the_suite_opens(string $suite): void
    {
        $this->seedCatalogue();
        $blueprints = app(BlueprintRegistry::class)->forSuite($suite);
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules(array_keys($blueprints), $owner);
        app(WorkspaceContext::class)->set($workspace = $workspace->fresh());
        $this->actingAs($owner);

        $broken = [];
        $check = function (string $url, TestResponse $response) use (&$broken): void {
            if ($response->status() !== 200) {
                $broken[] = $response->status().' '.$url.($response->exception ? ': '.$response->exception->getMessage().' at '.basename($response->exception->getFile()).':'.$response->exception->getLine() : '');
            }
        };

        foreach ($blueprints as $key => $blueprint) {
            foreach ($blueprint->entities as $entity) {
                foreach ($entity->statuses ?: [$entity->defaultStatus()] as $status) {
                    Record::factory()->ofEntity($key, $entity->key)->create([
                        'workspace_id' => $workspace->id,
                        'title' => $entity->label.' '.$status,
                        'status' => $status,
                        'amount' => 100,
                        'occurs_on' => today(),
                        'due_on' => today()->addWeek(),
                    ]);
                }
                $deletedLinks = collect($entity->fields)->filter(fn (Field $field) => in_array($field->type, ['record', 'user'], true))->map(fn () => 999999)->all();
                if ($deletedLinks) {
                    Record::factory()->ofEntity($key, $entity->key, $deletedLinks)->create([
                        'workspace_id' => $workspace->id,
                        'title' => $entity->label.' with deleted links',
                        'status' => $entity->defaultStatus(),
                        'amount' => 100,
                        'occurs_on' => today(),
                    ]);
                }
            }
            foreach ([route('apps.show', $key), route('apps.reports', $key)] as $url) {
                $check($url, $this->get($url));
            }
            foreach ($blueprint->entities as $entity) {
                foreach (['index', 'create'] as $page) {
                    $url = route('apps.records.'.$page, [$key, $entity->key]);
                    $check($url, $this->get($url));
                }
                foreach (Record::where('blueprint', $key)->where('entity', $entity->key)->get() as $record) {
                    foreach ([$record->url(), $record->url().'/edit'] as $url) {
                        $check($url, $this->get($url));
                    }
                }
            }
        }

        $this->assertSame([], $broken, count($broken).' screens failed to open in '.$suite);
    }
}
