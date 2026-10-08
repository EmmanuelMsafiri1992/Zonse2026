<?php

namespace App\Blueprints;

use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * One input on a blueprint entity. Parsed from the compact definition
 * syntax "key:type=opt1,opt2|Label*" where everything after the key is
 * optional: type defaults to text, the label is derived from the key and a
 * trailing "*" makes the field required.
 */
class Field
{
    public const TYPES = ['text', 'textarea', 'number', 'money', 'date', 'datetime', 'time', 'select', 'checkbox', 'email', 'phone', 'url', 'record', 'user'];

    /** @param  array<string, string>  $options */
    public function __construct(
        public string $key,
        public string $label,
        public string $type = 'text',
        public array $options = [],
        public bool $required = false,
        public ?string $relatedEntity = null,
        public ?string $help = null,
    ) {}

    public static function parse(string $spec): static
    {
        $label = null;
        if (str_contains($spec, '|')) {
            [$spec, $label] = explode('|', $spec, 2);
        }
        $required = str_ends_with($label ?? $spec, '*');
        $spec = rtrim($spec, '*');
        $label = $label !== null ? rtrim($label, '*') : null;

        $type = 'text';
        $raw = null;
        $key = $spec;
        if (str_contains($spec, ':')) {
            [$key, $type] = explode(':', $spec, 2);
            if (str_contains($type, '=')) {
                [$type, $raw] = explode('=', $type, 2);
            }
        }

        if (! in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Unknown field type [{$type}] for field [{$key}].");
        }

        $options = [];
        $related = null;
        if ($type === 'select') {
            foreach (array_filter(explode(',', (string) $raw)) as $option) {
                $options[$option] = Entity::statusLabel($option);
            }
        } elseif ($type === 'record') {
            $related = $raw;
        }

        return new static(
            key: $key,
            label: $label ?? Str::ucfirst(str_replace('_', ' ', $key)),
            type: $type,
            options: $options,
            required: $required,
            relatedEntity: $related,
        );
    }

    public function isTextual(): bool
    {
        return in_array($this->type, ['text', 'textarea', 'email', 'phone', 'url', 'select'], true);
    }

    /** @return array<int, mixed> */
    public function rules(): array
    {
        $rules = [$this->required ? 'required' : 'nullable'];

        $rules = array_merge($rules, match ($this->type) {
            'text', 'phone' => ['string', 'max:255'],
            'textarea' => ['string', 'max:20000'],
            'number', 'money' => ['numeric'],
            'date', 'datetime' => ['date'],
            'time' => ['date_format:H:i'],
            'select' => [Rule::in(array_keys($this->options))],
            'checkbox' => ['boolean'],
            'email' => ['email', 'max:255'],
            'url' => ['url', 'max:500'],
            'record', 'user' => ['integer'],
        });

        return $rules;
    }

    /** Normalise a submitted value before it is stored in the data JSON. */
    public function cast(mixed $value): mixed
    {
        if ($value === '' || $value === null) {
            return $this->type === 'checkbox' ? false : null;
        }

        return match ($this->type) {
            'number', 'money' => (float) $value,
            'checkbox' => (bool) $value,
            'record', 'user' => (int) $value,
            default => is_string($value) ? trim($value) : $value,
        };
    }
}
