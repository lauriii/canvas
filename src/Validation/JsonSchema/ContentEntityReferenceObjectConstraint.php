<?php

declare(strict_types=1);

namespace Drupal\canvas\Validation\JsonSchema;

use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaObjectRef;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use JsonSchema\Constraints\ObjectConstraint;
use JsonSchema\Entity\JsonPointer;

/**
 * Defines a custom JSON Schema "object" constraint validator.
 *
 * Adds Canvas-specific keyword validation on top of `type: object` schemas
 * that reference the `content-entity-reference` shape:
 * - `x-allowed-entity-type-id` must be present and resolve to a known content
 *   entity type.
 * - `x-allowed-bundle` must be present iff the entity type has bundles, and
 *   must resolve to a known bundle of that entity type.
 *
 * @see docs/shape-matching.md#3.2.3
 * @see \Drupal\canvas\JsonSchemaInterpreter\JsonSchemaObjectRef::ContentEntityReference
 */
final class ContentEntityReferenceObjectConstraint extends ObjectConstraint {

  /**
   * {@inheritdoc}
   *
   * @param mixed $additionalProp
   * @param mixed $patternProperties
   * @param list<string> $appliedDefaults
   *
   * @see \JsonSchema\Constraints\ObjectConstraint::check()
   */
  public function check(
    &$element,
    $schema = NULL,
    ?JsonPointer $path = NULL,
    $properties = NULL,
    $additionalProp = NULL,
    $patternProperties = NULL,
    $appliedDefaults = [],
  ): void {
    parent::check($element, $schema, $path, $properties, $additionalProp, $patternProperties, $appliedDefaults);

    if (!self::isContentEntityReferenceSchema($schema)) {
      return;
    }

    $prop_name = self::extractPropName($path);
    foreach (self::computeSchemaErrors((array) $schema, $prop_name) as [$constraint, $args]) {
      $this->addError($constraint, $path, $args);
    }
  }

  /**
   * Validates a content-entity-reference prop schema (metadata-time).
   *
   * Returns formatted error messages mirroring the style used elsewhere in
   * \Drupal\canvas\ComponentMetadataRequirementsChecker, so callers can fold
   * them straight into their existing error message lists.
   *
   * @param array $prop_schema
   *   The prop's JSON schema.
   * @param string $prop_name
   *   The prop name, embedded into messages for context.
   *
   * @return list<string>
   *   Zero or more error messages. Empty when valid (or when the schema is not
   *   a content-entity-reference).
   */
  public static function validateMetadataSchema(array $prop_schema, string $prop_name): array {
    if (!JsonSchemaObjectRef::isContentEntityReference($prop_schema)) {
      return [];
    }
    $messages = [];
    foreach (self::computeSchemaErrors($prop_schema, $prop_name) as [$constraint, $args]) {
      // Format the same way \JsonSchema\Constraints\BaseConstraint::addError()
      // does, so metadata-time and runtime errors read identically.
      $messages[] = ucfirst(vsprintf($constraint->getMessage(), array_values($args)));
    }
    return $messages;
  }

  /**
   * Whether the given schema is a Canvas content-entity-reference schema.
   *
   * @param mixed $schema
   *   Either an object (runtime path) or array (metadata path).
   */
  private static function isContentEntityReferenceSchema(mixed $schema): bool {
    if (\is_object($schema)) {
      return property_exists($schema, 'type')
        && property_exists($schema, '$ref')
        && $schema->type === 'object'
        && $schema->{'$ref'} === JsonSchemaObjectRef::ContentEntityReference->value;
    }
    return \is_array($schema) && JsonSchemaObjectRef::isContentEntityReference($schema);
  }

  /**
   * Computes content-entity-reference schema-keyword errors.
   *
   * Error messages deliberately do NOT enumerate valid entity type IDs or
   * bundle names: that would leak the site's content model to anyone who
   * surfaces these messages.
   *
   * @param array<string, mixed> $prop_schema
   *   The prop's JSON schema.
   * @param string $prop_name
   *   Prop name; included in the returned args at the position required by the
   *   matching message template.
   *
   * @return list<array{0: \JsonSchema\ConstraintError, 1: array<string, scalar>}>
   *   Tuples of (constraint, args) ready for ::addError() / vsprintf(). Each
   *   args array's value order matches the corresponding message template's
   *   positional placeholders.
   */
  private static function computeSchemaErrors(array $prop_schema, string $prop_name): array {
    if (!\array_key_exists('x-allowed-entity-type-id', $prop_schema)) {
      // @phpstan-ignore-next-line staticMethod.notFound
      return [[CustomConstraintError::X_ALLOWED_ENTITY_TYPE_ID_MISSING(), ['prop_name' => $prop_name]]];
    }
    $entity_type_id = $prop_schema['x-allowed-entity-type-id'];
    \assert(\is_string($entity_type_id));
    $entity_type = self::resolveContentEntityType($entity_type_id);
    if ($entity_type === NULL) {
      // @phpstan-ignore-next-line staticMethod.notFound
      return [[CustomConstraintError::X_ALLOWED_ENTITY_TYPE_ID_INVALID(), ['entity_type_id' => $entity_type_id]]];
    }

    $bundle_provided = \array_key_exists('x-allowed-bundle', $prop_schema);
    $has_bundles = $entity_type->hasKey('bundle');

    if ($has_bundles && !$bundle_provided) {
      return [[
        // @phpstan-ignore-next-line staticMethod.notFound
        CustomConstraintError::X_ALLOWED_BUNDLE_REQUIRED(),
        ['prop_name' => $prop_name, 'entity_type_id' => $entity_type_id],
      ],
      ];
    }
    if (!$has_bundles && $bundle_provided) {
      return [[
        // @phpstan-ignore-next-line staticMethod.notFound
        CustomConstraintError::X_ALLOWED_BUNDLE_NOT_APPLICABLE(),
        ['prop_name' => $prop_name, 'entity_type_id' => $entity_type_id],
      ],
      ];
    }
    if (!$has_bundles) {
      return [];
    }

    $bundle = $prop_schema['x-allowed-bundle'];
    \assert(\is_string($bundle));
    $valid_bundles = \array_keys(\Drupal::service(EntityTypeBundleInfoInterface::class)->getBundleInfo($entity_type_id));
    if (!\in_array($bundle, $valid_bundles, TRUE)) {
      return [[
        // @phpstan-ignore-next-line staticMethod.notFound
        CustomConstraintError::X_ALLOWED_BUNDLE_INVALID(),
        ['bundle' => $bundle, 'entity_type_id' => $entity_type_id],
      ],
      ];
    }

    return [];
  }

  /**
   * Resolves `$entity_type_id` to a content entity type definition, or NULL.
   *
   * Config entity types and unknown IDs both return NULL — the caller treats
   * them identically (neither can back a content-entity-reference prop).
   */
  private static function resolveContentEntityType(string $entity_type_id): ?EntityTypeInterface {
    $definition = \Drupal::entityTypeManager()->getDefinition($entity_type_id, FALSE);
    if (!$definition instanceof EntityTypeInterface) {
      return NULL;
    }
    return $definition->entityClassImplements(ContentEntityInterface::class) ? $definition : NULL;
  }

  /**
   * Extracts the prop name from the JsonPointer path (last segment).
   */
  private static function extractPropName(?JsonPointer $path): string {
    if ($path === NULL) {
      return '';
    }
    $segments = $path->getPropertyPaths();
    $last = end($segments);
    return $last === FALSE ? '' : (string) $last;
  }

}
