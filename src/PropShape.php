<?php

declare(strict_types=1);

namespace Drupal\experience_builder;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\Component;
use Drupal\Core\Template\Attribute;
use Drupal\experience_builder\PropExpressions\Component\ComponentPropExpression;

/**
 * A prop shape: a normalized SDC prop schema.
 *
 * Pass a `Component` plugin instance to `PropShape::getComponentProps()` and
 * receive an array of PropShape objects.
 *
 * @phpstan-type JsonSchema array<string, mixed>
 */
final class PropShape {

  public function __construct(
    // The schema of the prop shape.
    public readonly array $schema,
  ) {
    if ($schema !== self::normalizePropSchema($this->schema)) {
      throw new \InvalidArgumentException();
    }
  }

  public static function normalize(array $raw_sdc_prop_schema): PropShape {
    return new PropShape(self::normalizePropSchema($raw_sdc_prop_schema));
  }

  /**
   * @param \Drupal\Core\Plugin\Component $component
   *
   * @return \Drupal\experience_builder\PropShape[]
   */
  public static function getComponentProps(Component $component): array {
    $prop_shapes = [];

    // Retrieve the full JSON schema definition from the SDC's metadata.
    // @see \Drupal\sdc\Component\ComponentValidator::validateProps()
    // @see \Drupal\sdc\Component\ComponentMetadata::parseSchemaInfo()
    /** @var array<string, mixed> $component_schema */
    $component_schema = $component->metadata->schema;
    foreach ($component_schema['properties'] ?? [] as $prop_name => $prop_schema) {
      // TRICKY: `attributes` is a special case — it is kind of a reserved
      // prop.
      // @see \Drupal\sdc\Twig\TwigExtension::mergeAdditionalRenderContext()
      // @see https://www.drupal.org/project/drupal/issues/3352063#comment-15277820
      if ($prop_name === 'attributes') {
        assert($prop_schema['type'][0] === Attribute::class);
        continue;
      }

      $component_prop_expression = new ComponentPropExpression($component->getPluginId(), $prop_name);
      $prop_shapes[(string) $component_prop_expression] = static::normalize($prop_schema);
    }

    return $prop_shapes;
  }

  public function uniquePropSchemaKey(): string {
    // A reliable key thanks to ::normalizePropSchema().
    return urldecode(http_build_query($this->schema));
  }

  /**
   * @param JsonSchema $prop_schema
   *
   * @return JsonSchema
   */
  public static function normalizePropSchema(array $prop_schema): array {
    ksort($prop_schema);
    // Ensure that `type` is always listed first.
    $normalized_prop_schema = ['type' => $prop_schema['type']] + $prop_schema;

    // Title, description and examples do not affect which field type + widget
    // should be used.
    unset($normalized_prop_schema['title']);
    unset($normalized_prop_schema['description']);
    unset($normalized_prop_schema['examples']);

    $normalized_prop_schema['type'] = SdcPropJsonSchemaType::from(
    // TRICKY: SDC always allowed `object` for Twig integration reasons.
    // @see \Drupal\sdc\Component\ComponentMetadata::parseSchemaInfo()
      is_array($prop_schema['type']) ? $prop_schema['type'][0] : $prop_schema['type']
    )->value;

    return $normalized_prop_schema;
  }

  public function findFieldTypeStorage(): ?StorablePropShape {
    // The default storable prop shape, if any.
    $storable_prop_shape = SdcPropJsonSchemaType::from($this->schema['type'])->findFieldTypeStorage($this);

    $alterable = $storable_prop_shape
      ? CandidateStorablePropShape::fromStorablePropShape($storable_prop_shape)
      // If no default storable prop shape exists, generate an empty candidate.
      : new CandidateStorablePropShape($this);

    // Allow modules to alter the default.
    self::moduleHandler()->alter(
      'storage_prop_shape',
      // The value that other modules can alter.
      $alterable,
    );

    // @todo DX: validate that the field type exists.
    // @todo DX: validate that the field prop exists.
    // @todo DX: validate that the field widget exists.

    return $alterable->toStorablePropShape();
  }

  private static function moduleHandler(): ModuleHandlerInterface {
    return \Drupal::moduleHandler();
  }

}
