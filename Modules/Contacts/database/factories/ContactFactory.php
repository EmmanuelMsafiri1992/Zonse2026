<?php

namespace Modules\Contacts\Database\Factories;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Contacts\Models\Contact;

/** @extends Factory<Contact> */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    public function definition(): array
    {
        $kind = fake()->boolean(30) ? 'company' : 'person';

        return [
            'workspace_id' => Workspace::factory(),
            'type' => fake()->randomElement(['customer', 'customer', 'customer', 'supplier', 'lead']),
            'kind' => $kind,
            'name' => fake()->name(),
            'company_name' => $kind === 'company' ? fake()->company() : null,
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'mobile' => fake()->boolean(60) ? fake()->phoneNumber() : null,
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'country_code' => 'ZW',
            'currency_code' => 'USD',
            'tags' => [],
            'is_active' => true,
        ];
    }

    public function customer(): static
    {
        return $this->state(['type' => 'customer']);
    }

    public function supplier(): static
    {
        return $this->state(['type' => 'supplier']);
    }

    public function lead(): static
    {
        return $this->state(['type' => 'lead']);
    }

    public function company(string $name): static
    {
        return $this->state(['kind' => 'company', 'company_name' => $name]);
    }

    public function archived(): static
    {
        return $this->state(['is_active' => false]);
    }
}
