<?php

namespace App\Support;

use App\Models\PortalAccess;
use App\Models\Workspace;

/**
 * Settings and session helpers for the client portal: which sections a workspace shows,
 * its welcome message, and which portal access (if any) the visitor is signed in as.
 */
class Portal
{
    /** Sections the portal can show, with the module each one needs (null = always available). */
    public const SECTIONS = [
        'invoices' => ['label' => 'Invoices & quotes', 'icon' => 'receipt', 'module' => 'invoicing'],
        'appointments' => ['label' => 'Appointments', 'icon' => 'calendar-days', 'module' => 'appointments'],
        'requests' => ['label' => 'Support requests', 'icon' => 'life-buoy', 'module' => 'helpdesk'],
        'documents' => ['label' => 'Documents to sign', 'icon' => 'file-signature', 'module' => null],
        'records' => ['label' => 'My records', 'icon' => 'folder-open', 'module' => null],
    ];

    public const WELCOME_MAX = 500;

    /** @return array<string, array{label: string, icon: string, module: ?string}> */
    public function sections(Workspace $workspace): array
    {
        $hidden = (array) $workspace->setting('portal.hidden_sections', []);

        return array_filter(
            self::SECTIONS,
            fn (array $section, string $key) => ! in_array($key, $hidden, true)
                && ($section['module'] === null || $workspace->hasModule($section['module'])),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /** Sections the workspace could switch on, ignoring what the owner has hidden. */
    public function availableSections(Workspace $workspace): array
    {
        return array_filter(self::SECTIONS, fn (array $section) => $section['module'] === null || $workspace->hasModule($section['module']));
    }

    public function shows(Workspace $workspace, string $section): bool
    {
        return array_key_exists($section, $this->sections($workspace));
    }

    public function welcome(Workspace $workspace): ?string
    {
        return $workspace->setting('portal.welcome') ?: null;
    }

    public function sessionKey(Workspace $workspace): string
    {
        return 'portal.'.$workspace->id;
    }

    /** The active portal access the visitor signed in as for this workspace, if any. */
    public function signedIn(Workspace $workspace): ?PortalAccess
    {
        $id = session($this->sessionKey($workspace));
        if (! $id) {
            return null;
        }

        $access = PortalAccess::forWorkspace($workspace)->with('contact')->find($id);

        return $access?->isActive() && $access->contact ? $access : null;
    }

    public function signIn(Workspace $workspace, PortalAccess $access): void
    {
        session()->regenerate();
        session([$this->sessionKey($workspace) => $access->id]);
    }

    public function signOut(Workspace $workspace): void
    {
        session()->forget($this->sessionKey($workspace));
        session()->regenerateToken();
    }
}
