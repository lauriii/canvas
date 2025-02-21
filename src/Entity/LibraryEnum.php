<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Entity;

/**
 * @internal
 * @see \Drupal\experience_builder\Entity\Component::computeUiLibrary()
 */
enum LibraryEnum: string {
  case Elements = 'elements';
  case ExtensionComponents = 'extension_components';
  case DynamicComponents = 'dynamic_components';
  case PrimaryComponents = 'primary_components';
}
