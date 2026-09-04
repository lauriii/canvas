<?php

declare(strict_types=1);

namespace Drupal\multi_frontend\Schema;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\Core\Url;
use Drupal\multi_frontend\ComponentProducerManager;

/**
 * Publishes props schemas over HTTP.
 *
 * Core validates component props against schemas that live on disk behind a
 * file:// reference and are never served anywhere a front-end tool can fetch
 * them. Publication is the smallest change in the whole proposal and the one
 * with the highest ratio of value to risk: it is what turns a contract into
 * something a developer's toolchain can consume.
 *
 * Schemas are stamped draft-07 rather than the draft-04 core's metadata
 * schema declares. A props schema fragment carries no dialect today, so this
 * is a decision about what to stamp rather than a bug to fix. Draft-07 is
 * what a default Ajv install validates with no extra package, and what
 * contains every keyword this ecosystem already uses.
 */
final class SchemaPublisher {

  public const DIALECT = 'http://json-schema.org/draft-07/schema#';

  public function __construct(
    private readonly ComponentProducerManager $producerManager,
    private readonly ComponentPluginManager $componentManager,
  ) {}

  /**
   * Lists every published schema, for code generation.
   *
   * @return array<string, mixed>
   *   The catalog.
   */

  /**
   * Builds an absolute URL, keeping the cacheability it was generated with.
   *
   * Outbound route and path processors add metadata to a GeneratedUrl --
   * language prefixes and path aliases among them -- and reducing it to a
   * string throws that away, so a cached document can keep a link that is no
   * longer correct.
   */
  private static function url(string $route, array $parameters, ?CacheableMetadata $cacheability): string {
    $generated = Url::fromRoute($route, $parameters)->setAbsolute()->toString(TRUE);
    $cacheability?->addCacheableDependency($generated);
    return $generated->getGeneratedUrl();
  }

  public function catalog(?CacheableMetadata $cacheability = NULL): array {
    $producers = [];
    foreach ($this->producerManager->getDefinitions() as $id => $definition) {
      $producers[] = [
        'producer' => $id,
        'component' => $definition['component'],
        'subject' => $definition['subject'],
        'schema' => self::url('multi_frontend.schema.component', ['producer' => $id], $cacheability),
      ];
    }
    usort($producers, static fn (array $a, array $b): int => strcmp($a['producer'], $b['producer']));
    return [
      '$schema' => self::DIALECT,
      'envelope' => self::url('multi_frontend.schema.envelope', [], $cacheability),
      'producers' => $producers,
    ];
  }

  /**
   * Returns one producer's component props schema.
   *
   * @return array<string, mixed>
   *   A JSON Schema.
   */
  public function componentSchema(string $producer_id, ?CacheableMetadata $cacheability = NULL): array {
    $definition = $this->producerManager->getDefinition($producer_id);
    $component = $this->componentManager->getDefinition($definition['component']);
    $props = $component['props'] ?? ['type' => 'object', 'properties' => []];

    return [
      '$schema' => self::DIALECT,
      '$id' => self::url('multi_frontend.schema.component', ['producer' => $producer_id], $cacheability),
      'title' => (string) ($component['name'] ?? $definition['component']),
    ] + self::stripNonSerializableProps($props);
  }

  /**
   * Removes props that cannot cross the boundary.
   *
   * A prop typed as a PHP class is not data. Core strips those types before
   * validating and then never checks that what remains is serializable, which
   * is how a Url object reaches a `type: string` prop and JSON-encodes to an
   * empty object. A published schema must not describe a prop no consumer can
   * ever receive, so they are removed here and reported as unsupported.
   *
   * @param array<string, mixed> $props
   *   The props schema.
   *
   * @return array<string, mixed>
   *   The props schema, with class-typed props removed.
   */
  private static function stripNonSerializableProps(array $props): array {
    $json_types = ['array', 'boolean', 'integer', 'null', 'number', 'object', 'string'];
    foreach ($props['properties'] ?? [] as $name => $definition) {
      $types = (array) ($definition['type'] ?? []);
      $class_types = array_filter($types, static fn (mixed $type): bool => \is_string($type) && !\in_array($type, $json_types, TRUE));
      if ($class_types !== []) {
        unset($props['properties'][$name]);
        $props['required'] = array_values(array_diff($props['required'] ?? [], [$name]));
      }
    }
    if (($props['required'] ?? NULL) === []) {
      unset($props['required']);
    }
    if (($props['properties'] ?? NULL) === []) {
      $props['properties'] = new \stdClass();
    }
    return $props;
  }

  /**
   * Returns the schema of the envelope itself.
   *
   * @return array<string, mixed>
   *   A JSON Schema.
   */
  public static function envelopeSchema(?CacheableMetadata $cacheability = NULL): array {
    $cacheability = [
      'type' => 'object',
      'required' => ['tags', 'maxAge', 'contexts', 'varies'],
      'additionalProperties' => FALSE,
      'properties' => [
        'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
        'maxAge' => ['type' => ['integer', 'null']],
        'contexts' => [
          'type' => 'array',
          'items' => ['type' => 'string'],
          'description' => 'Drupal cache context identifiers. Opaque outside Drupal; use "varies" instead.',
        ],
        'varies' => [
          'type' => 'object',
          'required' => ['public', 'on'],
          'additionalProperties' => FALSE,
          'properties' => [
            'public' => ['type' => 'boolean', 'description' => 'Whether a shared cache may store this response.'],
            'on' => [
              'type' => 'array',
              'items' => ['type' => 'string'],
              'description' => 'HTTP request headers this response varies on.',
            ],
          ],
        ],
      ],
    ];
    $node = [
      'oneOf' => [
        ['$ref' => '#/definitions/componentNode'],
        ['$ref' => '#/definitions/htmlNode'],
      ],
    ];

    return [
      '$schema' => self::DIALECT,
      '$id' => self::url('multi_frontend.schema.envelope', [], $cacheability),
      'title' => 'PageEnvelope',
      'type' => 'object',
      'required' => ['page', 'regions', 'cacheability'],
      'properties' => [
        'page' => [
          'type' => 'object',
          // Every envelope carries all three. Leaving them optional makes a
          // generated client treat guaranteed page metadata as nullable.
          'required' => ['title', 'langcode', 'layout'],
          'properties' => [
            'title' => ['type' => ['string', 'null']],
            'langcode' => ['type' => 'string'],
            'layout' => ['type' => 'string'],
          ],
        ],
        'regions' => [
          'type' => 'object',
          'additionalProperties' => ['type' => 'array', 'items' => $node],
        ],
        'cacheability' => $cacheability,
      ],
      'definitions' => [
        'cacheability' => $cacheability,
        'node' => $node,
        'componentNode' => [
          'type' => 'object',
          'required' => ['type', 'component', 'props', 'slots', 'attributes', 'cacheability'],
          'properties' => [
            'type' => ['const' => 'component'],
            'component' => ['type' => 'string'],
            'props' => ['type' => 'object'],
            'slots' => [
              'type' => 'object',
              'additionalProperties' => ['type' => 'array', 'items' => $node],
            ],
            'attributes' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
            'cacheability' => ['$ref' => '#/definitions/cacheability'],
          ],
        ],
        'htmlNode' => [
          'type' => 'object',
          'required' => ['type', 'markup', 'cacheability'],
          'properties' => [
            'type' => ['const' => 'html'],
            'markup' => ['type' => 'string'],
            'cacheability' => ['$ref' => '#/definitions/cacheability'],
          ],
        ],
      ],
    ];
  }

}
