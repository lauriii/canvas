<?php

declare(strict_types=1);

namespace Drupal\multi_frontend;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\multi_frontend\Envelope\ComponentNode;

/**
 * The entire API surface a producer is handed.
 *
 * Deliberately small. A producer that needs more than this is a producer that
 * is about to reach into storage, which is what this object exists to make
 * awkward: it carries no entity storage, no database, and no config writer.
 * This is a convention enforced by the shape of the API and by coding
 * standards, not a sandbox, and the plan says so rather than pretending
 * otherwise.
 */
final class ProducerContext {

  public function __construct(
    private readonly RefinableCacheableDependencyInterface $cacheability,
    private readonly ProducerInvoker $invoker,
    private readonly int $depth = 0,
  ) {}

  /**
   * Records a cacheable dependency discovered while producing.
   */
  public function addCacheableDependency(CacheableDependencyInterface $dependency): static {
    $this->cacheability->addCacheableDependency($dependency);
    return $this;
  }

  /**
   * Reads a field, applying the access check the field formatter would apply.
   *
   * Entity access is not enough. EntityViewDisplay::buildMultiple() checks
   * view access per field and bubbles its cacheability, and a producer
   * replaces the formatter, so reading a field straight off the entity skips
   * a check nothing else will make.
   *
   * @return \Drupal\Core\Field\FieldItemListInterface|null
   *   The field items, or NULL when the field is absent or not viewable.
   */
  public function field(FieldableEntityInterface $entity, string $field_name): ?FieldItemListInterface {
    if (!$entity->hasField($field_name)) {
      return NULL;
    }
    $items = $entity->get($field_name);
    $access = $items->access('view', NULL, TRUE);
    $this->cacheability->addCacheableDependency($access);
    return $access->isAllowed() ? $items : NULL;
  }

  /**
   * Reads a formatted-text field, filtered through its own text format.
   *
   * A prop declared `contentMediaType: text/html` receives markup that a
   * client will render as HTML. Stored values are not safe until the text
   * format's filters have run, and a producer replaces the formatter that
   * would have run them.
   *
   * @return string|null
   *   The filtered markup, or NULL when the field is absent, not viewable, or
   *   empty.
   */
  public function formattedText(FieldableEntityInterface $entity, string $field_name, int $delta = 0): ?string {
    $items = $this->field($entity, $field_name);
    $item = $items?->get($delta);
    if ($item === NULL || $item->isEmpty()) {
      return NULL;
    }
    $properties = $item->getProperties(TRUE);
    if (!\array_key_exists('value', $properties)) {
      // Not a text field at all. Returning NULL is better than throwing on a
      // property that does not exist: the schema should not have declared
      // this prop as HTML in the first place.
      return NULL;
    }
    $value = (string) ($item->get('value')->getValue() ?? '');
    if ($value === '') {
      return NULL;
    }
    $format = \array_key_exists('format', $properties)
      ? (string) ($item->get('format')->getValue() ?? '')
      : '';
    if ($format === '') {
      // Not a formatted-text field. Return the raw value, which the schema
      // should not have declared as HTML.
      return $value;
    }
    $build = [
      '#type' => 'processed_text',
      '#text' => $value,
      '#format' => $format,
      '#langcode' => $entity->language()->getId(),
    ];
    return (string) $this->invoker->renderInContext($build, $this->cacheability);
  }

  /**
   * Produces a child node, for a slot.
   *
   * Named differently from a producer's own produce() on purpose: this
   * returns a complete envelope node, while a producer returns props.
   */
  public function produceChild(string $producer_id, mixed $subject): ComponentNode {
    if ($this->depth >= self::MAX_DEPTH) {
      throw new \RuntimeException(\sprintf('Component producer nesting exceeded %d levels at "%s".', self::MAX_DEPTH, $producer_id));
    }
    return $this->invoker->produceNode($producer_id, $subject, $this->cacheability, $this->depth + 1);
  }

  /**
   * Maximum producer nesting depth.
   */
  public const MAX_DEPTH = 10;

}
