<?php

namespace App\Blueprints;

/**
 * A data-driven app: one catalogue module key, a handful of entities, and
 * the generic record screens that come with them.
 *
 * Compact definition (see app/Blueprints/definitions/*.php):
 *   'farm' => ['Farm & crops', 'tractor', 'Fields, crops, seasons and yields.', [
 *       'fields' => ['Field', 'Field name', 'active,fallow', ['size_ha:number|Size (ha)', 'crop', ...]],
 *   ]],
 */
class Blueprint
{
    /** @var array<string, Entity> */
    public array $entities = [];

    /** @param  array<string, Entity>  $entities */
    public function __construct(
        public string $key,
        public string $suite,
        public string $name,
        public string $icon,
        public string $description,
        array $entities,
    ) {
        $this->entities = $entities;
    }

    /** @param  array<int, mixed>  $spec */
    public static function fromArray(string $key, string $suite, array $spec): static
    {
        [$name, $icon, $description, $entitySpecs] = $spec;

        $entities = [];
        foreach ($entitySpecs as $entityKey => $entitySpec) {
            $entities[$entityKey] = Entity::fromArray($entityKey, $key, $icon, $entitySpec);
        }

        return new static($key, $suite, $name, $icon, $description, $entities);
    }

    public function entity(string $key): ?Entity
    {
        return $this->entities[$key] ?? null;
    }

    public function primaryEntity(): Entity
    {
        return reset($this->entities);
    }

    /** Entities whose record fields point at the given entity. @return array<string, array<string, Field>> entityKey => fields */
    public function entitiesReferencing(string $entityKey): array
    {
        $result = [];
        foreach ($this->entities as $key => $entity) {
            $fields = array_filter($entity->recordFields(), fn (Field $f) => $f->relatedEntity === $entityKey);
            if ($fields) {
                $result[$key] = $fields;
            }
        }

        return $result;
    }
}
