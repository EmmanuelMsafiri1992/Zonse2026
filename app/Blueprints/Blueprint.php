<?php

namespace App\Blueprints;

/**
 * A data-driven app: one catalogue module key, a handful of entities, and
 * the generic record screens that come with them.
 *
 * Compact definition (see app/Blueprints/definitions/*.php):
 *   'farm' => ['Farm & crops', 'tractor', 'Fields, crops, seasons and yields.', [
 *       'fields' => ['Field', 'Field name', 'active,fallow', ['size_ha:number|Size (ha)', 'crop', ...]],
 *   ], ['depends' => ['invoicing'], 'logic' => FarmLogic::class]],
 *
 * The optional fifth element lists modules the app needs and an AppLogic class that
 * adds behaviour (billing, actions, reports) on top of the generic screens.
 */
class Blueprint
{
    /** @var array<string, Entity> */
    public array $entities = [];

    protected ?AppLogic $logicInstance = null;

    /**
     * @param  array<string, Entity>  $entities
     * @param  list<string>  $depends
     * @param  class-string<AppLogic>|null  $logicClass
     */
    public function __construct(
        public string $key,
        public string $suite,
        public string $name,
        public string $icon,
        public string $description,
        array $entities,
        public array $depends = [],
        public ?string $logicClass = null,
    ) {
        $this->entities = $entities;
    }

    /** @param  array<int, mixed>  $spec */
    public static function fromArray(string $key, string $suite, array $spec): static
    {
        [$name, $icon, $description, $entitySpecs] = $spec;
        $options = $spec[4] ?? [];

        $entities = [];
        foreach ($entitySpecs as $entityKey => $entitySpec) {
            $entities[$entityKey] = Entity::fromArray($entityKey, $key, $icon, $entitySpec);
        }

        return new static($key, $suite, $name, $icon, $description, $entities, $options['depends'] ?? [], $options['logic'] ?? null);
    }

    /** The behaviour layer for this app (a no-op AppLogic when the app has none). */
    public function logic(): AppLogic
    {
        return $this->logicInstance ??= app($this->logicClass ?? AppLogic::class)->forApp($this);
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
