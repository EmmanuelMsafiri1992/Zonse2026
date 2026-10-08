<?php

namespace App\Blueprints;

use Illuminate\Support\Facades\File;

/**
 * Loads every app definition in app/Blueprints/definitions. Each file groups
 * related apps (its name becomes the blueprint's group) and returns
 * [catalogue module key => blueprint spec].
 */
class BlueprintRegistry
{
    /** @var array<string, Blueprint>|null */
    protected ?array $blueprints = null;

    public function __construct(protected ?string $path = null)
    {
        $this->path ??= app_path('Blueprints/definitions');
    }

    /** @return array<string, Blueprint> keyed by module key */
    public function all(): array
    {
        if ($this->blueprints !== null) {
            return $this->blueprints;
        }

        $blueprints = [];
        foreach (File::glob($this->path.'/*.php') as $file) {
            $suite = pathinfo($file, PATHINFO_FILENAME);
            foreach (require $file as $key => $spec) {
                if (isset($blueprints[$key])) {
                    throw new \RuntimeException("Blueprint [{$key}] is defined twice.");
                }
                $blueprints[$key] = Blueprint::fromArray($key, $suite, $spec);
            }
        }

        return $this->blueprints = $blueprints;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    public function get(string $key): ?Blueprint
    {
        return $this->all()[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    public function entity(string $blueprintKey, string $entityKey): ?Entity
    {
        return $this->get($blueprintKey)?->entity($entityKey);
    }

    /** @return array<string, Blueprint> */
    public function forSuite(string $suite): array
    {
        return array_filter($this->all(), fn (Blueprint $b) => $b->suite === $suite);
    }
}
