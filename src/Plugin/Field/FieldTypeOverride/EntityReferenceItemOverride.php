<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Field\FieldTypeOverride;

use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem as CoreEntityReferenceItem;

/**
 * Adds `target_uuid` and `url` properties to generic entity reference items.
 *
 * TRICKY: the fact that this class is overriding EntityReferenceItem means that
 * FileItem and its subclasses won't get these additional properties.
 *
 * For the `url` field property that's fine, because FileUriItem itself already
 * has a mechanism for exposing the file URL.
 *
 * @see \Drupal\file\Plugin\Field\FieldType\FileUriItem
 *
 * Contributed module subclasses of core's EntityReferenceItem can use the trait
 * to achieve the same.
 */
final class EntityReferenceItemOverride extends CoreEntityReferenceItem {

  use CoreFeatureEntityReferenceItemAddPropertiesTrait;

}
