<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Symfony\Component\HttpFoundation\Request;

// phpcs:disable
// @todo Remove this — this was added to avoid breaking the client while finalizing the server.
final class HardcodedPropsComponentTreeItem extends ComponentTreeItem {
  public array $hardcoded_props = [];
  public function resolveComponentProps(string $component_instance_uuid): array {
    return $this->hardcoded_props[$component_instance_uuid];
  }
}
// phpcs:enable

final class ApiPreviewController {

  use ClientServerConversionTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TypedDataManagerInterface $typedDataManager,
    private readonly AutoSaveManager $autoSaveManager,
  ) {}

  public function __invoke(Request $request, EntityInterface $entity): array {
    $body = json_decode($request->getContent(), TRUE);
    $this->autoSaveManager->save($entity, $body);
    ['layout' => $layout, 'model' => $model] = $body;
    $renderable = $this->clientLayoutAndModelToXbField($layout, $model)->toRenderable();

    if (isset($renderable[ComponentTreeStructure::ROOT_UUID])) {
      $build = self::wrapComponentsForPreview($renderable[ComponentTreeStructure::ROOT_UUID]);
    }
    $build['#prefix'] = '<div class="xb--sortable-list" data-xb-uuid="root">';
    $build['#suffix'] = '</div>';
    $build['#attached']['library'][] = 'experience_builder/preview';
    return $build;
  }

  private static function wrapComponentsForPreview(array $build): array {
    foreach (Element::children($build) as $uuid) {
      $build[$uuid] = self::wrapComponentsForPreview($build[$uuid]);

      if (isset($build[$uuid]['#component'])) {
        // @todo where is data-xb-component-id used in the client? how does this affect non-SDC components?
        $build[$uuid]['#prefix'] = sprintf('<div class="xb--sortable-item" data-xb-uuid="%s" data-xb-component-id="%s">', $uuid, $build[$uuid]['#component']);
      }
      else {
        $build[$uuid]['#prefix'] = sprintf('<div class="xb--sortable-item" data-xb-uuid="%s">', $uuid);
      }
      $build[$uuid]['#suffix'] = '</div>';

      if (isset($build[$uuid]['#slots'])) {
        foreach ($build[$uuid]['#slots'] as $slot_name => $slot) {
          $build[$uuid]['#slots'][$slot_name] = self::wrapComponentsForPreview($slot);
          $build[$uuid]['#slots'][$slot_name]['#prefix'] = sprintf('<div class="xb--sortable-list" data-xb-uuid="%s" data-xb-component-id="slot"%s>',
            $uuid . '-slot-' . $slot_name,
            (array_key_exists('#plain_text', $build[$uuid]['#slots'][$slot_name]) || array_key_exists('#markup', $build[$uuid]['#slots'][$slot_name]))
              ? ' data-xb-slot-is-empty'
              : ''
          );
          $build[$uuid]['#slots'][$slot_name]['#suffix'] = '</div>';
        }
      }
    }
    return $build;
  }

  /**
   * Transform the `layout` + `model` data structure that the client uses.
   *
   * This is the server side, so transform to the representation used by the
   * server-side field type. This allows reusing all of the field type
   * infrastructure, which is also used for
   * final rendering.
   *
   * @see \Drupal\experience_builder\Plugin\Field\FieldFormatter\NaiveComponentTreeFormatter
   * @todo Refactor/remove in
   *   https://www.drupal.org/project/experience_builder/issues/3467954.
   */
  private function clientLayoutAndModelToXbField(array $layout, array $model): ComponentTreeItem {
    $field_item_definition = $this->typedDataManager->createDataDefinition('field_item:component_tree');
    // @phpstan-ignore-next-line
    $field_item_definition->setClass(HardcodedPropsComponentTreeItem::class);
    $component_tree_field_item = $this->typedDataManager->createInstance('field_item:component_tree', [
      'name' => NULL,
      'parent' => NULL,
      'data_definition' => $field_item_definition,
    ]);

    // @todo Use $violations in https://www.drupal.org/project/experience_builder/issues/3485878
    // phpcs:disable DrupalPractice.CodeAnalysis.VariableAnalysis.UnusedVariable
    [$tree, $violations] = self::clientLayoutToServerTree($layout);
    // phpcs:enable

    // This uses a partial override of the XB field type, because the client is
    // sending explicit prop values in its `model`, not prop sources. Use these
    // directly.
    // @see \Drupal\experience_builder\Controller\HardcodedPropsComponentTreeItem::resolveComponentProps()
    assert($component_tree_field_item instanceof HardcodedPropsComponentTreeItem);
    $component_tree_field_item->setValue([
      'tree' => json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT),
    ]);
    $component_tree_field_item->hardcoded_props = $model;

    return $component_tree_field_item;
  }

  /**
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *
   * @return string
   */
  public function getLabel(EntityInterface $entity): string {
    return (string) $entity->label();
  }

}
