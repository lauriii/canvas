<?php

declare(strict_types=1);

namespace Drupal\canvas_views\Entity;

use Drupal\canvas\Entity\ComponentTreeConfigEntityBase;
use Drupal\canvas\Entity\PreviewRenderableInterface;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\canvas_views\Form\CanvasViewsDisplayForm;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\views\ViewExecutable;
use Drupal\views\Views;

/**
 * A designed display for a view: a component tree rendered once per row.
 *
 * The view is the query; this entity is the experience. Its tree is designed
 * in the Canvas editor (the entity is a self-rendering template there:
 * repetition per result row, editing annotations on the first repetition
 * only), and its `mappings` bind template component props to the view's
 * declared fields (the display's field handlers). Each entity of this type is
 * exposed as a placeable component by the `views_display` component source.
 *
 * MVP quality: string-shaped mapped values, no pager, per-row output not
 * render cached.
 */
#[ConfigEntityType(
  id: self::ENTITY_TYPE_ID,
  label: new TranslatableMarkup('Canvas views display'),
  label_collection: new TranslatableMarkup('Canvas views displays'),
  label_singular: new TranslatableMarkup('Canvas views display'),
  label_plural: new TranslatableMarkup('Canvas views displays'),
  admin_permission: self::ADMIN_PERMISSION,
  handlers: [
    'list_builder' => EntityListBuilder::class,
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
    'form' => [
      'add' => CanvasViewsDisplayForm::class,
      'edit' => CanvasViewsDisplayForm::class,
    ],
  ],
  links: [
    'collection' => '/admin/structure/canvas-views-display',
    'add-form' => '/admin/structure/canvas-views-display/add',
    'edit-form' => '/admin/structure/canvas-views-display/{canvas_views_display}',
  ],
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'status' => 'status',
  ],
  config_export: [
    'id',
    'label',
    'view_id',
    'component_tree',
    'mappings',
  ],
)]
final class CanvasViewsDisplay extends ComponentTreeConfigEntityBase implements PreviewRenderableInterface {

  public const string ENTITY_TYPE_ID = 'canvas_views_display';

  public const string ADMIN_PERMISSION = 'administer canvas views displays';

  /**
   * How many result rows the editor preview renders.
   */
  private const int PREVIEW_ITEMS = 3;

  protected string $id;

  protected ?string $label;

  protected ?string $view_id = NULL;

  /**
   * Prop mappings: component instance UUID => prop name => views field ID.
   *
   * @var array<string, array<string, string>>
   */
  protected array $mappings = [];

  /**
   * {@inheritdoc}
   */
  public function getComponentTree(): ComponentTreeItemList {
    $component_tree = $this->createDanglingComponentTreeItemList($this);
    $component_tree->setValue(\array_values($this->component_tree ?? []));
    return $component_tree;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    parent::calculateDependencies();
    $this->addDependencies($this->getComponentTree()->calculateDependencies());
    $view = Views::getView($this->getViewId());
    if ($view !== NULL) {
      $this->addDependency('config', $view->storage->getConfigDependencyName());
    }
    return $this;
  }

  public function getViewId(): string {
    return $this->view_id ?? '';
  }

  /**
   * @return array<string, array<string, string>>
   *   The prop mappings.
   */
  public function getMappings(): array {
    return $this->mappings;
  }

  /**
   * Loads the view executable with its query display set.
   *
   * Prefers a `canvas` (query-only) display; falls back to the default
   * display so the MVP also works with pre-existing views.
   */
  public function getViewExecutable(): ?ViewExecutable {
    $view = Views::getView($this->getViewId());
    if ($view === NULL) {
      return NULL;
    }
    foreach ($view->storage->get('display') as $display_id => $display) {
      if (($display['display_plugin'] ?? NULL) === 'canvas') {
        $view->setDisplay($display_id);
        return $view;
      }
    }
    $view->setDisplay('default');
    return $view;
  }

  /**
   * {@inheritdoc}
   */
  public function buildPreviewRenderable(): array {
    // The controller unwraps the tree root.
    // @see \Drupal\canvas\Controller\ApiLayoutController::buildPreviewRenderable()
    return [ComponentTreeItemList::ROOT_UUID => $this->build(TRUE)];
  }

  /**
   * Renders the designed display: the tree once per result row.
   *
   * @param bool $is_preview
   *   TRUE in the Canvas editor: caps the row count and renders the first
   *   repetition with editing annotations so each template component appears
   *   once to the client.
   */
  public function build(bool $is_preview = FALSE): array {
    $view = $this->getViewExecutable();
    if ($view === NULL) {
      return [];
    }
    if ($is_preview) {
      $view->setItemsPerPage(self::PREVIEW_ITEMS);
    }
    $view->preExecute();
    $view->execute();

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['canvas-views-display']],
    ];
    $cacheability = CacheableMetadata::createFromObject($this);
    $cacheability->addCacheableDependency($view->storage);
    $cacheability->addCacheTags($view->getCacheTags());

    if ($view->result === []) {
      // Keep the template designable against an empty result set.
      if ($is_preview) {
        $build['rows'][0] = $this->getComponentTree()->toRenderable($this, TRUE);
      }
      $cacheability->applyTo($build);
      return $build;
    }

    $raw_items = \array_values($this->component_tree ?? []);
    foreach ($view->result as $index => $row) {
      $row_entity = $row->_entity;
      $items = $this->prepareRowItems($raw_items, $view, $index);
      $item_list = $this->createDanglingComponentTreeItemList(
        $row_entity instanceof FieldableEntityInterface ? $row_entity : $this
      );
      $item_list->setValue($items);
      $row_is_preview = $is_preview && $index === 0;
      $build['rows'][$index] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['canvas-views-display__row']],
        'content' => $item_list->toRenderable($row_entity instanceof FieldableEntityInterface ? $row_entity : $this, $row_is_preview),
      ];
    }

    $cacheability->applyTo($build);
    return $build;
  }

  /**
   * Applies this row's mapped field values over the stored static inputs.
   *
   * @return array<int, array<string, mixed>>
   *   Item values ready for ComponentTreeItemList::setValue().
   */
  private function prepareRowItems(array $raw_items, ViewExecutable $view, int $row_index): array {
    $items = [];
    foreach ($raw_items as $item_value) {
      $item_mappings = $this->mappings[$item_value['uuid']] ?? [];
      if ($item_mappings !== []) {
        $inputs = $item_value['inputs'] ?? [];
        $was_json = \is_string($inputs);
        if ($was_json) {
          $inputs = Json::decode($inputs) ?? [];
        }
        foreach ($item_mappings as $prop_name => $field_id) {
          if (!isset($inputs[$prop_name]) || !isset($view->field[$field_id])) {
            continue;
          }
          // Render-context-safe rendered field output; string props only.
          // @see \Drupal\views\Plugin\views\style\StylePluginBase::renderFields()
          $rendered = \trim(\strip_tags((string) $view->style_plugin?->getField($row_index, $field_id)));
          if (\is_array($inputs[$prop_name])) {
            $inputs[$prop_name]['value'] = $rendered;
          }
          else {
            $inputs[$prop_name] = $rendered;
          }
        }
        $item_value['inputs'] = $was_json ? Json::encode($inputs) : $inputs;
      }
      $items[] = $item_value;
    }
    return $items;
  }

}
