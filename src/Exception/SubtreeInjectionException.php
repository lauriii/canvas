<?php

declare(strict_types=1);

namespace Drupal\canvas\Exception;

/**
 * Thrown when a subtree injection fails.
 *
 * @see \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList::injectSlotContent()
 */
final class SubtreeInjectionException extends \RuntimeException {
}
