<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\TicketApiRequest;
use App\Http\Resources\V1\TicketResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Helpdesk\Models\Ticket;

class TicketController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Ticket::class);

        $tickets = Ticket::query()->search($request->string('q')->toString())
            ->status($request->string('status')->toString() ?: null)
            ->assignedTo($request->input('assignee_id'))
            ->forContact($request->input('contact_id'))
            ->latest('id')->paginate($this->perPage($request))->withQueryString();

        return TicketResource::collection($tickets);
    }

    public function show(Ticket $ticket): TicketResource
    {
        $this->authorize('view', $ticket);

        return new TicketResource($ticket);
    }

    public function store(TicketApiRequest $request): JsonResponse
    {
        $this->authorize('create', Ticket::class);

        return (new TicketResource(Ticket::create($request->payload())))->response()->setStatusCode(201);
    }

    public function update(TicketApiRequest $request, Ticket $ticket): TicketResource
    {
        $this->authorize('update', $ticket);

        $ticket->update($request->payload());

        return new TicketResource($ticket);
    }
}
