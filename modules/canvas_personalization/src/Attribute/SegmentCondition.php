<?php

declare(strict_types=1);

namespace Drupal\canvas_personalization\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines an attribute for a segment condition.
 *
 * @see \Drupal\canvas_personalization\SegmentCondition\SegmentConditionInterface
 * @see \Drupal\canvas_personalization\SegmentCondition\SegmentConditionManager
 * @see \Drupal\canvas_personalization\SegmentCondition\SegmentConditionBase
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class SegmentCondition extends Plugin {

  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
  ) {}

}
