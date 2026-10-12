<?php

namespace App\Http\Controllers\Api\V1;

use App\Blueprints\BlueprintRegistry;
use App\Blueprints\Entity;
use App\Http\Requests\Api\RecordApiRequest;
use App\Http\Resources\V1\RecordResource;
use App\Models\Record;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/** Records of any app the workspace has switched on, e.g. /api/v1/apps/clinic/patients. */
class RecordController extends ApiController
{
    public function __construct(protected BlueprintRegistry $blueprints) {}

    public function index(Request $request, string $blueprint, string $entity): AnonymousResourceCollection
    {
        $def = $this->entity($blueprint, $entity);
        $this->authorize('viewAny', Record::class);

        $records = Record::query()->ofEntity($def->blueprintKey, $def->key)
            ->search($request->string('q')->toString())
            ->status($request->string('status')->toString() ?: null)
            ->forContact($request->input('contact_id'))
            ->assignedTo($request->input('assignee_id'))
            ->latest('id')->paginate($this->perPage($request))->withQueryString();

        return RecordResource::collection($records);
    }

    public function show(string $blueprint, string $entity, int $record): RecordResource
    {
        $record = $this->find($this->entity($blueprint, $entity), $record);
        $this->authorize('view', $record);

        return new RecordResource($record);
    }

    public function store(RecordApiRequest $request, string $blueprint, string $entity): JsonResponse
    {
        $def = $this->entity($blueprint, $entity);
        $this->authorize('create', Record::class);

        $record = Record::create([...$request->payload(), 'blueprint' => $def->blueprintKey, 'entity' => $def->key]);

        return (new RecordResource($record->fresh()))->response()->setStatusCode(201);
    }

    public function update(RecordApiRequest $request, string $blueprint, string $entity, int $record): RecordResource
    {
        $record = $this->find($this->entity($blueprint, $entity), $record);
        $this->authorize('update', $record);

        $payload = $request->payload();
        // Keys starting with "_" are kept by the app logic (sale lines, billing marks), not sent by callers.
        $payload['data'] = array_merge(array_filter((array) $record->data, fn ($key) => str_starts_with((string) $key, '_'), ARRAY_FILTER_USE_KEY), $payload['data']);
        $record->update($payload);

        return new RecordResource($record->fresh());
    }

    public function destroy(string $blueprint, string $entity, int $record): Response
    {
        $record = $this->find($this->entity($blueprint, $entity), $record);
        $this->authorize('delete', $record);

        $record->delete();

        return response()->noContent();
    }

    protected function entity(string $blueprint, string $entity): Entity
    {
        return $this->blueprints->entity($blueprint, $entity) ?? abort(404);
    }

    protected function find(Entity $def, int $id): Record
    {
        return Record::query()->ofEntity($def->blueprintKey, $def->key)->findOrFail($id);
    }
}
