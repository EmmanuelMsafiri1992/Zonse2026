<?php

namespace App\Support\Fiscal;

use App\Models\Workspace;

/**
 * A workspace's tax-authority reporting set-up, kept under the "fiscal" settings key. Only the
 * test gateway exists until a business brings certified device credentials from its authority.
 */
class FiscalSettings
{
    public const MODES = ['test' => 'Test (nothing reaches the tax authority)'];

    /** @var array{enabled: bool, authority: ?string, taxpayer_id: ?string, device_id: ?string, mode: string, simulate_outage: bool} */
    public const DEFAULTS = [
        'enabled' => false, 'authority' => null, 'taxpayer_id' => null, 'device_id' => null, 'mode' => 'test', 'simulate_outage' => false,
    ];

    /** @return array{enabled: bool, authority: ?string, taxpayer_id: ?string, device_id: ?string, mode: string, simulate_outage: bool} */
    public static function for(Workspace $workspace): array
    {
        $stored = (array) $workspace->setting('fiscal', []);
        $settings = array_merge(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));

        $settings['authority'] = Authorities::get($settings['authority']) ? $settings['authority'] : null;
        $settings['mode'] = array_key_exists($settings['mode'], self::MODES) ? $settings['mode'] : 'test';
        $settings['enabled'] = (bool) $settings['enabled'] && $settings['authority'] !== null && filled($settings['taxpayer_id']);
        $settings['simulate_outage'] = (bool) $settings['simulate_outage'];
        $settings['taxpayer_id'] = filled($settings['taxpayer_id']) ? (string) $settings['taxpayer_id'] : null;
        $settings['device_id'] = filled($settings['device_id']) ? (string) $settings['device_id'] : null;

        return $settings;
    }

    /** @param  array<string, mixed>  $values */
    public static function save(Workspace $workspace, array $values): void
    {
        $settings = (array) $workspace->settings;
        foreach (array_intersect_key($values, self::DEFAULTS) as $key => $value) {
            data_set($settings, 'fiscal.'.$key, $value);
        }
        $workspace->forceFill(['settings' => $settings])->save();
    }

    public static function enabled(Workspace $workspace): bool
    {
        return self::for($workspace)['enabled'];
    }
}
