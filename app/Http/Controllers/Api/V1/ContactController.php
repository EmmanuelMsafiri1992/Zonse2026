<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\ContactApiRequest;
use App\Http\Resources\V1\ContactResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Contacts\Models\Contact;

class ContactController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Contact::class);

        $contacts = Contact::query()->search($request->string('q')->toString())->ofType($request->string('type')->toString() ?: null)
            ->orderBy('name')->paginate($this->perPage($request))->withQueryString();

        return ContactResource::collection($contacts);
    }

    public function show(Contact $contact): ContactResource
    {
        $this->authorize('view', $contact);

        return new ContactResource($contact);
    }

    public function store(ContactApiRequest $request): JsonResponse
    {
        $this->authorize('create', Contact::class);

        return (new ContactResource(Contact::create($request->payload())))->response()->setStatusCode(201);
    }

    public function update(ContactApiRequest $request, Contact $contact): ContactResource
    {
        $this->authorize('update', $contact);

        $contact->update($request->payload());

        return new ContactResource($contact);
    }

    public function destroy(Contact $contact): Response
    {
        $this->authorize('delete', $contact);

        $contact->delete();

        return response()->noContent();
    }
}
