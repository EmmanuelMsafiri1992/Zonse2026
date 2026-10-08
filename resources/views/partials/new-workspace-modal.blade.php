<div class="modal fade" id="newWorkspaceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('workspaces.store') }}">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">New workspace</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted fs-7">A workspace is a separate company, clinic, school or project with its own data, team and apps. You can switch between them any time.</p>
                <x-form.input name="name" label="Workspace name" placeholder="e.g. Harare Dental Clinic" required />
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-white" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><x-icon name="plus" /> Create &amp; set up</button>
            </div>
        </form>
    </div>
</div>
