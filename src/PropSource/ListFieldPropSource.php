<?php

declare(strict_types=1);

namespace Drupal\canvas\PropSource;

use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * Prop source powered by the iterated item's declared fields.
 *
 * The explicit field mapping of a repeated template: the stored
 * representation names one field a repeating renderer declares (a views
 * display's field handlers, the List element's source fields), and evaluation
 * resolves that field's value for the current iteration from
 * ListFieldContext. Outside a repeating render — for example when the client
 * model is built — it evaluates to NULL, which is also this source's honest
 * design-time value: the mapped value only exists per row.
 *
 * Stored representation:
 * @code
 * ['sourceType' => 'list-field', 'field' => 'title']
 * @endcode
 *
 * @internal
 */
final class ListFieldPropSource extends PropSourceBase {

  public function __construct(
    public readonly string $fieldName,
  ) {
    if ($this->fieldName === '') {
      throw new \InvalidArgumentException('A list-field prop source requires a field name.');
    }
  }

  /**
   * @return array{sourceType: string, field: string}
   */
  public function toArray(): array {
    return [
      'sourceType' => $this->getSourceType(),
      'field' => $this->fieldName,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function parse(array $prop_source): static {
    \assert(
      isset($prop_source['sourceType']) &&
      $prop_source['sourceType'] === PropSource::getTypePrefix(self::class)
    );
    $field = $prop_source['field'] ?? '';
    if (!\is_string($field) || $field === '') {
      throw new \LogicException('A list-field prop source requires a non-empty `field`.');
    }
    return new self($field);
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate(?FieldableEntityInterface $host_entity, bool $is_required): EvaluationResult {
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    $context = \Drupal::service(ListFieldContext::class);
    \assert($context instanceof ListFieldContext);
    if (!$context->hasContext()) {
      // Outside a repeating render there is no iteration to resolve against.
      return new EvaluationResult(NULL);
    }
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheableDependency($context->getCacheability());
    return new EvaluationResult($context->getValue($this->fieldName), $cacheability);
  }

  public function asChoice(): string {
    return $this->getSourceType() . self::SOURCE_TYPE_PREFIX_SEPARATOR . $this->fieldName;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(FieldableEntityInterface|FieldItemListInterface|null $host_entity = NULL): array {
    // The declared field belongs to the repeating renderer's own
    // configuration (e.g. the views display the template entity references),
    // which carries the dependency.
    return [];
  }

}
