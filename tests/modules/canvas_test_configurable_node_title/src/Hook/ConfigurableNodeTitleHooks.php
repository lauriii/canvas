<?php

declare(strict_types=1);

namespace Drupal\canvas_test_configurable_node_title\Hook;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Test-only hooks that make the node title field display-configurable.
 *
 * Block- and Canvas-based sites render the page title via the
 * `page_title_block` block and hide the node's own title field. That requires
 * the title base field to be display-configurable, which core does not allow by
 * default. This mirrors that real-world configuration so tests can reproduce it.
 */
class ConfigurableNodeTitleHooks {

  #[Hook('entity_base_field_info_alter')]
  public function entityBaseFieldInfoAlter(array &$fields, EntityTypeInterface $entity_type): void {
    if ($entity_type->id() === 'node' && isset($fields['title'])) {
      \assert($fields['title'] instanceof BaseFieldDefinition);
      $fields['title']->setDisplayConfigurable('view', TRUE);
    }
  }

}
