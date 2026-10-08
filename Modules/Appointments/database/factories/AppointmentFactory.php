<?php

namespace Modules\Appointments\Database\Factories;

use App\Models\Workspace;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Appointments\Models\Appointment;
use Modules\Contacts\Models\Contact;

/** @extends Factory<Appointment> */
class AppointmentFactory extends Factory
{
    protected $model = Appointment::class;

    public function definition(): array
    {
        $start = now()->addDays(fake()->numberBetween(0, 7))->setTime(fake()->numberBetween(8, 16), fake()->randomElement([0, 30]));

        return [
            'workspace_id' => Workspace::factory(),
            'contact_id' => fn (array $attrs) => Contact::factory()->for(Workspace::find($attrs['workspace_id']))->customer(),
            'status' => 'scheduled',
            'starts_at' => $start,
            'ends_at' => $start->copy()->addMinutes(30),
            'price' => null,
        ];
    }

    public function at(string $startsAt, int $minutes = 30): static
    {
        $start = Carbon::parse($startsAt);

        return $this->state(['starts_at' => $start, 'ends_at' => $start->copy()->addMinutes($minutes)]);
    }

    public function confirmed(): static
    {
        return $this->state(['status' => 'confirmed', 'confirmed_at' => now()]);
    }

    public function completed(): static
    {
        return $this->state(['status' => 'completed', 'completed_at' => now()]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => 'cancelled', 'cancelled_at' => now()]);
    }
}
