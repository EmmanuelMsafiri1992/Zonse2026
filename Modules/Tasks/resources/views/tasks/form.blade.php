@extends('layouts.app')
@section('title', $task->exists ? 'Edit task' : 'New task')
@section('content')
    <x-page-header :title="$task->exists ? 'Edit task' : 'New task'"
                   :sub="$task->exists ? $task->title : 'Say what needs doing, who does it and by when.'"
                   :crumbs="['Tasks' => route('tasks.index'), $task->exists ? 'Edit' : 'New']" />

    <form method="POST" action="{{ $task->exists ? route('tasks.update', $task) : route('tasks.store') }}">
        @csrf
        @if($task->exists) @method('PUT') @endif
        @if(request('status'))<input type="hidden" name="_return" value="board">@endif

        <div class="row g-3">
            <div class="col-lg-8">
                <div class="card mb-3">
                    <div class="card-body">
                        <x-form.input name="title" label="Task" :value="$task->title" placeholder="e.g. Call Rudo about the follow-up" required autofocus />
                        <x-form.textarea name="description" label="Details (optional)" :value="$task->description" rows="5" placeholder="Anything the person doing it needs to know." />
                    </div>
                </div>
                <x-custom-fields.inputs entity="task" :record="$task" />
            </div>
            <div class="col-lg-4">
                <div class="card mb-3">
                    <div class="card-header"><h5 class="card-title">Plan it</h5></div>
                    <div class="card-body">
                        <x-form.select name="status" label="Status" :options="$statuses" :value="$task->status" required />
                        <x-form.select name="priority" label="Priority" :options="$priorities" :value="$task->priority" required />
                        <x-form.input name="due_date" type="date" label="Due date" :value="$task->due_date?->format('Y-m-d')" />
                        <x-form.select name="assignee_id" label="Assigned to" :options="$members" :value="$task->assignee_id" placeholder="Unassigned" />
                        <x-form.select name="contact_id" label="About a contact" :options="$contacts" :value="$task->contact_id" placeholder="Not linked to a contact" />
                        @if($branches->count() > 1)
                            <x-form.select name="branch_id" label="Branch" :options="$branches" :value="$task->branch_id" placeholder="—" />
                        @endif
                    </div>
                    <div class="card-footer d-flex justify-content-between">
                        <a href="{{ $task->exists ? route('tasks.show', $task) : route('tasks.index') }}" class="btn btn-white">Cancel</a>
                        <button class="btn btn-primary"><x-icon name="check" /> {{ $task->exists ? 'Save changes' : 'Add task' }}</button>
                    </div>
                </div>
            </div>
        </div>
    </form>
@endsection
