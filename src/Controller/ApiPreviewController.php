<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\Entity\PageTemplate;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\experience_builder\Render\PreviewEnvelope;
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
    private readonly TypedDataManagerInterface $typedDataManager,
    private readonly AutoSaveManager $autoSaveManager,
  ) {}

  public function __invoke(Request $request, EntityInterface $entity): PreviewEnvelope {
    $body = json_decode($request->getContent(), TRUE);
    \assert(\array_key_exists('model', $body));
    \assert(\array_key_exists('layout', $body));
    \assert(\array_key_exists('entity_form_fields', $body));
    ['layout' => $layout, 'model' => $model] = $body;

    // Save the content region.
    // @todo Store model values for content vs global regions only with their
    // respective entities.
    // @see https://www.drupal.org/project/experience_builder/issues/3495598
    foreach ($layout as $key => $region) {
      if ($region['id'] === 'content') {
        $this->autoSaveManager->save($entity, [
          'layout' => [$region],
          'model' => $model,
          'entity_form_fields' => $body['entity_form_fields'],
        ]);
        $content = $region;
        unset($layout[$key]);
      }
    }

    // Save the global regions if the page template is active.
    if ($template = PageTemplate::forActiveTheme()) {
      $this->autoSaveManager->save($template, [
        'layout' => \array_values($layout),
        'model' => $model,
      ]);
    }

    assert(isset($content));
    // @todo Use converter to convert this to a proper tree item after
    // https://www.drupal.org/i/3493941 and https://www.drupal.org/i/3493943.
    // The conversion logic in the backend does not support dynamic components
    // yet, so we work with a hardcoded props item for the sake of this
    // controller.
    // @see \Drupal\experience_builder\Controller\ClientServerConversionTrait::findTargetForProps
    $renderable = $this->clientLayoutAndModelToXbField($content, $model)->toRenderable();

    if (isset($renderable[ComponentTreeStructure::ROOT_UUID])) {
      $build = $renderable[ComponentTreeStructure::ROOT_UUID];
    }
    // @todo Remove/replace this in https://www.drupal.org/project/experience_builder/issues/3499364
    $build['#prefix'] = '<div data-xb-uuid="content" data-xb-region="content">';
    $build['#suffix'] = '</div>';
    $build['#attached']['library'][] = 'experience_builder/preview';
    return new PreviewEnvelope($build);
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

    // @todo Handle validation in https://www.drupal.org/project/experience_builder/issues/3485878
    $tree = self::clientLayoutToServerTree($layout, FALSE);

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
