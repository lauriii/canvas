<?php

declare(strict_types=1);

namespace Drupal\canvas\PropSource;

use Drupal\canvas\MissingHostEntityException;
use Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface;
use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\canvas\PropExpressions\StructuredData\Evaluator;
use Drupal\canvas\PropExpressions\StructuredData\FieldTypeBasedPropExpressionInterface;
use Drupal\canvas\PropExpressions\StructuredData\FieldTypeObjectPropsExpression;
use Drupal\canvas\PropExpressions\StructuredData\FieldTypePropExpression;
use Drupal\canvas\PropExpressions\StructuredData\Labeler;
use Drupal\canvas\PropExpressions\StructuredData\NegotiatedLanguage;
use Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldTypePropExpression;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Describes structured data in the item an item template is iterating.
 *
 * Conceptual sibling of EntityFieldPropSource, but:
 * - EntityFieldPropSource evaluates an entity-rooted expression against the
 *   tree's host entity
 * - this evaluates a field-item-rooted expression against the field item that
 *   the enclosing item template is currently rendering
 *
 * The two coexist inside one field-sourced item template, so a card can show
 * this image's caption alongside this page's title. Which context a prop reads
 * from is a property of its prop source class, never of where it sits.
 *
 * A stored expression never contains a delta: the template does not know which
 * delta it is rendering, the item does. That is what keeps a template subtree
 * valid as the host entity gains and loses values.
 *
 * @see \Drupal\canvas\PropSource\AmbientItemContext
 * @see \Drupal\canvas\ShapeMatcher\ItemPropSourceMatcher
 * @see docs/adr/0021-item-template-data-context-is-a-field-item.md
 *
 * @phpstan-import-type PropSourceArray from PropSourceBase
 * @internal
 */
final class ItemPropSource extends PropSourceBase implements LinkablePropSourceInterface {

  public function __construct(
    public readonly FieldTypeBasedPropExpressionInterface $expression,
  ) {}

  /**
   * {@inheritdoc}
   *
   * @return PropSourceArray
   */
  public function toArray(): array {
    return [
      'sourceType' => $this->getSourceType(),
      'expression' => (string) $this->expression,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function parse(array $sdc_prop_source): static {
    if (!\array_key_exists('expression', $sdc_prop_source)) {
      throw new \LogicException('Missing the keys expression.');
    }
    \assert(\is_string($sdc_prop_source['expression']));
    $expression = StructuredDataPropExpression::fromString($sdc_prop_source['expression']);
    if (!$expression instanceof FieldTypeBasedPropExpressionInterface) {
      throw new \LogicException('An item prop source must store a field-item-rooted expression.');
    }
    return new self($expression);
  }

  /**
   * {@inheritdoc}
   *
   * The host entity is accepted for signature compatibility and ignored: this
   * prop source resolves against the ambient item, not against the host entity.
   */
  public function evaluate(?FieldableEntityInterface $host_entity, bool $is_required): EvaluationResult {
    $item = AmbientItemContext::get();
    if ($item === NULL) {
      // No context of any kind: a component tree stored in config is validated
      // detached from the entity that owns it. This is the same situation an
      // EntityFieldPropSource is in without a host entity, and Canvas already
      // accommodates that exception.
      // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::validateComponentInput()
      if ($host_entity === NULL) {
        throw new MissingHostEntityException();
      }
      // There is a host entity but no item, so this binding cannot resolve
      // where it now sits — the enclosing List iterates entities rather than a
      // field, most likely because a site builder just switched its source
      // kind. That is a stale binding, not a broken request: it evaluates to
      // nothing, exactly as an empty field would, so the rest of the template
      // keeps rendering and stays editable.
      return new EvaluationResult(NULL, new CacheableMetadata());
    }
    return Evaluator::evaluate(
      $item,
      $this->expression,
      $is_required,
      NegotiatedLanguage::matchEntity($item->getEntity()),
    );
  }

  public function asChoice(): string {
    return (string) $this->expression;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(FieldableEntityInterface|FieldItemListInterface|null $host_entity = NULL): array {
    return $this->expression->calculateDependencies(
      $host_entity instanceof FieldItemListInterface ? $host_entity : NULL,
    );
  }

  /**
   * Generates a label for this prop source.
   *
   * Follows the existing `field → property` convention, rooted at the item
   * rather than at the field: an item template over an image field offers
   * "Alternative text", and through the reference "File size".
   *
   * @param \Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface|null $host_entity_data_definition
   *   Unused: an item expression is rooted in a field type, not in an entity.
   */
  public function label(mixed $host_entity_data_definition = NULL): TranslatableMarkup {
    return new TranslatableMarkup('@label', ['@label' => self::labelFor($this->expression)]);
  }

  /**
   * Builds the human-readable label of a field-item-rooted expression.
   */
  public static function labelFor(FieldTypeBasedPropExpressionInterface $expression): string {
    return match (TRUE) {
      $expression instanceof FieldTypePropExpression => self::propertyLabel($expression->fieldType, $expression->propName),
      $expression instanceof FieldTypeObjectPropsExpression => \implode(' + ', \array_map(
        static fn (FieldTypeBasedPropExpressionInterface $sub): string => self::labelFor($sub),
        $expression->getObjectExpressions(),
      )),
      $expression instanceof ReferenceFieldTypePropExpression && $expression->referenced instanceof EntityFieldBasedPropExpressionInterface => (string) Labeler::flatten(
        Labeler::label($expression->referenced, $expression->referenced->getHostEntityDataDefinition()),
      ),
      default => (string) $expression,
    };
  }

  /**
   * Resolves a field type's property label, falling back to its machine name.
   */
  private static function propertyLabel(string $field_type, string $property_name): string {
    $field_type_manager = \Drupal::service(FieldTypePluginManagerInterface::class);
    \assert($field_type_manager instanceof FieldTypePluginManagerInterface);
    $definition = $field_type_manager->getDefinition($field_type, FALSE);
    if ($definition === NULL) {
      return $property_name;
    }
    $class = $definition['class'];
    \assert(\is_subclass_of($class, FieldItemInterface::class));
    // @see \Drupal\canvas\PropSource\StaticPropSource::conjureFieldItem()
    $properties = $class::propertyDefinitions(FieldStorageDefinition::create($field_type));
    return isset($properties[$property_name])
      ? (string) $properties[$property_name]->getLabel()
      : $property_name;
  }

}
