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

  /**
   * Implements hook_config_schema_info_alter().
   */
  #[Hook('config_schema_info_alter')]
  public static function configSchemaInfoAlter(array &$definitions): void {
    // It is a Canvas product decision that all SDC and code component props
    // with URI-esque prop shapes are translatable. For config-defined component
    // trees, it's config schema that determines what exactly appears as
    // translatable. Alter the config schema types for the default values of the
    // `link` and `uri` field types to allow translating their URIs.
    // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentInstanceInputsConfigSchemaGenerator::getConfigSchemaMapping()
    // @see \Drupal\canvas\JsonSchemaInterpreter\JsonSchemaStringFormat::isUriEsque()
    // @see link.schema.yml: field.value.link
    if (isset($definitions['field.value.link']['mapping']['uri'])) {
      $definitions['field.value.link']['mapping']['uri']['type'] = 'translatable_uri';
    }
    // @see core.data_types.schema.yml: field.value.uri
    if (isset($definitions['field.value.uri']['mapping']['value'])) {
      $definitions['field.value.uri']['mapping']['value']['type'] = 'translatable_uri';
    }

    // @todo Remove when Canvas requires a core version that includes https://www.drupal.org/project/drupal/issues/2381147
    // @todo Move to TmgmtHooks when `canvas_dev_translation` is deleted.
    if (isset($definitions['field.value.text'])) {
      $definitions['field.value.text']['type'] = 'text_format';
      unset($definitions['field.value.text']['mapping']);
    }
    if (isset($definitions['field.value.text_long'])) {
      $definitions['field.value.text_long']['type'] = 'text_format';
      unset($definitions['field.value.text_long']['mapping']);
    }
  }

}
