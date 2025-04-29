<?php

declare(strict_types=1);

namespace Drupal\xb_test_page\Hook;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\experience_builder\Entity\Page;

class XbTestPageHooks {

  /**
   * Implements hook_entity_base_field_info().
   */
  #[Hook('entity_base_field_info')]
  public function entityBaseFieldInfo(EntityTypeInterface $entity_type): array {
    if ($entity_type->id() === Page::ENTITY_TYPE_ID) {
      $fields = [];
      $fields['xb_test_field'] = BaseFieldDefinition::create('string')
        ->setLabel(new TranslatableMarkup('Test field'))
        ->setDescription(new TranslatableMarkup('A test field'))
        ->setDisplayOptions('view', [
          'label' => 'above',
          'type' => 'string',
          'weight' => 0,
        ]);
      return $fields;
    }
    return [];
  }

  /**
   * Implements hook_ENTITY_TYPE_view().
   */
  #[Hook('xb_page_view')]
  public function xbPageView(array &$build): void {
    $build['#attached']['drupalSettings']['xb_test_page'] = ['foo' => 'Bar'];
    $build['#attached']['library'][] = 'core/drupalSettings';
    $build['xb_test_page_markup'] = ['#markup' => '<div id="xb-test-page-markup">xb_test_page_xb_page_view markup</div>'];
  }

  #[Hook('field_widget_info_alter')]
  public function widgetInfoAlter(array &$info): void {
    // @see \Drupal\Tests\experience_builder\Kernel\LibraryInfoAlterTest::testTransformMounting()
    $info['non_existent_widget']['xb'] = [
      'transforms' => [
        'diaclone' => [],
      ],
    ];
  }

}
