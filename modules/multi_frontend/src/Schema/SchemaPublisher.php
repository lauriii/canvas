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
   * The component node is published as a union discriminated on `component`,
   * one variant per component a producer can emit, each carrying that
   * component's props schema inline. Without it `props` is an open object,
   * so a generated client has to cast at the one point it most wants a type,
   * which is the weakest part of the experience this contract offers.
   *
   * @return array<string, mixed>
   *   A JSON Schema.
   */
  public function envelopeSchema(?CacheableMetadata $cacheability = NULL): array {
    // Named apart from the $cacheability parameter deliberately: this is the
    // schema describing a cacheability object, not the collector the URLs
    // below record themselves into.
    $cacheability_schema = [
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
        'cacheability' => $cacheability_schema,
        'error' => [
          'type' => 'object',
          'description' => 'Present only on an error response, whose HTTP status is the same as "status". The envelope is otherwise well-formed, so one parse handles both outcomes.',
          'required' => ['status', 'message'],
          'additionalProperties' => FALSE,
          'properties' => [
            'status' => ['type' => 'integer'],
            'message' => ['type' => 'string'],
          ],
        ],
      ],
      'definitions' => \array_merge(
        [
          'cacheability' => $cacheability_schema,
          'node' => $node,
        ],
        $this->componentNodeDefinitions($node, $cacheability),
        [
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
      ),
    ];
  }

  /**
   * The component node, as a union discriminated on the component id.
   *
   * One variant per component, so a consumer that narrows on `component`
   * gets that component's props typed rather than an open object.
   *
   * @param array<string, mixed> $node
   *   The node reference used for slot contents.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheability
   *   Collector for the URLs the props schemas generate.
   *
   * @return array<string, mixed>
   *   The `componentNode` definition, followed by one definition per variant.
   */
  private function componentNodeDefinitions(array $node, ?CacheableMetadata $cacheability): array {
    // Keyed by component rather than by producer: two producers may serve one
    // component, and a node names the component, not the producer that made
    // it. A variant per producer would give a card node two matching branches,
    // and `oneOf` demands exactly one.
    $producer_by_component = [];
    foreach ($this->producerManager->getDefinitions() as $producer_id => $definition) {
      $producer_by_component[$definition['component']] ??= $producer_id;
    }

    $variants = [];
    $refs = [];
    foreach ($producer_by_component as $component_id => $producer_id) {
      $props = $this->componentSchema($producer_id, $cacheability);
      // The published props schema is reused verbatim rather than rebuilt, so
      // the two documents cannot drift. Its title is dropped with the keys
      // that only mean something on a standalone document: the standalone
      // schema already generates a named type, and repeating the name here
      // would declare it twice in one generated file.
      $title = (string) ($props['title'] ?? $component_id);
      unset($props['$schema'], $props['$id'], $props['title']);

      $key = 'componentNode.' . $component_id;
      $variants[$key] = [
        'type' => 'object',
        'title' => $title . ' node',
        'required' => ['type', 'component', 'props', 'slots', 'attributes', 'cacheability'],
        'properties' => [
          'type' => ['const' => 'component'],
          'component' => ['const' => $component_id],
          'props' => $props,
          'slots' => [
            'type' => 'object',
            'additionalProperties' => ['type' => 'array', 'items' => $node],
          ],
          'attributes' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
          'cacheability' => ['$ref' => '#/definitions/cacheability'],
        ],
      ];
      $refs[] = ['$ref' => '#/definitions/' . self::escapePointerSegment($key)];
    }

    if ($variants === []) {
      // A site with no producers installed still has to publish a usable
      // schema, and an empty `oneOf` is one no validator accepts.
      return [
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
      ];
    }

    return ['componentNode' => ['oneOf' => $refs]] + $variants;
  }

  /**
   * Escapes one JSON Pointer segment.
   *
   * Component ids carry no guarantee about `/` or `~`. Replacing them with a
   * safe character instead would let two different ids collapse onto one
   * definition, so one component would claim another's branch.
   */
  private static function escapePointerSegment(string $segment): string {
    return \str_replace(['~', '/'], ['~0', '~1'], $segment);
  }

}
