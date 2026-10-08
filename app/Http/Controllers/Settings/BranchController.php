<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Support\Audit;
use App\Tenancy\WorkspaceContext;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function __construct(protected WorkspaceContext $context) {}

    public function index()
    {
        return view('settings.branches', [
            'branches' => Branch::orderByDesc('is_default')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $branch = Branch::create($data + ['is_active' => true]);
        $this->applyDefault($branch, $request->boolean('is_default'));

        Audit::log('settings', 'branch-created', "Added the {$branch->name} branch", $branch);

        return back()->with('flash', ['type' => 'success', 'message' => "Branch {$branch->name} added."]);
    }

    public function update(Request $request, Branch $branch)
    {
        $data = $this->validated($request, $branch);
        $branch->update($data + ['is_active' => $request->boolean('is_active', true)]);
        $this->applyDefault($branch, $request->boolean('is_default'));

        Audit::log('settings', 'branch-updated', "Updated the {$branch->name} branch", $branch);

        return back()->with('flash', ['type' => 'success', 'message' => "Branch {$branch->name} updated."]);
    }

    public function destroy(Branch $branch)
    {
        if ($branch->is_default) {
            return back()->withErrors(['branch' => 'Make another branch the default before deleting this one.']);
        }
        $branch->delete();

        Audit::log('settings', 'branch-deleted', "Deleted the {$branch->name} branch");

        return back()->with('flash', ['type' => 'success', 'message' => 'Branch deleted.']);
    }

    protected function validated(Request $request, ?Branch $branch = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'code' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:80'],
        ]);
    }

    protected function applyDefault(Branch $branch, bool $default): void
    {
        if ($default && ! $branch->is_default) {
            Branch::where('id', '!=', $branch->id)->update(['is_default' => false]);
            $branch->forceFill(['is_default' => true])->save();
        }
    }
}
