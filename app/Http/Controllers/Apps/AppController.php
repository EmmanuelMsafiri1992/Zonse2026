<?php

namespace App\Http\Controllers\Apps;

use App\Blueprints\Blueprint;
use App\Blueprints\BlueprintRegistry;
use App\Http\Controllers\Controller;
use App\Models\Record;
use App\Tenancy\WorkspaceContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Modules\Contacts\Models\Contact;

/** Overview pages for blueprint apps: all enabled apps, and one app's home. */
class AppController extends Controller
{
    public function __construct(protected BlueprintRegistry $blueprints, protected WorkspaceContext $context) {}

    public function index(Request $request): View
    {
        $workspace = $this->context->getOrFail();
        $enabledKeys = $workspace->enabledModuleKeys();

        $apps = collect($this->blueprints->all())
            ->filter(fn (Blueprint $b) => in_array($b->key, $enabledKeys, true))
            ->sortBy('name')->values();

        $counts = Record::query()->whereIn('blueprint', $apps->pluck('key'))
            ->selectRaw('blueprint, count(*) as total')->groupBy('blueprint')->pluck('total', 'blueprint');

        $contact = $request->query('contact') ? Contact::query()->find($request->query('contact')) : null;
        $contactRecords = $contact
            ? Record::query()->forContact($contact->id)->whereIn('blueprint', $apps->pluck('key'))->orderByDesc('id')->limit(50)->get()
            : collect();

        return view('apps.index', [
            'apps' => $apps,
            'counts' => $counts,
            'contact' => $contact,
            'contactRecords' => $contactRecords,
        ]);
    }

    public function show(string $blueprint): View
    {
        $app = $this->blueprints->get($blueprint) ?? abort(404);

        $entities = [];
        foreach ($app->entities as $entity) {
            $base = Record::query()->ofEntity($app->key, $entity->key);
            $entities[] = [
                'def' => $entity,
                'count' => (clone $base)->count(),
                'byStatus' => (clone $base)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
                'recent' => (clone $base)->with('contact')->orderByDesc('id')->limit(5)->get(),
                'dueSoon' => $entity->hasDue()
                    ? (clone $base)->whereNotNull('due_on')->whereBetween('due_on', [now()->startOfDay(), now()->addDays(7)->endOfDay()])->count()
                    : null,
                'amount' => $entity->hasAmount() ? (float) (clone $base)->sum('amount') : null,
            ];
        }

        return view('apps.show', ['app' => $app, 'entities' => $entities]);
    }
}
