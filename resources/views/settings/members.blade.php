@extends('layouts.app')
@section('title', 'Team members')
@section('content')
    <x-page-header title="Team members" sub="People who can sign in to this workspace and what they are allowed to do." :crumbs="['Settings' => route('settings.workspace.edit'), 'Team']">
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#inviteModal"><x-icon name="user-plus" /> Invite member</button>
    </x-page-header>

    <div class="card mb-4">
        <div class="card-header"><h5 class="card-title">Members <span class="badge rounded-pill z-soft-primary ms-1">{{ $members->count() }}</span></h5></div>
        <div class="z-table-wrap">
            <table class="table z-table">
                <thead><tr><th>Person</th><th>Role</th><th>Branch</th><th>Joined</th><th></th></tr></thead>
                <tbody>
                @foreach($members as $m)
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <span class="z-avatar z-avatar-sm">{{ $m->initials }}</span>
                                <div><div class="z-row-title">{{ $m->name }}</div><div class="z-row-sub">{{ $m->email }}</div></div>
                            </div>
                        </td>
                        <td><x-pill :status="$m->pivot->role === 'owner' ? 'approved' : 'primary'">{{ $roles[$m->pivot->role] ?? $m->pivot->role }}</x-pill></td>
                        <td>{{ $branches->firstWhere('id', $m->pivot->branch_id)?->name ?? 'All' }}</td>
                        <td class="text-muted fs-7">{{ optional($m->pivot->joined_at ? \Carbon\Carbon::parse($m->pivot->joined_at) : null)?->toFormattedDateString() }}</td>
                        <td class="text-end">
                            @if($m->pivot->role !== 'owner')
                                <div class="z-row-actions d-inline-flex gap-1">
                                    <button type="button" class="btn btn-sm btn-icon btn-soft-secondary" data-bs-toggle="modal" data-bs-target="#editMember{{ $m->id }}" title="Edit"><x-icon name="pencil" class="zi zi-sm" /></button>
                                    <form method="POST" action="{{ route('settings.members.destroy', $m) }}" onsubmit="return confirm('Remove {{ $m->name }} from this workspace?')">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-icon btn-soft-danger" title="Remove"><x-icon name="trash-2" class="zi zi-sm" /></button>
                                    </form>
                                </div>
                                @push('modals')
                                    <div class="modal fade" id="editMember{{ $m->id }}" tabindex="-1">
                                        <div class="modal-dialog modal-dialog-centered">
                                            <form class="modal-content" method="POST" action="{{ route('settings.members.update', $m) }}">
                                                @csrf @method('PATCH')
                                                <div class="modal-header"><h5 class="modal-title">Edit {{ $m->name }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                                                <div class="modal-body">
                                                    <x-form.select name="role" label="Role" :options="array_diff_key($roles, ['owner' => 1])" :value="$m->pivot->role" required />
                                                    <x-form.select name="branch_id" label="Branch" :options="$branches->pluck('name', 'id')->all()" :value="$m->pivot->branch_id" placeholder="All branches" />
                                                    <x-form.input name="job_title" label="Job title" :value="$m->pivot->job_title" />
                                                </div>
                                                <div class="modal-footer"><button type="button" class="btn btn-white" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save</button></div>
                                            </form>
                                        </div>
                                    </div>
                                @endpush
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h5 class="card-title">Pending invitations</h5></div>
        @if($invitations->isEmpty())
            <div class="card-body"><p class="text-muted fs-7 mb-0">No pending invitations.</p></div>
        @else
            <div class="z-table-wrap">
                <table class="table z-table">
                    <thead><tr><th>Email</th><th>Role</th><th>Invite link</th><th>Expires</th><th></th></tr></thead>
                    <tbody>
                    @foreach($invitations as $inv)
                        <tr>
                            <td class="z-row-title">{{ $inv->email }}</td>
                            <td><x-pill status="pending">{{ $roles[$inv->role] ?? $inv->role }}</x-pill></td>
                            <td><input type="text" class="form-control form-control-sm" readonly value="{{ $inv->url() }}" onclick="this.select()" style="max-width:320px"></td>
                            <td class="text-muted fs-7">{{ $inv->expires_at->diffForHumans() }}</td>
                            <td class="text-end">
                                <form method="POST" action="{{ route('settings.members.invitations.destroy', $inv) }}">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-sm btn-icon btn-soft-danger" title="Cancel invitation"><x-icon name="x" class="zi zi-sm" /></button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    @push('modals')
        <div class="modal fade" id="inviteModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" method="POST" action="{{ route('settings.members.invite') }}">
                    @csrf
                    <div class="modal-header"><h5 class="modal-title">Invite a team member</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <x-form.input name="email" label="Email address" type="email" required />
                        <x-form.select name="role" label="Role" :options="array_diff_key($roles, ['owner' => 1])" value="member" required help="Admins can change settings, billing and members. Managers run day-to-day apps. Viewers are read-only." />
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-white" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary"><x-icon name="send" /> Send invitation</button></div>
                </form>
            </div>
        </div>
    @endpush
@endsection
