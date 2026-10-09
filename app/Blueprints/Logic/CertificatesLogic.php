<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Certificates & verification: every certificate carries a unique verification code, made up when
 * none is given, and is valid from its issue date until it expires or is revoked. A verification
 * check looks its code up and records whether it was verified, not found, or a mismatch because the
 * certificate is revoked or expired.
 */
class CertificatesLogic extends AppLogic
{
    public const CODE_PREFIX = 'ZC-';

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'certificates') {
            $code = strtoupper(trim((string) ($data['verification_code'] ?? '')));
            if ($code !== '' && $this->findByCode($code, $existing)) {
                $errors['data.verification_code'] = 'Code '.$code.' is already on another certificate.';
            }
            if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
                $errors['due_on'] = 'A certificate cannot expire before it is issued.';
            }
            if ($existing?->status === 'revoked' && $payload['status'] === 'issued') {
                $errors['status'] = 'A revoked certificate stays revoked; issue a new one.';
            }

            return $errors;
        }

        if (blank($data['code_checked'] ?? null)) {
            $errors['data.code_checked'] = 'Enter the code to check.';
        }

        return $errors;
    }

    protected function findByCode(string $code, ?Record $except = null): ?Record
    {
        $code = strtoupper(trim($code));

        return $this->records('certificates')->when($except, fn ($query) => $query->whereKeyNot($except->id))->get()
            ->first(fn (Record $certificate) => strtoupper(trim((string) $certificate->value('verification_code'))) === $code);
    }

    public static function outcome(?Record $certificate): string
    {
        if (! $certificate) {
            return 'not_found';
        }
        if ($certificate->status === 'revoked' || ($certificate->due_on && $certificate->due_on->lt(today()))) {
            return 'mismatch';
        }

        return 'verified';
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity === 'certificates') {
            $code = strtoupper(trim((string) $record->value('verification_code')));
            while ($code === '' || $this->findByCode($code, $record->exists ? $record : null)) {
                $code = self::CODE_PREFIX.strtoupper(Str::random(8));
            }
            $checks = $record->exists ? $this->linked('checks', 'certificate', $record)->get() : collect();
            $this->put($record, [
                'verification_code' => $code,
                '_expired' => (bool) $record->due_on?->lt(today()),
                '_checks' => $checks->count(),
                '_last_checked' => $checks->max(fn (Record $check) => $check->occurs_on?->toDateString()),
            ]);

            return;
        }

        $code = strtoupper(trim((string) $record->value('code_checked')));
        $certificate = $this->findByCode($code);
        $record->status = self::outcome($certificate);
        $this->put($record, ['code_checked' => $code, 'certificate' => $certificate?->id, '_holder' => $certificate?->title]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'checks') {
            $this->recalculate($this->parent($record, 'certificate'));
            $this->recalculate($this->previousParent($record, 'certificate'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'checks') {
            $this->recalculate($this->parent($record, 'certificate'));
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'checks') {
            return ['recheck' => ['label' => 'Check again', 'icon' => 'refresh-cw']];
        }

        return $record->status === 'issued' ? ['revoke' => ['label' => 'Revoke', 'icon' => 'ban', 'confirm' => 'Revoke this certificate? Verification checks will fail from now on.']] : [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'recheck') {
            $record->update(['occurs_on' => today()]);

            return 'Code '.$record->value('code_checked').' is '.str_replace('_', ' ', $record->fresh()->status).'.';
        }
        $record->update(['status' => 'revoked']);

        return 'Certificate '.$record->value('verification_code').' revoked.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'certificates') {
            return [];
        }

        $checks = $this->linked('checks', 'certificate', $record)->orderByDesc('occurs_on')->get();
        $valid = $record->status === 'issued' && ! $record->value('_expired');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Certificate', 'icon' => 'award', 'stats' => [
                ['label' => 'Verification code', 'value' => (string) $record->value('verification_code')],
                ['label' => 'Standing', 'value' => $record->status === 'revoked' ? 'Revoked' : ($record->value('_expired') ? 'Expired' : 'Valid'), 'tone' => $valid ? 'success' : 'danger'],
                ['label' => 'Valid until', 'value' => $record->due_on?->format('d M Y') ?? 'No expiry'],
                ['label' => 'Times checked', 'value' => (string) (int) $record->value('_checks')],
                ['label' => 'Last checked', 'value' => $record->value('_last_checked') ? Carbon::parse($record->value('_last_checked'))->format('d M Y') : 'Never'],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Verification checks', 'icon' => 'shield-check', 'empty' => 'Nobody has checked this certificate.',
                'rows' => $checks->take(10)->map(fn (Record $check) => [
                    'label' => $check->title, 'sub' => ($check->value('organisation') ?: 'No organisation').' · '.$check->occurs_on?->format('d M Y'), 'value' => ucfirst(str_replace('_', ' ', $check->status)), 'href' => $check->url(),
                    'tone' => $check->status === 'verified' ? 'success' : 'danger',
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $certificates = $this->records('certificates')->get();
        $checks = $this->records('checks')->orderByDesc('occurs_on')->orderByDesc('id')->get();
        $month = $checks->filter(fn (Record $check) => $check->occurs_on?->isCurrentMonth());
        $expiring = $certificates->where('status', 'issued')->filter(fn (Record $certificate) => $certificate->due_on && $certificate->due_on->between(today(), today()->addDays(30)));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Certificates', 'icon' => 'award', 'stats' => [
                ['label' => 'Valid', 'value' => (string) $certificates->where('status', 'issued')->filter(fn (Record $certificate) => ! $certificate->value('_expired'))->count()],
                ['label' => 'Expiring in 30 days', 'value' => (string) $expiring->count(), 'tone' => $expiring->isNotEmpty() ? 'warning' : null],
                ['label' => 'Revoked', 'value' => (string) $certificates->where('status', 'revoked')->count()],
                ['label' => 'Checks this month', 'value' => (string) $month->count()],
                ['label' => 'Failed checks this month', 'value' => (string) $month->where('status', '!=', 'verified')->count(), 'tone' => $month->where('status', '!=', 'verified')->isNotEmpty() ? 'danger' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Recent checks', 'icon' => 'shield-check', 'empty' => 'No checks yet.',
                'rows' => $checks->take(10)->map(fn (Record $check) => [
                    'label' => $check->value('code_checked').($check->value('_holder') ? ' · '.$check->value('_holder') : ''), 'sub' => $check->title.' · '.$check->occurs_on?->format('d M Y'), 'value' => ucfirst(str_replace('_', ' ', $check->status)), 'href' => $check->url(),
                    'tone' => $check->status === 'verified' ? 'success' : 'danger',
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $certificates = $this->records('certificates')->get();
        $byProgramme = $certificates->groupBy(fn (Record $certificate) => $certificate->value('programme') ?: 'Unknown')->sortKeys()->map(fn ($group, $programme) => [
            $programme, $group->count(), $group->where('status', 'issued')->filter(fn (Record $certificate) => ! $certificate->value('_expired'))->count(), $group->filter(fn (Record $certificate) => $certificate->value('_expired'))->count(), $group->where('status', 'revoked')->count(), $group->sum(fn (Record $certificate) => (int) $certificate->value('_checks')),
        ])->values()->all();

        $checks = $this->dated('checks', $from, $to)->get();
        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($checks) {
            $group = $checks->filter(fn (Record $check) => $check->occurs_on?->format('Y-m') === $month);

            return [$label, $group->count(), $group->where('status', 'verified')->count(), $group->where('status', 'not_found')->count(), $group->where('status', 'mismatch')->count()];
        })->values()->all();

        return [
            ['title' => 'Certificates by programme', 'columns' => ['Programme', 'Issued', 'Valid', 'Expired', 'Revoked', 'Checks'], 'rows' => $byProgramme],
            ['title' => 'Checks by month', 'columns' => ['Month', 'Checks', 'Verified', 'Not found', 'Mismatch'], 'rows' => $byMonth],
        ];
    }
}
