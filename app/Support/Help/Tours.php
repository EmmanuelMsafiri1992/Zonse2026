<?php

namespace App\Support\Help;

use App\Models\User;
use App\Models\Workspace;

/**
 * Guided in-app tours. Each tour belongs to one page (route) and walks through it a step at a time,
 * pointing at an element by CSS selector. Steps whose element is missing or hidden are skipped,
 * and a step without a target shows in the middle of the screen.
 *
 * A tour starts by itself the first time someone opens its page, and can be taken again from the help menu.
 * Finishing or closing it is remembered on the user (users.help_tours: key => "completed" | "dismissed").
 */
class Tours
{
    public const TOURS = [
        'welcome' => [
            'route' => 'dashboard',
            'title' => 'Welcome tour',
            'steps' => [
                ['title' => 'Welcome to your workspace', 'text' => 'Here is a one-minute look around. Use the arrow keys or the buttons to move, and Esc to close.'],
                ['target' => '.z-sidebar .z-nav', 'title' => 'Your apps', 'text' => 'Every app you switch on appears in this menu, grouped by what it does.'],
                ['target' => '#z-global-search', 'title' => 'Search anything', 'text' => 'Find customers, invoices and records from anywhere. Press / to jump here.'],
                ['target' => '.z-workspace-switch', 'title' => 'Workspaces', 'text' => 'Run more than one business? Switch between them here, or start a new one.'],
                ['target' => '[data-tour="help"]', 'title' => 'Help is always here', 'text' => 'Guides for the page you are on, the full help centre, this tour, and what is new.'],
                ['target' => '[data-tour="add-apps"]', 'title' => 'Add more apps', 'text' => 'Switch on the apps your business needs. You can turn them off again at any time.', 'can' => 'manage-workspace'],
            ],
        ],
        'contacts' => [
            'route' => 'contacts.index',
            'module' => 'contacts',
            'title' => 'Contacts tour',
            'steps' => [
                ['title' => 'Everyone you do business with', 'text' => 'Customers, suppliers and leads live here and are shared by all your apps.'],
                ['target' => '[data-tour="contact-types"]', 'title' => 'Filter by type', 'text' => 'Jump straight to customers, suppliers or leads.'],
                ['target' => '[data-tour="new-contact"]', 'title' => 'Add a contact', 'text' => 'Add people one at a time here.'],
                ['target' => '[data-tour="import"]', 'title' => 'Bring your list across', 'text' => 'Upload a spreadsheet or an export from QuickBooks, Sage or Xero instead.', 'can' => 'manage-workspace'],
            ],
        ],
        'invoices' => [
            'route' => 'invoices.index',
            'module' => 'invoicing',
            'title' => 'Invoices tour',
            'steps' => [
                ['title' => 'Get paid faster', 'text' => 'Create invoices, send them by email or WhatsApp, and record payments as they arrive.'],
                ['target' => '[data-tour="invoice-stats"]', 'title' => 'What is owed', 'text' => 'See what is outstanding and overdue at a glance.'],
                ['target' => '[data-tour="new-invoice"]', 'title' => 'New invoice', 'text' => 'Pick a customer, add lines from your price list, and send.'],
            ],
        ],
        'settings' => [
            'route' => 'settings.workspace.edit',
            'title' => 'Settings tour',
            'can' => 'manage-workspace',
            'steps' => [
                ['title' => 'Make it yours', 'text' => 'Your business name, currency, time zone and logo are set here and used on every document.'],
                ['target' => '.z-sidebar .z-nav', 'title' => 'More settings', 'text' => 'Team, apps, billing, branding, tax reporting and data import are in the Settings menu.'],
            ],
        ],
    ];

    /**
     * The tour for this page that the user can see, with its steps filtered by permission.
     *
     * @return array{key: string, title: string, steps: list<array{target?: string, title: string, text: string}>}|null
     */
    public static function forRoute(?string $routeName, User $user, ?Workspace $workspace): ?array
    {
        foreach (self::TOURS as $key => $tour) {
            if ($tour['route'] === $routeName) {
                return self::available($key, $user, $workspace);
            }
        }

        return null;
    }

    /** @return array{key: string, title: string, steps: list<array{target?: string, title: string, text: string}>}|null */
    public static function available(string $key, User $user, ?Workspace $workspace): ?array
    {
        $tour = self::TOURS[$key] ?? null;
        if (! $tour || ! $workspace) {
            return null;
        }
        if (isset($tour['module']) && ! $workspace->hasModule($tour['module'])) {
            return null;
        }
        if (isset($tour['can']) && ! $user->can($tour['can'])) {
            return null;
        }
        $steps = array_values(array_map(
            fn (array $step) => array_diff_key($step, ['can' => true]),
            array_filter($tour['steps'], fn (array $step) => ! isset($step['can']) || $user->can($step['can'])),
        ));

        return ['key' => $key, 'title' => $tour['title'], 'steps' => $steps];
    }

    /** Whether the tour should start by itself: the user has neither finished nor closed it. */
    public static function isPending(string $key, User $user): bool
    {
        return ! isset(($user->help_tours ?? [])[$key]);
    }

    public static function record(User $user, string $key, string $outcome): void
    {
        $user->forceFill(['help_tours' => array_merge($user->help_tours ?? [], [$key => $outcome])])->save();
    }

    public static function reset(User $user, string $key): void
    {
        $tours = $user->help_tours ?? [];
        unset($tours[$key]);
        $user->forceFill(['help_tours' => $tours ?: null])->save();
    }
}
