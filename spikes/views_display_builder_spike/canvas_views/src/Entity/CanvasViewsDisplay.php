<?php

declare(strict_types=1);

namespace Drupal\canvas_views\Entity;

use Drupal\canvas\Entity\ComponentTreeConfigEntityBase;
use Drupal\canvas\Entity\ListFieldsProviderInterface;
use Drupal\canvas\Entity\PreviewRenderableInterface;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\canvas\PropSource\ListFieldContext;
use Drupal\canvas_views\Form\CanvasViewsDisplayForm;
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
 * only). Template component props map to the view's declared fields (the
 * display's field handlers) through `list-field` prop sources stored in the
 * tree, resolved per row via ListFieldContext during hydration. Each entity
 * of this type is exposed as a placeable component by the `views_display`
 * component source.
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
  ],
)]
final class CanvasViewsDisplay extends ComponentTreeConfigEntityBase implements PreviewRenderableInterface, ListFieldsProviderInterface {

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
   *
   * The view's field handlers are the declared fields.
   */
  public function getDeclaredListFields(): array {
    $view = $this->getViewExecutable();
    if ($view === NULL) {
      return [];
    }
    $view->initHandlers();
    $fields = [];
    foreach ($view->field as $field_id => $handler) {
      $fields[(string) $field_id] = (string) $handler->adminLabel();
    }
    return $fields;
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
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    $list_field_context = \Drupal::service(ListFieldContext::class);
    \assert($list_field_context instanceof ListFieldContext);
    foreach ($view->result as $index => $row) {
      $row_entity = $row->_entity;
      $item_list = $this->createDanglingComponentTreeItemList(
        $row_entity instanceof FieldableEntityInterface ? $row_entity : $this
      );
      $item_list->setValue($raw_items);
      $row_is_preview = $is_preview && $index === 0;
      // The tree's list-field prop sources resolve against this row's
      // declared field values during hydration, through the normal prop
      // source evaluation pipeline.
      $list_field_context->push($this->buildRowFieldValues($view, $index), $cacheability);
      try {
        $row_build = $item_list->toRenderable($row_entity instanceof FieldableEntityInterface ? $row_entity : $this, $row_is_preview);
      }
      finally {
        $list_field_context->pop();
      }
      $build['rows'][$index] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['canvas-views-display__row']],
        'content' => $row_build,
      ];
    }

    $cacheability->applyTo($build);
    return $build;
  }

  /**
   * One row's declared field values: the display's rendered field handlers.
   *
   * Render-context-safe via StylePluginBase::getField(); string-shaped values
   * only for now.
   *
   * @return array<string, string>
   *   Values keyed by views field ID.
   */
  private function buildRowFieldValues(ViewExecutable $view, int $row_index): array {
    $values = [];
    foreach (\array_keys($view->field) as $field_id) {
      $values[(string) $field_id] = \trim(\strip_tags((string) $view->style_plugin?->getField($row_index, (string) $field_id)));
    }
    return $values;
  }

}
