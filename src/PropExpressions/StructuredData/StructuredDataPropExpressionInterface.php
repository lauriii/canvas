<?php

declare(strict_types=1);

namespace Drupal\experience_builder\PropExpressions\StructuredData;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\experience_builder\PropExpressions\PropExpressionInterface;

interface StructuredDataPropExpressionInterface extends PropExpressionInterface {

  // Structured data contains information.
  const PREFIX = 'ℹ︎';

  public function isSupported(EntityInterface|FieldItemInterface $entity_or_field): bool;

}
