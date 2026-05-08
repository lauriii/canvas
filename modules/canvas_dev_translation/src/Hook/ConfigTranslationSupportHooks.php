<?php

declare(strict_types=1);

namespace Drupal\canvas_dev_translation\Hook;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\PageRegion;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\OrderBefore;

/**
 * Makes Canvas config entities compatible with config_translation.
 */
readonly final class ConfigTranslationSupportHooks {

  /**
   * Implements hook_entity_type_alter.
   */
  #[Hook('entity_type_alter', order: new OrderBefore(['config_translation']))]
  public static function entityTypeAlter(array $definitions): void {
    $edit_links = [
      ContentTemplate::ENTITY_TYPE_ID => '/admin/structure/content-template/{content_template}',
      PageRegion::ENTITY_TYPE_ID => '/admin/appearance/page-region/{page_region}',
    ];
    foreach ($edit_links as $entity_type => $edit_link) {
      if (isset($definitions[$entity_type])) {
        \assert($definitions[$entity_type] instanceof EntityTypeInterface);
        // config_translation requires an `edit-form` link template to generate
        // a `config-translation-overview` link template.
        // @see \Drupal\config_translation\Hook\ConfigTranslationHooks::entityTypeAlter()
        $definitions[$entity_type]->setLinkTemplate('edit-form', $edit_link);
      }
    }
  }

}
