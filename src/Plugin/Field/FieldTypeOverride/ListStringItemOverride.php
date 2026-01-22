<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Field\FieldTypeOverride;

use Drupal\options\Plugin\Field\FieldType\ListStringItem;

/**
 * Adds `label` property to lists of string items.
 *
 * Contributed module subclasses of core's ListStringItem can use the trait
 * to achieve the same.
 */
final class ListStringItemOverride extends ListStringItem {

  use CoreFeatureListStringItemAddPropertiesTrait;

}
