<?php

declare(strict_types=1);

namespace Drupal\canvas\Entity;

use Drupal\Core\Entity\EntityInterface;

/**
 * Allows entities of this type to be organized in Folders.
 *
 * A Folder lists config entities by ID, and content entities by UUID.
 *
 * @see \Drupal\canvas\Entity\Folder
 *
 * @internal
 */
interface FolderItemInterface extends EntityInterface {}
