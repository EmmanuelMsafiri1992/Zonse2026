<?php

namespace Database\Factories;

use App\Models\WebForm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebForm>
 */
class WebFormFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Newsletter sign-up',
            'target' => 'contact',
            'title' => 'Join our mailing list',
            'intro' => 'Hear about offers first.',
            'fields' => [
                ['key' => 'name', 'label' => 'Full name', 'required' => true, 'help' => null],
                ['key' => 'email', 'label' => 'Email address', 'required' => true, 'help' => null],
                ['key' => 'phone', 'label' => 'Phone number', 'required' => false, 'help' => null],
            ],
            'success_message' => 'Thanks, you are on the list.',
            'is_active' => true,
        ];
    }
}
