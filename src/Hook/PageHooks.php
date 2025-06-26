<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Hook;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\experience_builder\Entity\Page;

/**
 * @file
 * Hook implementations that makes XB's Page content entity type work.
 *
 * @see https://www.drupal.org/project/issues/experience_builder?component=Page
 * @see docs/adr/0004-page-entity-type.md
 */
final class PageHooks {

  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
  }

  /**
   * Implements hook_entity_base_field_info().
   */
  #[Hook('entity_base_field_info')]
  public function entityBaseFieldInfo(EntityTypeInterface $entity_type): array {
    $fields = [];
    if ($entity_type->id() === Page::ENTITY_TYPE_ID) {
      // Modules providing an entity type cannot add dynamic base fields based on
      // other modules. The entity field manager determines if a field should be
      // installed based on its "provider", which is the module providing the
      // field definition. All fields from an entity's `baseFieldDefinitions` are
      // always set to the provider of the entity type.
      //
      // To work around this limitation, we provide the base field definition in
      // this hook, where we can specify the provider as the Metatag module.
      //
      // @see \Drupal\Core\Entity\EntityFieldManager::buildBaseFieldDefinitions()
      // @see \Drupal\Core\Extension\ModuleInstaller::install()
      if ($this->moduleHandler->moduleExists('metatag')) {
        $fields['metatags'] = BaseFieldDefinition::create('metatag')
          ->setLabel(new TranslatableMarkup('Metatags'))
          ->setDescription(new TranslatableMarkup('The meta tags for the entity.'))
          ->setTranslatable(\TRUE)
          ->setDisplayOptions('form', [
            'type' => 'metatag_firehose',
            'settings' => ['sidebar' => \TRUE, 'use_details' => \TRUE],
          ])
          ->setDisplayConfigurable('form', \TRUE)
          ->setDefaultValue(Json::encode([
            'title' => '[xb_page:title] | [site:name]',
            'description' => '[xb_page:description]',
            'canonical_url' => '[xb_page:url]',
            // @see https://stackoverflow.com/a/19274942
            'image_src' => '[xb_page:image:entity:field_media_image:entity:url]',
          ]))
          ->setInternal(\TRUE)
          ->setProvider('metatag');
      }
    }
    return $fields;
  }

  /**
   * Implements hook_entity_access().
   *
   * Prevents the deletion of entity whose path is set as homepage.
   *
   * @todo Move to non-Page-specific hooks in https://www.drupal.org/i/3498525
   */
  #[Hook('entity_access')]
  public function preventHomepageDeletion(EntityInterface $entity, string $operation, AccountInterface $account): AccessResultInterface {
    if ($operation === 'delete' && $entity instanceof FieldableEntityInterface) {
      $system_config = $this->configFactory->get('system.site');
      $homepage = $system_config->get('page.front');
      try {
        $url = $entity->toUrl('canonical');
        $path_alias = $url->toString();
        $internal_path = '/' . $url->getInternalPath();
        $paths = array_unique([$path_alias, $internal_path]);
      }
      catch (\Exception) {
        // If the entity does not have a canonical URL, we cannot check the path.
        return AccessResult::neutral();
      }
      if (in_array($homepage, $paths, TRUE)) {
        return AccessResult::forbidden()
          ->addCacheableDependency($system_config)
          ->addCacheableDependency($entity)
          ->setReason('This entity cannot be deleted because its path is set as the homepage.');
      }
      return AccessResult::neutral()->addCacheableDependency($system_config);
    }
    return AccessResult::neutral();
  }

}
