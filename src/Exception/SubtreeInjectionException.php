<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Exception;

/**
 * Thrown when a subtree injection fails.
 *
 * @see \Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemList::injectSubTreeItemList()
 */
final class SubtreeInjectionException extends \RuntimeException {
}
