<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Tenancy\WorkspaceContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * Lets the workspace owner download everything the workspace holds as CSV
 * files in one ZIP (data portability under GDPR / POPIA).
 */
class DataExportController extends Controller
{
    /** Tables left out: secrets, access-control internals and rows exported separately. */
    public const EXCLUDED_TABLES = [
        'settings', 'sequences', 'invitations', 'model_has_permissions', 'model_has_roles', 'roles', 'workspace_user', 'personal_access_tokens',
    ];

    /** Columns never written to an export. */
    public const EXCLUDED_COLUMNS = ['password', 'remember_token', 'token', 'two_factor_secret', 'two_factor_recovery_codes', 'secret', 'api_key'];

    public function __construct(protected WorkspaceContext $context) {}

    public function index(Request $request): View
    {
        $workspace = $this->context->getOrFail();

        return view('settings.data-export', [
            'canExport' => $this->canExport($request),
            'tables' => $this->tables(),
            'lastExport' => Activity::query()->where('workspace_id', $workspace->id)
                ->where('log_name', 'data')->where('event', 'workspace-exported')->with('causer')->latest('id')->first(),
        ]);
    }

    public function store(Request $request): BinaryFileResponse
    {
        abort_unless($this->canExport($request), 403, 'Only the workspace owner can export all data.');
        $workspace = $this->context->getOrFail();

        $directory = storage_path('app/private/exports');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/'.$workspace->slug.'-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.zip';

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $profile = collect($workspace->only(['name', 'slug', 'type', 'email', 'phone', 'website', 'address', 'city', 'country_code', 'currency_code', 'timezone', 'tax_number', 'created_at']));
        $zip->addFromString('workspace.csv', $this->csv([$profile->map(fn ($v) => (string) $v)->all()]));

        $members = $workspace->members()->orderBy('name')->get()->map(fn ($user) => [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
            'role' => $user->pivot->role, 'joined_at' => (string) $user->pivot->joined_at,
        ]);
        $zip->addFromString('members.csv', $this->csv($members->all()));

        $counts = [];
        foreach ($this->tables() as $table) {
            $rows = DB::table($table)->where('workspace_id', $workspace->id)->orderBy(Schema::hasColumn($table, 'id') ? 'id' : 'workspace_id')->get()
                ->map(fn ($row) => array_diff_key((array) $row, array_flip(self::EXCLUDED_COLUMNS)))->all();
            $counts[$table] = count($rows);
            $zip->addFromString($table.'.csv', $this->csv($rows));
        }

        $audit = DB::table('activity_log')->where('workspace_id', $workspace->id)->orderBy('id')
            ->get(['created_at', 'log_name', 'event', 'description', 'subject_type', 'subject_id', 'causer_id'])
            ->map(fn ($row) => (array) $row)->all();
        $zip->addFromString('audit_log.csv', $this->csv($audit));

        $zip->addFromString('README.txt', implode("\n", [
            'Zonseo data export for '.$workspace->name,
            'Created '.now()->toDayDateTimeString().' by '.$request->user()->name,
            '',
            'Each CSV file holds one kind of record. Columns ending in _id point at the "id" column of the related file.',
            'Payment gateway keys, SMS keys and passwords are never included.',
        ]));
        $zip->close();

        Audit::log('data', 'workspace-exported', 'Exported all workspace data', properties: ['rows' => array_sum($counts)]);

        return response()->download($path, 'zonseo-'.$workspace->slug.'-export-'.now()->format('Y-m-d').'.zip')->deleteFileAfterSend();
    }

    /** @return list<string> */
    protected function tables(): array
    {
        return collect(Schema::getTableListing(Schema::getCurrentSchemaListing(), false))
            ->reject(fn (string $table) => in_array($table, self::EXCLUDED_TABLES, true) || $table === 'activity_log')
            ->filter(fn (string $table) => Schema::hasColumn($table, 'workspace_id'))
            ->sort()->values()->all();
    }

    protected function canExport(Request $request): bool
    {
        $user = $request->user();

        return $user->is_super_admin || $user->isOwnerOf($this->context->getOrFail());
    }

    /** @param  list<array<string, mixed>>  $rows */
    protected function csv(array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        if ($rows !== []) {
            fputcsv($out, array_keys($rows[0]));
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($value) => is_scalar($value) || $value === null ? $value : json_encode($value), $row));
            }
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }
}
