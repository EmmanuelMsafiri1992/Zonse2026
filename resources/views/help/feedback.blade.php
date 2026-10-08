@extends('layouts.app')
@section('title', 'Guide ratings')
@section('content')
    <x-page-header title="Guide ratings" sub="How readers rate each guide, least helpful first. Fix the ones at the top." :crumbs="['Help centre' => route('help.index'), 'Guide ratings']" />

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0"><x-icon name="thumbs-up" /> Ratings</h5></div>
                @if($ratings->isEmpty())
                    <div class="card-body"><x-empty icon="thumbs-up" title="No ratings yet" text="Readers rate a guide at the bottom of each article." /></div>
                @else
                    <div class="table-responsive">
                        <table class="table mb-0 align-middle">
                            <thead><tr><th>Guide</th><th class="text-end">Answers</th><th class="text-end">Helpful</th></tr></thead>
                            <tbody>
                                @foreach($ratings as $row)
                                    @php $share = (int) round($row['helpful'] / max($row['answers'], 1) * 100); @endphp
                                    <tr>
                                        <td><a href="{{ route('help.show', $row['article']) }}">{{ $row['title'] }}</a></td>
                                        <td class="text-end">{{ $row['answers'] }}</td>
                                        <td class="text-end"><span class="fw-600 {{ $share < 50 ? 'text-danger' : 'text-success' }}">{{ $share }}%</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0"><x-icon name="message-square" /> Latest comments</h5></div>
                <div class="card-body">
                    @forelse($comments as $comment)
                        <div class="py-2 {{ $loop->last ? '' : 'border-bottom' }}">
                            <div class="fs-8 text-muted mb-1">
                                <x-icon :name="$comment->helpful ? 'thumbs-up' : 'thumbs-down'" class="zi zi-sm {{ $comment->helpful ? 'text-success' : 'text-danger' }}" />
                                {{ $titles[$comment->article] ?? $comment->article }} · {{ $comment->user?->name }} · {{ $comment->updated_at->diffForHumans() }}
                            </div>
                            <div class="fs-7">{{ $comment->comment }}</div>
                        </div>
                    @empty
                        <p class="text-muted fs-7 mb-0">No comments yet.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
@endsection
