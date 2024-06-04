<?php

declare(strict_types=1);

namespace Drupal\experience_builder\PropSource;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\experience_builder\PropExpressions\StructuredData\Evaluator;
use Drupal\experience_builder\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\StructuredDataPropExpressionInterface;

final class DynamicPropSource extends PropSource {

  private FieldableEntityInterface $hostEntity;

  public function __construct(
    private readonly StructuredDataPropExpressionInterface $expression,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function parse(array $sdc_prop_source): static {
    // `sourceType = dynamic` requires an expression to be specified.
    $missing = array_diff(['expression'], array_keys($sdc_prop_source));
    if (!empty($missing)) {
      throw new \LogicException(sprintf('Missing the keys %s.', implode(',', $missing)));
    }

    return new DynamicPropSource(StructuredDataPropExpression::fromString($sdc_prop_source['expression']));
  }

  public function withHostEntity(FieldableEntityInterface $host_entity): self {
    $this->hostEntity = $host_entity;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate(): mixed {
    if (!isset($this->hostEntity)) {
      throw new \LogicException('Can only evaluate a dynamic prop source after calling withHostEntity().');
    }
    return Evaluator::evaluate($this->hostEntity, $this->expression);
  }

}
