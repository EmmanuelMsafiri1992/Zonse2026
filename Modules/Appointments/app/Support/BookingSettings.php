<?php

namespace Modules\Appointments\Support;

use App\Models\Workspace;

/**
 * Workspace-level booking preferences with sensible defaults.
 */
class BookingSettings
{
    public const DEFAULTS = [
        'slot_minutes' => 30,
        'default_duration' => 30,
        'day_start' => '08:00',
        'day_end' => '17:00',
        'working_days' => [1, 2, 3, 4, 5],
        'auto_confirm' => false,
        'booking_note' => null,
    ];

    /**
     * @return array{slot_minutes: int, default_duration: int, day_start: string, day_end: string, working_days: list<int>, auto_confirm: bool, booking_note: ?string}
     */
    public static function for(Workspace $workspace): array
    {
        $workingDays = $workspace->setting('appointments.working_days', self::DEFAULTS['working_days']);

        return [
            'slot_minutes' => (int) $workspace->setting('appointments.slot_minutes', self::DEFAULTS['slot_minutes']),
            'default_duration' => (int) $workspace->setting('appointments.default_duration', self::DEFAULTS['default_duration']),
            'day_start' => (string) $workspace->setting('appointments.day_start', self::DEFAULTS['day_start']),
            'day_end' => (string) $workspace->setting('appointments.day_end', self::DEFAULTS['day_end']),
            'working_days' => array_values(array_map('intval', is_array($workingDays) ? $workingDays : self::DEFAULTS['working_days'])),
            'auto_confirm' => (bool) $workspace->setting('appointments.auto_confirm', self::DEFAULTS['auto_confirm']),
            'booking_note' => $workspace->setting('appointments.booking_note'),
        ];
    }

    /** @param  array<string, mixed>  $values */
    public static function save(Workspace $workspace, array $values): void
    {
        foreach (array_intersect_key($values, self::DEFAULTS) as $key => $value) {
            $workspace->putSetting('appointments.'.$key, $value);
        }
    }

    /** @return array<int, string> */
    public static function weekdays(): array
    {
        return [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
    }
}
