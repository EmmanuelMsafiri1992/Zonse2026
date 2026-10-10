@extends('layouts.app')
@section('title', 'Document templates')
@section('content')
    <x-page-header title="Document templates" sub="Design letters, certificates and receipts once. Print them filled in for any contact, payment or record."
                   :crumbs="['Settings' => route('settings.workspace.edit'), 'Document templates']">
        <a href="{{ route('settings.document-templates.create') }}" class="btn btn-primary"><x-icon name="plus" /> New template</a>
    </x-page-header>

    <div class="card">
        @if($templates->isEmpty())
            <div class="card-body">
                @php($exampleTag = '{'.'{ contact.name }'.'}')
                <x-empty icon="file-text" title="No templates yet"
                         text="Start from a letter, certificate or receipt. Tags like {{ $exampleTag }} are filled in when you print." />
            </div>
        @else
            <div class="z-table-wrap">
                <table class="table z-table align-middle mb-0">
                    <thead><tr><th>Template</th><th>Filled in from</th><th>Printed</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    @foreach($templates as $template)
                        <tr>
                            <td class="z-row-title">
                                <a href="{{ route('settings.document-templates.edit', $template) }}">{{ $template->name }}</a>
                                <div class="fs-8 text-muted">{{ $template->kindLabel() }}</div>
                            </td>
                            <td class="fs-7">{{ $template->subjectLabel() }}</td>
                            <td class="fs-7">{{ number_format($template->generated_count) }}</td>
                            <td><x-pill :status="$template->is_active ? 'active' : 'inactive'">{{ $template->is_active ? 'In use' : 'Off' }}</x-pill></td>
                            <td class="text-end">
                                @if($template->subject === 'none' && $template->is_active)
                                    <a href="{{ route('documents.show', $template) }}" target="_blank" class="btn btn-sm btn-white" data-no-ajax><x-icon name="printer" class="zi zi-sm" /> Print</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
