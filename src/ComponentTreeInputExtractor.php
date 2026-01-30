<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\canvas\Entity\ComponentInterface;
use Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase;
use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\canvas\Entity\ComponentTreeEntityInterface;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\PropExpressions\Component\ComponentPropExpression;
use Drupal\canvas\PropShape\PropShape;
use Drupal\canvas\Storage\ComponentTreeLoader;

/**
 * Extracts inputs from the component tree.
 */
final readonly class ComponentTreeInputExtractor {

  public function __construct(
    private ComponentTreeLoader $componentTreeLoader,
  ) {}

  /**
   * Extracts inputs from the component tree.
   *
   * @param \Drupal\canvas\Entity\ComponentTreeEntityInterface|\Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The tree.
   * @param array<string> $ignored_prop_names
   *   An array of prop names to ignore during extraction. Defaults to
   *   self::DEFAULT_IGNORED_PROP_NAMES.
   *
   * @return array<string, array<int, mixed>>
   *   The input extracted from the component tree.
   */
  public function extract(ComponentTreeEntityInterface|FieldableEntityInterface $entity, array $ignored_prop_names): array {
    $extracted = [];
    // Extracts the component tree input from the entity.
    $tree = $this->componentTreeLoader->load($entity);

    foreach ($tree as $component_tree_item) {
      \assert($component_tree_item instanceof ComponentTreeItem);
      $component_instance_uuid = $component_tree_item->getUuid();
      $component = $component_tree_item->getComponent();
      \assert($component !== NULL);

      $prop_shapes = self::getPropShapes($component);
      if (count($prop_shapes) === 0) {
        continue;
      }

      $input = $component->getComponentSource()
        ->getExplicitInput($component_instance_uuid, $component_tree_item);

      foreach ($prop_shapes as $prop_expression => $prop_shape) {
        $prop_name = ComponentPropExpression::fromString($prop_expression)->propName;
        if (self::allowedProp($prop_name, $prop_shape, $ignored_prop_names) && isset($input['resolved'][$prop_name])) {
          \assert($input['resolved'][$prop_name] instanceof EvaluationResult);
          $extracted[$component_instance_uuid][] = $input['resolved'][$prop_name]->value;
        }
      }

    }
    return $extracted;
  }

  /**
   * Gets the prop shapes for the given component.
   *
   * Only works for GeneratedFieldExplicitInputUxComponentSourceBase based
   * component sources, which excludes Blocks.
   *
   * @param \Drupal\canvas\Entity\ComponentInterface $component
   *   The component.
   *
   * @return array<string, \Drupal\canvas\PropShape\PropShape>
   *   The prop shapes.
   */
  private static function getPropShapes(ComponentInterface $component): array {
    $component_source = $component->getComponentSource();
    if (!$component_source instanceof GeneratedFieldExplicitInputUxComponentSourceBase) {
      return [];
    }
    $metadata = $component_source->getMetadata();
    return GeneratedFieldExplicitInputUxComponentSourceBase::getComponentInputsForMetadata($component_source->getPluginId(), $metadata);
  }

  /**
   * Checks if the prop is allowed.
   *
   * @param string $prop_name
   *   The prop name.
   * @param \Drupal\canvas\PropShape\PropShape $prop_shape
   *   The prop shape.
   * @param array<string> $ignored_prop_names
   *   An array of prop names to ignore.
   *
   * @return bool
   *   TRUE if the prop is allowed, FALSE otherwise.
   */
  private static function allowedProp(string $prop_name, PropShape $prop_shape, array $ignored_prop_names): bool {
    // Props must be a string.
    if ($prop_shape->resolvedSchema['type'] !== 'string') {
      return FALSE;
    }
    // Ignore enums, as they are values from a pick list and not content.
    if (isset($prop_shape->resolvedSchema['enum'])) {
      return FALSE;
    }
    // Skip props that are configured to be ignored.
    if (in_array($prop_name, $ignored_prop_names, TRUE)) {
      return FALSE;
    }
    return TRUE;
  }

}
