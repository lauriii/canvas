<?php

declare(strict_types=1);

namespace Drupal\canvas_entity_test\Hook;

use Drupal\canvas_entity_test\AlterBuildTestViewBuilder;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\entity_test\EntityTestViewBuilderOverriddenView;

/**
 * Defines hooks for test entities.
 */
final class EntityTestHooks {

  /**
   * Implements hook_entity_base_field_info_alter().
   */
  #[Hook('entity_base_field_info_alter')]
  public static function entityBaseFieldInfoAlter(array &$fields, EntityTypeInterface $entity_type): void {
    if ($entity_type->id() === 'entity_test_mulrev') {
      $key = $entity_type->getKey('revision');
      \assert(\is_string($key));
      // Make the revision key use a string data type.
      $fields[$key] = BaseFieldDefinition::create('string')
        ->setName($key)
        ->setTargetEntityTypeId('entity_test_mulrev')
        ->setLabel(new TranslatableMarkup('Revision ID, but a string'))
        ->setReadOnly(TRUE)
        ->setSetting('max_length', 36);
    }
  }

  /**
   * Implements hook_entity_type_alter().
   *
   * @see \Drupal\Tests\canvas\Kernel\Config\ContentTemplateTest::testFieldUiBaseRouteSetByLaterEntityTypeAlter()
   * @see \Drupal\Tests\canvas\Kernel\EntityHandlers\ContentTemplateAwareViewBuilderTest::testOverriddenViewWithoutTemplate()
   * @see \Drupal\Tests\canvas\Kernel\EntityHandlers\ContentTemplateAwareViewBuilderTest::testAlterBuildInListWithAndWithoutTemplate()
   */
  #[Hook('entity_type_alter')]
  public static function entityTypeAlter(array &$entity_types): void {
    if (isset($entity_types['entity_test_uuid_id'])) {
      $entity_types['entity_test_uuid_id']->set('field_ui_base_route', 'entity.entity_test_uuid_id.admin_form');
      $entity_types['entity_test_uuid_id']->setViewBuilderClass(EntityTestViewBuilderOverriddenView::class);
    }
    if (isset($entity_types['entity_test_with_bundle'])) {
      $entity_types['entity_test_with_bundle']->set('field_ui_base_route', 'entity.entity_test_bundle.edit_form');
      $entity_types['entity_test_with_bundle']->setViewBuilderClass(AlterBuildTestViewBuilder::class);
    }
  }

  #[Hook('entity_test_create_access')]
  public static function createAccess(): AccessResultInterface {
    return AccessResult::neutral()->addCacheTags(['test_create_access_cache_tag']);
  }

}
