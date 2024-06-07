<?php

declare(strict_types=1);

namespace Drupal\experience_builder\PropSource;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\experience_builder\PropExpressions\StructuredData\Evaluator;
use Drupal\experience_builder\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\StructuredDataPropExpressionInterface;

final class DynamicPropSource extends PropSource {

  public function __construct(
    private readonly StructuredDataPropExpressionInterface $expression,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function __toString(): string {
    // @phpstan-ignore-next-line
    return json_encode([
      'sourceType' => 'dynamic',
      'expression' => (string) $this->expression,
    ], JSON_UNESCAPED_UNICODE);
  }

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

  /**
   * {@inheritdoc}
   */
  public function evaluate(FieldableEntityInterface $host_entity): mixed {
    return Evaluator::evaluate($host_entity, $this->expression);
  }

  public function asChoice(): string {
    return (string) $this->expression;
  }

}
