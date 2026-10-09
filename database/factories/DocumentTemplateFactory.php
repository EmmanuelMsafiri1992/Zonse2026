<?php

namespace Database\Factories;

use App\Models\DocumentTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentTemplate>
 */
class DocumentTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Welcome letter',
            'kind' => 'letter',
            'subject' => 'contact',
            'heading' => 'Welcome',
            'body' => "Dear {{ contact.name }},\n\nThank you for choosing **{{ workspace.name }}**.",
            'signatures' => ['Manager'],
            'footer' => 'We look forward to serving you.',
        ];
    }

    public function certificate(): static
    {
        return $this->state([
            'name' => 'Certificate of completion',
            'kind' => 'certificate',
            'heading' => 'Certificate of Completion',
            'body' => "This certifies that\n\n# {{ contact.name }}\n\nhas completed the course.",
            'orientation' => 'landscape',
            'font' => 'serif',
            'align' => 'center',
            'border' => 'double',
            'signatures' => ['Instructor', 'Director'],
        ]);
    }
}
