@extends('layouts.app')
@section('title', 'Automations')
@section('content')
    <x-page-header title="Automations" sub="Let Zonseob do the routine follow-ups: when something happens, it can alert people, make a task, email or text the customer, or update the record." :crumbs="['Settings' => route('settings.workspace.edit'), 'Automations']">
        <a href="{{ route('settings.automations.create') }}" class="btn btn-primary"><x-icon name="plus" /> New automation</a>
    </x-page-header>

    <div class="card mb-4">
        @if($automations->isEmpty())
            <div class="card-body"><x-empty icon="zap" title="No automations yet" text="Start from one of the ideas below, or make your own." /></div>
        @else
            <div class="list-group list-group-flush">
                @foreach($automations as $automation)
                    <div class="list-group-item d-flex gap-3 align-items-center">
                        <x-icon name="zap" class="zi zi-lg {{ $automation->is_active ? 'text-warning' : 'text-muted' }}" />
                        <div class="flex-grow-1 min-w-0">
                            <a href="{{ route('settings.automations.edit', $automation) }}" class="fw-600 text-body">{{ $automation->name }}</a>
                            <div class="fs-8 text-muted">
                                When {{ mb_strtolower($automation->triggerLabel()) }}{{ $automation->conditions ? ' (with '.count($automation->conditions).' '.Str::plural('condition', count($automation->conditions)).')' : '' }} → {{ mb_strtolower($automation->summary()) }}
                                · {{ $automation->run_count ? 'ran '.$automation->run_count.' '.Str::plural('time', $automation->run_count).', last '.$automation->last_run_at?->diffForHumans() : 'not run yet' }}
                            </div>
                        </div>
                        @if($automation->failed_count)
                            <x-pill status="danger">{{ $automation->failed_count }} with problems this week</x-pill>
                        @endif
                        <form method="POST" action="{{ route('settings.automations.toggle', $automation) }}">
                            @csrf
                            <button class="btn btn-sm {{ $automation->is_active ? 'btn-soft-success' : 'btn-white' }}" title="Switch {{ $automation->is_active ? 'off' : 'on' }}">
                                <x-icon :name="$automation->is_active ? 'power' : 'power-off'" /> {{ $automation->is_active ? 'On' : 'Off' }}
                            </button>
                        </form>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <h6 class="text-muted text-uppercase fs-8 fw-600 mb-2">Ideas to start from</h6>
    <div class="row g-3">
        @foreach($recipes as $key => $recipe)
            <div class="col-md-6 col-xl-3">
                <a href="{{ route('settings.automations.create', ['recipe' => $key]) }}" class="card h-100 text-body text-decoration-none">
                    <div class="card-body">
                        <div class="fw-600 mb-1">{{ $recipe['name'] }}</div>
                        <div class="fs-7 text-muted">{{ $recipe['blurb'] }}</div>
                    </div>
                </a>
            </div>
        @endforeach
    </div>
@endsection
