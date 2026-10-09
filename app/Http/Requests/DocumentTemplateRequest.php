<?php

namespace App\Http\Requests;

use App\Models\DocumentTemplate;
use App\Support\DocumentTemplates;
use App\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Starting a document template from a starter, or saving it from the designer. */
class DocumentTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        if (! $this->route('documentTemplate')) {
            $subjects = collect(DocumentTemplates::subjects(app(WorkspaceContext::class)->getOrFail()))->flatMap(fn (array $group) => array_keys($group))->all();

            return [
                'name' => ['required', 'string', 'max:120'],
                'starter' => ['required', Rule::in(array_keys(DocumentTemplates::starters()))],
                'subject' => ['required', 'string', Rule::in($subjects)],
            ];
        }

        return self::designRules();
    }

    /**
     * What the designer may send, shared with the live preview.
     *
     * @return array<string, list<mixed>>
     */
    public static function designRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in(array_keys(DocumentTemplates::KINDS))],
            'heading' => ['nullable', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:10000'],
            'paper' => ['required', Rule::in(array_keys(DocumentTemplates::PAPERS))],
            'orientation' => ['required', Rule::in(array_keys(DocumentTemplates::ORIENTATIONS))],
            'font' => ['required', Rule::in(array_keys(DocumentTemplates::FONTS))],
            'align' => ['required', Rule::in(array_keys(DocumentTemplates::ALIGNS))],
            'border' => ['required', Rule::in(array_keys(DocumentTemplates::BORDERS))],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'show_logo' => ['boolean'],
            'signatures' => ['nullable', 'array', 'max:'.DocumentTemplates::MAX_SIGNATURES],
            'signatures.*' => ['nullable', 'string', 'max:60'],
            'footer' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'subject.in' => 'Pick what the document is filled in from.',
            'signatures.max' => 'A document can have up to '.DocumentTemplates::MAX_SIGNATURES.' signature lines.',
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        /** @var DocumentTemplate|null $template */
        $template = $this->route('documentTemplate');
        if (! $template) {
            return [];
        }

        return [function (Validator $validator) use ($template) {
            $unknown = DocumentTemplates::unknownTags($template->subject, $this->input('heading'), $this->input('body'), $this->input('footer'), ...array_filter((array) $this->input('signatures', []), 'is_string'));
            if ($unknown !== []) {
                $validator->errors()->add('body', 'These tags are not available here: {{ '.implode(' }}, {{ ', $unknown).' }}. Pick tags from the list.');
            }
        }];
    }

    /**
     * The attributes to save.
     *
     * @return array<string, mixed>
     */
    public function template(): array
    {
        if (! $this->route('documentTemplate')) {
            $starter = DocumentTemplates::starters()[$this->string('starter')->toString()];
            $subject = $this->string('subject')->toString();
            $values = $starter['values'];

            // A starter written for one subject keeps only its layout when used for another.
            if ($subject !== $starter['subject']) {
                $values['body'] = $subject === 'none' || $starter['subject'] === 'none'
                    ? 'Write here. Use the tags on the right to fill in details.'
                    : self::stripTags($values['body'], $subject);
                $values['heading'] = self::stripTags($values['heading'], $subject);
                $values['footer'] = self::stripTags($values['footer'], $subject);
                $values['signatures'] = array_values(array_filter(array_map(fn (string $role) => self::stripTags($role, $subject), $values['signatures'])));
            }

            return array_merge($values, [
                'name' => $this->string('name')->trim()->toString(),
                'kind' => $starter['kind'],
                'subject' => $subject,
            ]);
        }

        return self::design($this->validated());
    }

    /**
     * Validated designer input as model attributes.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function design(array $data): array
    {
        return [
            'name' => trim($data['name']),
            'kind' => $data['kind'],
            'heading' => filled($data['heading'] ?? null) ? trim($data['heading']) : null,
            'body' => rtrim($data['body']),
            'paper' => $data['paper'],
            'orientation' => $data['orientation'],
            'font' => $data['font'],
            'align' => $data['align'],
            'border' => $data['border'],
            'color' => strtolower($data['color']),
            'show_logo' => (bool) ($data['show_logo'] ?? false),
            'signatures' => array_values(array_filter(array_map(fn (?string $role) => trim((string) $role), $data['signatures'] ?? []))),
            'footer' => filled($data['footer'] ?? null) ? trim($data['footer']) : null,
            'is_active' => (bool) ($data['is_active'] ?? false),
        ];
    }

    /**
     * Drops the tags the new subject does not offer, so the starter still saves cleanly. A person's
     * name becomes the record's title, so a certificate for a pupil names the pupil, not the payer.
     */
    protected static function stripTags(?string $text, string $subject): ?string
    {
        if ($text === null) {
            return null;
        }
        $known = DocumentTemplates::tagKeys($subject);

        return preg_replace_callback(DocumentTemplates::TAG_PATTERN, function (array $match) use ($known) {
            $tag = strtolower($match[1]);

            return match (true) {
                $tag === 'contact.name' && in_array('record.title', $known, true) => '{{ record.title }}',
                in_array($tag, $known, true) => $match[0],
                default => '',
            };
        }, $text);
    }
}
