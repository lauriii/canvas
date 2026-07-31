<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Canvas\ComponentSource;

use Drupal\canvas\Attribute\ComponentSource;
use Drupal\canvas\ComponentSource\ComponentSourceBase;
use Drupal\canvas\ComponentSource\ComponentSourceWithDeferredSlotsInterface;
use Drupal\canvas\Entity\Component as ComponentEntity;
use Drupal\canvas\Entity\ComponentTreeEntityInterface;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\ListBuilder\ListElementFieldInfo;
use Drupal\canvas\ListBuilder\ListElementFieldTypeFamily;
use Drupal\canvas\ListBuilder\ListElementSettingsValidator;
use Drupal\canvas\ListBuilder\ListQueryExecutor;
use Drupal\canvas\MissingComponentInputsException;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemListInstantiatorTrait;
use Drupal\canvas\PropSource\AmbientItemContext;
use Drupal\canvas\Validation\ConstraintPropertyPathTranslatorTrait;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\Element\EntityAutocomplete;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\Plugin\DataType\EntityAdapter;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Defines the component source for the List element.
 *
 * The List element is the first dynamic-query component in Canvas: its
 * rendered output is the result of a stored content query. The per-instance
 * settings (source, display, limit, pagination, filters, sorts, layout) are a
 * constrained query DSL stored in the instance's `inputs` blob, validated by
 * this source, and executed through entity queries at render time.
 *
 * @see \Drupal\canvas\ListBuilder\ListQueryExecutor
 * @see \Drupal\canvas\ListBuilder\ListElementSettingsValidator
 * @see docs/adr/0020-list-element-component-source-with-constrained-query-dsl.md
 */
#[ComponentSource(
  id: self::SOURCE_PLUGIN_ID,
  label: new TranslatableMarkup('List'),
  supportsImplicitInputs: FALSE,
  discovery: ListComponentDiscovery::class,
)]
final class ListComponent extends ComponentSourceBase implements ComponentSourceWithDeferredSlotsInterface, ContainerFactoryPluginInterface {

  use ComponentTreeItemListInstantiatorTrait;
  use ConstraintPropertyPathTranslatorTrait;

  public const string SOURCE_PLUGIN_ID = 'list';
  public const string EXPLICIT_INPUT_NAME = 'settings';
  public const string ITEM_TEMPLATE_SLOT = 'item_template';

  /**
   * The hydrated-inputs key carrying the identity of the tree's host entity.
   *
   * The pagination endpoint needs to know which entity stores the component
   * tree. This key is computed during hydration and never stored.
   */
  public const string HOST_CONTEXT_KEY = 'canvas_list_host';

  /**
   * The hydrated-inputs key carrying the tree's host entity object.
   *
   * A field source iterates a field of that entity. Computed during hydration
   * and never stored.
   */
  public const string HOST_ENTITY_KEY = 'canvas_list_host_entity';

  /**
   * Settings the last ::clientModelToInput() call dropped, as labels.
   *
   * When the editor changes the content source, conditions and sorts that
   * reference fields missing from the new bundle are dropped. The same-request
   * form rebuild reads this property to show an inline warning naming them.
   *
   * @var list<string>
   */
  private array $lastDroppedSettings = [];

  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
    private readonly ListQueryExecutor $queryExecutor,
    private readonly ListElementSettingsValidator $settingsValidator,
    private readonly ListElementFieldInfo $fieldInfo,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
    private readonly EntityDisplayRepositoryInterface $displayRepository,
    private readonly PrivateTempStoreFactory $tempStoreFactory,
  ) {
    \assert(\array_key_exists('local_source_id', $configuration));
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(ListQueryExecutor::class),
      $container->get(ListElementSettingsValidator::class),
      $container->get(ListElementFieldInfo::class),
      $container->get(EntityTypeManagerInterface::class),
      $container->get(EntityTypeBundleInfoInterface::class),
      $container->get(EntityDisplayRepositoryInterface::class),
      $container->get(PrivateTempStoreFactory::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function isBroken(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getReferencedPluginClass(): ?string {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getComponentDescription(): TranslatableMarkup {
    return new TranslatableMarkup('List of content');
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(): array {
    return ['module' => ['node']];
  }

  /**
   * {@inheritdoc}
   */
  public function requiresExplicitInput(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-ignore-next-line method.childReturnType
   */
  public function getDefaultExplicitInput(bool $only_required = FALSE): array {
    // The List element has no per-input requiredness (the mechanism exists
    // for prop-based sources); avoid the default-bundle query on hot paths.
    if ($only_required) {
      return [];
    }
    return [
      'source' => ['entity_type' => 'node', 'bundle' => $this->getDefaultBundle()],
      'display' => ['mode' => 'title_linked'],
      'limit' => 3,
      'pagination' => ['mode' => 'none', 'page_size' => 10],
      'filters' => ['conjunction' => 'and', 'conditions' => []],
      'sorts' => [['field' => 'created', 'direction' => 'desc']],
      'layout' => ['mode' => 'stack', 'gap' => 'medium'],
    ];
  }

  /**
   * Computes the default content source for newly placed List elements.
   *
   * Prefers the bundle of the most recently created accessible content item,
   * so a fresh List immediately previews with real content, and falls back to
   * the alphabetically first bundle.
   */
  private function getDefaultBundle(): string {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->execute();
    if ($ids !== []) {
      $node = $storage->load(\reset($ids));
      if ($node !== NULL) {
        return $node->bundle();
      }
    }
    $bundles = \array_keys($this->bundleInfo->getBundleInfo('node'));
    \sort($bundles);
    return (string) ($bundles[0] ?? '');
  }

  /**
   * {@inheritdoc}
   */
  public function getExplicitInput(string $uuid, ComponentTreeItem $item, ?FieldableEntityInterface $host_entity = NULL): array {
    try {
      $settings = $item->getInputs() ?? $this->getDefaultExplicitInput();
    }
    catch (MissingComponentInputsException) {
      $settings = $this->getDefaultExplicitInput();
    }
    return $settings + [
      self::HOST_CONTEXT_KEY => self::resolveHostIdentity($item),
      // A field source reads its values from the live host entity object, not
      // from a reloaded copy: the negotiated translation and any unsaved
      // preview state must survive into the item template.
      self::HOST_ENTITY_KEY => self::resolveHostEntity($item, $host_entity),
    ];
  }

  /**
   * Resolves the identity of the entity storing the component tree.
   *
   * @return array{entity_type: string, id: string}|null
   */
  private static function resolveHostIdentity(ComponentTreeItem $item): ?array {
    $root = $item->getRoot();
    if (!$root instanceof EntityAdapter) {
      return NULL;
    }
    $entity = $root->getValue();
    if (!$entity instanceof EntityInterface || $entity->isNew()) {
      return NULL;
    }
    return ['entity_type' => $entity->getEntityTypeId(), 'id' => (string) $entity->id()];
  }

  /**
   * Resolves the fieldable entity a field source reads its values from.
   */
  private static function resolveHostEntity(ComponentTreeItem $item, ?FieldableEntityInterface $host_entity): ?FieldableEntityInterface {
    if ($host_entity instanceof FieldableEntityInterface) {
      return $host_entity;
    }
    $root = $item->getRoot();
    $entity = $root instanceof EntityAdapter ? $root->getValue() : NULL;
    return $entity instanceof FieldableEntityInterface ? $entity : NULL;
  }

  /**
   * Resolves the bundle context a field source is authored against.
   *
   * A field source needs a host entity that has the field. That is true in a
   * content template, whose tree is stored in config against a specific
   * bundle, and false on a page, in a global region, and in a pattern, whose
   * trees have no such bundle.
   *
   * @return array{entity_type: string, bundle: string}|null
   *
   * @see \Drupal\canvas\ListBuilder\ListElementSettingsValidator::validate()
   */
  public static function resolveHostBundleContext(?ComponentTreeItem $item): ?array {
    $root = $item?->getRoot();
    return self::hostBundleContextOf($root instanceof EntityAdapter ? $root->getValue() : $root?->getValue());
  }

  /**
   * Same as ::resolveHostBundleContext(), for an entity rather than a tree item.
   *
   * @return array{entity_type: string, bundle: string}|null
   */
  public static function hostBundleContextOf(mixed $entity): ?array {
    if ($entity instanceof ContentTemplate) {
      return [
        'entity_type' => $entity->getTargetEntityTypeId(),
        'bundle' => $entity->getTargetBundle(),
      ];
    }
    // While rendering, a content template's tree is rooted in the very entity
    // it renders.
    if ($entity instanceof FieldableEntityInterface && !$entity instanceof ComponentTreeEntityInterface) {
      return ['entity_type' => $entity->getEntityTypeId(), 'bundle' => $entity->bundle()];
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function hydrateComponent(array $explicit_input, array $slot_definitions, array $active_required_explicit_inputs): array {
    $host = $explicit_input[self::HOST_CONTEXT_KEY] ?? NULL;
    $host_entity = $explicit_input[self::HOST_ENTITY_KEY] ?? NULL;
    unset($explicit_input[self::HOST_CONTEXT_KEY], $explicit_input[self::HOST_ENTITY_KEY]);
    $hydrated = [
      self::EXPLICIT_INPUT_NAME => $explicit_input,
      self::HOST_CONTEXT_KEY => $host,
      self::HOST_ENTITY_KEY => $host_entity,
    ];
    if ($slot_definitions !== []) {
      $hydrated['slots'] = \array_map(static fn (array $slot): string => $slot['examples'][0] ?? '', $slot_definitions);
    }
    return $hydrated;
  }

  /**
   * {@inheritdoc}
   */
  public function getSlotDefinitions(): array {
    return [
      self::ITEM_TEMPLATE_SLOT => [
        'title' => 'Item template',
        'description' => 'Components rendered once per listed item, with that item as their data context.',
        'examples' => [''],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getDeferredSlotNames(): array {
    return [self::ITEM_TEMPLATE_SLOT];
  }

  /**
   * {@inheritdoc}
   */
  public function getDeferredSlotContext(array $explicit_input, ?FieldableEntityInterface $host_entity = NULL): FieldableEntityInterface|FieldItemInterface|null {
    $host_entity = $explicit_input[self::HOST_ENTITY_KEY] ?? $host_entity;
    \assert($host_entity === NULL || $host_entity instanceof FieldableEntityInterface);
    unset($explicit_input[self::HOST_CONTEXT_KEY], $explicit_input[self::HOST_ENTITY_KEY]);
    if (\count($this->settingsValidator->validate($explicit_input, self::hostContextOf($host_entity))) > 0) {
      return NULL;
    }
    if (ListElementSettingsValidator::sourceKind($explicit_input) === ListElementSettingsValidator::SOURCE_FIELD) {
      // The first value stands in for every value while validating and
      // modeling the template. With no values there is no representative
      // context at all, exactly as for a query that matches nothing.
      $items = self::windowFieldItems($explicit_input, $host_entity);
      return $items[0] ?? NULL;
    }
    // A representative entity of the source bundle, ignoring the filters, so
    // the template stays validatable and editable while the filters match
    // nothing.
    $sample_settings = $explicit_input;
    $sample_settings['filters']['conditions'] = [];
    $sample_settings['limit'] = 1;
    $sample_settings['pagination'] = ['mode' => 'none', 'page_size' => 10];
    $entities = $this->queryExecutor->execute($sample_settings)->entities;
    $entity = \reset($entities);
    return $entity instanceof FieldableEntityInterface ? $entity : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function setSlots(array &$build, array $slots): void {
    // The item template's children render per result entity via the deferred
    // slot subtree, not via the pre-rendered slot content. The only slot
    // content that is used is the editor preview's empty-slot placeholder,
    // which ::renderComponent() prepared a target position for.
    $target = $build['#canvas_list_slot_target'] ?? NULL;
    if ($target !== NULL && isset($slots[self::ITEM_TEMPLATE_SLOT])) {
      NestedArray::setValue($build, [...$target, 'placeholder'], $slots[self::ITEM_TEMPLATE_SLOT]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getResolvedExplicitInput(string $uuid, ComponentTreeItem $item, ?FieldableEntityInterface $host_entity = NULL): array {
    $hydrated_inputs = parent::getResolvedExplicitInput($uuid, $item, $host_entity);
    \assert(\array_key_exists(self::EXPLICIT_INPUT_NAME, $hydrated_inputs));
    return $hydrated_inputs[self::EXPLICIT_INPUT_NAME];
  }

  /**
   * {@inheritdoc}
   */
  protected function getExplicitInputDefinitions(): array {
    // A declarative descriptor of the settings DSL: any change to the DSL's
    // surface results in a new Component version.
    return [
      self::EXPLICIT_INPUT_NAME => [
        'dsl_version' => 1,
        'structure' => ['source', 'display', 'limit', 'pagination', 'filters', 'sorts', 'layout'],
        'display_modes' => ListElementSettingsValidator::DISPLAY_MODES,
        'pagination_modes' => ListElementSettingsValidator::PAGINATION_MODES,
        'layout_modes' => ListElementSettingsValidator::LAYOUT_MODES,
        'operators' => \array_reduce(
          ListElementFieldTypeFamily::cases(),
          static fn (array $carry, ListElementFieldTypeFamily $family): array => $carry + [$family->value => $family->allowedOperators(TRUE)],
          [],
        ),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function renderComponent(array $inputs, array $slot_definitions, string $componentUuid, bool $isPreview = FALSE): array {

    $settings = $inputs[self::EXPLICIT_INPUT_NAME] ?? [];
    $host_entity = $inputs[self::HOST_ENTITY_KEY] ?? NULL;
    \assert($host_entity === NULL || $host_entity instanceof FieldableEntityInterface);
    $violations = $this->settingsValidator->validate($settings, self::hostContextOf($host_entity));
    if (\count($violations) > 0) {
      // A misconfigured list renders nothing on the live site and a warning
      // state in the editor preview.
      if (!$isPreview) {
        return [];
      }
      return [
        '#type' => 'container',
        '#attributes' => ['class' => ['canvas-list-element', 'canvas-list-element--warning']],
        'message' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#attributes' => ['class' => ['canvas-list-element__state']],
          '#value' => $this->t('This List is misconfigured — for example its content type or a filtered field no longer exists. Review its settings.'),
        ],
        '#attached' => ['library' => ['canvas/list_element']],
      ];
    }

    $is_field_source = ListElementSettingsValidator::sourceKind($settings) === ListElementSettingsValidator::SOURCE_FIELD;
    if ($is_field_source) {
      // The item window is computed before any item renders: a template
      // subtree is a whole component tree per item, so building a value only
      // to discard it is the one thing that must not happen.
      // @see https://www.drupal.org/i/2846485
      $items = self::windowFieldItems($settings, $host_entity);
      // A field's values are host entity data; its cache tag covers them.
      $cacheability = CacheableMetadata::createFromObject($host_entity);
    }
    else {
      $result = $this->queryExecutor->execute($settings);
      $items = $result->entities;
      $cacheability = CacheableMetadata::createFromObject($result->cacheability);
    }

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['canvas-list-element']],
      '#attached' => ['library' => ['canvas/list_element']],
      'items' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => \array_filter([
            'canvas-list',
            'canvas-list--' . $settings['layout']['mode'],
            'canvas-list--gap-' . $settings['layout']['gap'],
            isset($settings['layout']['distribute']) ? 'canvas-list--distribute-' . $settings['layout']['distribute'] : NULL,
            isset($settings['layout']['align']) ? 'canvas-list--align-' . $settings['layout']['align'] : NULL,
          ]),
        ],
      ],
    ];
    if (isset($settings['layout']['items_per_row']) || isset($settings['layout']['max_per_row'])) {
      $build['items']['#attributes']['style'] = \sprintf('--canvas-list-per-row: %d;', $settings['layout']['items_per_row'] ?? $settings['layout']['max_per_row']);
    }

    $template_subtree = $inputs[ComponentTreeItemList::DEFERRED_SLOT_SUBTREES_KEY][self::ITEM_TEMPLATE_SLOT] ?? [];
    foreach ($this->renderItems($settings, $items, $template_subtree, $isPreview, $host_entity) as $item) {
      $build['items'][] = $item;
    }

    // In the editor preview, keep the item template editable even when the
    // filters match nothing: bind one repetition to a sample entity of the
    // bundle so the template's components render and stay selectable. A field
    // source has no sample to invent: its values are the host entity's own, so
    // an empty field simply shows the empty placeholder.
    if (!$is_field_source && $isPreview && $settings['display']['mode'] === 'item_template' && $template_subtree !== [] && $items === []) {
      $sample_settings = $settings;
      $sample_settings['filters']['conditions'] = [];
      $sample_settings['limit'] = 1;
      $sample_settings['pagination'] = ['mode' => 'none', 'page_size' => 10];
      $sample = $this->queryExecutor->execute($sample_settings);
      $cacheability->addCacheableDependency($sample->cacheability);
      foreach ($this->renderItems($settings, $sample->entities, $template_subtree, TRUE) as $item) {
        $build['items'][] = $item;
      }
    }

    if ($isPreview && $settings['display']['mode'] === 'item_template') {
      self::prepareTemplateSlotPreview($build, $template_subtree, $componentUuid);
    }

    if ($items === [] && $isPreview) {
      $build['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['canvas-list-element__state', 'canvas-list-element__state--empty']],
        '#value' => $is_field_source
          ? $this->t('This field has no values.')
          : $this->t('No content matches these settings.'),
      ];
    }

    if (!$is_field_source) {
      $this->addPagination($build, $settings, $inputs, $componentUuid, $result->consumed, $result->hasMore, $isPreview, $cacheability);
    }

    $cacheability->applyTo($build);
    return $build;
  }

  /**
   * Returns the entity type and bundle of a host entity, if there is one.
   *
   * @return array{entity_type: string, bundle: string}|null
   */
  private static function hostContextOf(?FieldableEntityInterface $host_entity): ?array {
    return self::hostBundleContextOf($host_entity);
  }

  /**
   * Returns the field items a field source iterates, already windowed.
   *
   * @return list<FieldItemInterface>
   *   The field's items in delta order, at most `limit` of them.
   */
  private static function windowFieldItems(array $settings, ?FieldableEntityInterface $host_entity): array {
    if ($host_entity === NULL || !$host_entity->hasField($settings['source']['field_name'])) {
      return [];
    }
    $field_item_list = $host_entity->get($settings['source']['field_name']);
    \assert($field_item_list instanceof FieldItemListInterface);
    if (!$field_item_list->access('view')) {
      return [];
    }
    $items = \iterator_to_array($field_item_list);
    $limit = $settings['limit'] ?? NULL;
    return \array_values($limit === NULL ? $items : \array_slice($items, 0, $limit));
  }

  /**
   * Renders result entities as list items per the display settings.
   *
   * Also used by the pagination endpoint, so that subsequent pages render
   * exactly like the initial page.
   *
   * @param array $settings
   *   Valid canonical List element settings.
   * @param array<int|string, EntityInterface|FieldItemInterface> $entities
   *   The items of one window: result entities for a query source, field items
   *   for a field source.
   * @param array $template_subtree
   *   For the item template display: the raw component tree item values of
   *   the template subtree.
   * @param bool $isPreview
   *   TRUE when rendering the editor preview. Only the first repetition of an
   *   item template renders with preview annotations, so each template
   *   component appears once in the editor.
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $host_entity
   *   For a field source: the entity the field belongs to. Entity field prop
   *   sources inside the template keep resolving against it.
   *
   * @return list<array>
   *   One item wrapper render array per entity.
   *
   * @see \Drupal\canvas\Controller\ApiListElementController
   */
  public function renderItems(array $settings, array $entities, array $template_subtree = [], bool $isPreview = FALSE, ?FieldableEntityInterface $host_entity = NULL): array {
    $items = [];
    $index = 0;
    foreach ($entities as $entity) {
      $items[] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['canvas-list__item']],
        'content' => $this->renderItem($entity, $settings, $template_subtree, $isPreview && $index === 0, $host_entity),
      ];
      $index++;
    }
    return $items;
  }

  /**
   * Renders one item per the display settings.
   */
  private function renderItem(EntityInterface|FieldItemInterface $entity, array $settings, array $template_subtree, bool $is_annotated_preview, ?FieldableEntityInterface $host_entity = NULL): array {
    // A field item is only ever displayed through an item template: the other
    // display modes render an entity, and a field item is not one.
    if ($entity instanceof FieldItemInterface) {
      if ($template_subtree === [] || $host_entity === NULL) {
        return [];
      }
      // The host entity is NOT replaced: entity field prop sources inside the
      // template keep resolving against it, while item prop sources resolve
      // against the ambient item.
      // @see docs/adr/0021-item-template-data-context-is-a-field-item.md
      $tree = $this->createDanglingComponentTreeItemList($host_entity);
      $tree->setValue($template_subtree);
      return AmbientItemContext::within(
        $entity,
        static fn (): array => $tree->toRenderable($host_entity, $is_annotated_preview),
      );

    }
    if ($settings['display']['mode'] === 'item_template') {
      if ($template_subtree === [] || !$entity instanceof FieldableEntityInterface) {
        return [];
      }
      // Render the template subtree bound to this result entity: entity field
      // prop expressions inside the template resolve against it.
      $tree = $this->createDanglingComponentTreeItemList($entity);
      $tree->setValue($template_subtree);
      return $tree->toRenderable($entity, $is_annotated_preview);
    }
    if ($settings['display']['mode'] === 'view_mode') {
      return $this->entityTypeManager
        ->getViewBuilder($entity->getEntityTypeId())
        ->view($entity, $settings['display']['view_mode']);
    }
    // The built-in "Title (linked)" display: the label linked to the entity.
    // It requires no site building, so the List element produces useful
    // output even on sites with no configured view modes.
    $link = $entity->toLink()->toRenderable();
    $link['#attributes']['class'][] = 'canvas-list__item-title-link';
    CacheableMetadata::createFromObject($entity)->applyTo($link);
    return $link;
  }

  /**
   * Marks the item template's slot region in the editor preview.
   *
   * The first repetition doubles as the slot region: the editor's overlay
   * uses the slot annotation comments to make it a drop target, and the
   * template's components appear once (in that repetition) as selectable
   * instances. With no template components yet, an empty pseudo-item hosts
   * the slot placeholder that ::setSlots() receives.
   */
  private static function prepareTemplateSlotPreview(array &$build, array $template_subtree, string $componentUuid): void {
    if (!isset($build['items'][0])) {
      $build['items'][0] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['canvas-list__item']],
        'content' => [],
      ];
    }
    $build['items'][0]['content']['#prefix'] = Markup::create(\sprintf('<!-- canvas-slot-start-%s/%s -->', $componentUuid, self::ITEM_TEMPLATE_SLOT));
    $build['items'][0]['content']['#suffix'] = Markup::create(\sprintf('<!-- canvas-slot-end-%s/%s -->', $componentUuid, self::ITEM_TEMPLATE_SLOT));
    if ($template_subtree === []) {
      $build['#canvas_list_slot_target'] = ['items', 0, 'content'];
    }
  }

  /**
   * Extracts a List instance's item template subtree from a component tree.
   *
   * The direct children of the template slot are re-rooted so the returned
   * values form a self-contained tree; deeper structure is kept intact.
   *
   * @return list<array>
   *
   * @see \Drupal\canvas\Controller\ApiListElementController
   */
  public static function extractTemplateSubtree(ComponentTreeItemList $tree, string $list_uuid): array {
    $values = $tree->getValue();
    $subtree = [];
    $included = [];
    foreach ($values as $value) {
      if (($value['parent_uuid'] ?? NULL) === $list_uuid && ($value['slot'] ?? NULL) === self::ITEM_TEMPLATE_SLOT) {
        $included[$value['uuid']] = TRUE;
        unset($value['parent_uuid'], $value['slot']);
        $subtree[] = $value;
      }
    }
    do {
      $changed = FALSE;
      foreach ($values as $value) {
        if (!isset($included[$value['uuid']]) && isset($included[$value['parent_uuid'] ?? ''])) {
          $included[$value['uuid']] = TRUE;
          $subtree[] = $value;
          $changed = TRUE;
        }
      }
    } while ($changed);
    return $subtree;
  }

  /**
   * Adds the pagination controls and client-side behavior to the build.
   */
  private function addPagination(array &$build, array $settings, array $inputs, string $componentUuid, int $consumed, bool $has_more, bool $isPreview, CacheableMetadata $cacheability): void {
    $mode = $settings['pagination']['mode'];
    if ($mode === 'none' || !$has_more) {
      return;
    }

    // The load more button ships as plain markup so it is targetable by
    // global styles.
    if ($mode === 'load_more') {
      $build['load_more'] = [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#attributes' => [
          'type' => 'button',
          'class' => ['canvas-list-element__load-more'],
        ],
        '#value' => $this->t('Load more'),
      ];
    }
    else {
      $build['sentinel'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['canvas-list-element__sentinel'], 'aria-hidden' => 'true'],
      ];
    }

    // The editor preview shows the first page window only: the controls above
    // appear (so editors see what visitors get), but fetching further pages is
    // published-page behavior.
    $host = $inputs[self::HOST_CONTEXT_KEY] ?? NULL;
    if ($isPreview || $host === NULL) {
      return;
    }

    $url = Url::fromRoute('canvas.list_element.page', [
      'entity_type' => $host['entity_type'],
      'entity' => $host['id'],
      'component_instance_uuid' => $componentUuid,
    ])->toString(TRUE);
    $cacheability->addCacheableDependency($url);
    $build['#attributes']['data-canvas-list-endpoint'] = $url->getGeneratedUrl();
    $build['#attributes']['data-canvas-list-mode'] = $mode;
    $build['#attributes']['data-canvas-list-offset'] = (string) $consumed;
    $build['#attached']['library'][] = 'canvas/list_element.pagination';
  }

  /**
   * {@inheritdoc}
   */
  public function getClientSideInfo(ComponentEntity $component): array {
    return [
      'build' => $this->renderComponent(
        [self::EXPLICIT_INPUT_NAME => $this->getDefaultExplicitInput()],
        $component->getSlotDefinitions(),
        $component->uuid(),
        TRUE,
      ),
      // The client detects slot support through this metadata.
      // @see hasSlotDefinitions() in ui/src/types/Component.ts
      'metadata' => ['slots' => $this->getSlotDefinitions()],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function inputToClientModel(array $explicit_input): array {
    unset($explicit_input[self::HOST_CONTEXT_KEY]);
    $settings = $explicit_input;

    $display = $settings['display'] ?? ['mode' => 'title_linked'];
    $conditions = [];
    foreach ($settings['filters']['conditions'] ?? [] as $condition) {
      $value = $condition['value'] ?? NULL;
      if (\is_bool($value)) {
        $value = $value ? '1' : '0';
      }
      $conditions[] = \array_filter([
        'field' => $condition['field'],
        'operator' => $condition['operator'],
        'value' => $value,
      ], static fn (mixed $v): bool => $v !== NULL);
    }
    $sorts = \array_values($settings['sorts'] ?? []);

    return [
      'resolved' => [
        'source' => ['selection' => self::sourceSelection($settings)],
        'display' => [
          'mode_select' => $display['mode'] === 'view_mode' ? 'view_mode:' . ($display['view_mode'] ?? '') : $display['mode'],
        ],
        'limit' => [
          'unlimited' => !isset($settings['limit']),
          'count' => $settings['limit'] ?? 3,
        ],
        'pagination' => $settings['pagination'] ?? ['mode' => 'none', 'page_size' => 10],
        'filters' => [
          'conjunction' => $settings['filters']['conjunction'] ?? 'and',
          'conditions' => $conditions,
        ],
        'sorts' => $sorts,
        'layout' => $settings['layout'] ?? ['mode' => 'stack', 'gap' => 'medium'],
      ],
      // The structural signature of the settings. The client re-fetches the
      // settings form whenever a client model's `source` key changes, so any
      // change that alters which form controls exist (but not mere value
      // edits, which must not steal focus) triggers a server-side rebuild
      // with re-derived dependent options.
      // @see ui/src/components/ComponentInstanceForm.tsx
      'source' => self::settingsStructureSignature($settings),
      // The remount key makes the client remount (not just re-render) the
      // form subtree when the structure changed, so removed controls really
      // disappear.
      'formRemountKey' => \hash('xxh64', \json_encode(self::settingsStructureSignature($settings), JSON_THROW_ON_ERROR)),
    ];
  }

  /**
   * Computes the structural signature of the settings.
   *
   * Two settings arrays share a signature exactly when they produce the same
   * set of form controls in ::buildComponentInstanceForm().
   */
  private static function settingsStructureSignature(array $settings): array {
    return [
      'selection' => self::sourceSelection($settings),
      'display_mode' => $settings['display']['mode'] ?? 'title_linked',
      'unlimited' => !isset($settings['limit']),
      'pagination_mode' => $settings['pagination']['mode'] ?? 'none',
      'conditions' => \array_map(
        static fn (array $condition): array => ['field' => $condition['field'], 'operator' => $condition['operator']],
        $settings['filters']['conditions'] ?? [],
      ),
      'sorts' => \array_column($settings['sorts'] ?? [], 'field'),
      'layout_mode' => $settings['layout']['mode'] ?? 'stack',
    ];
  }

  /**
   * Returns the source select's value for a settings blob.
   *
   * `bundle:<bundle>` for a content query, `field:<field_name>` for a field of
   * the host entity.
   */
  private static function sourceSelection(array $settings): string {
    return ListElementSettingsValidator::sourceKind($settings) === ListElementSettingsValidator::SOURCE_FIELD
      ? 'field:' . ($settings['source']['field_name'] ?? '')
      : 'bundle:' . ($settings['source']['bundle'] ?? '');
  }

  /**
   * {@inheritdoc}
   */
  public function clientModelToInput(string $component_instance_uuid, ComponentEntity $component, array $client_model, ?FieldableEntityInterface $host_entity, ?ConstraintViolationListInterface $violations = NULL): array {
    $this->lastDroppedSettings = [];
    $values = $client_model['resolved'] ?? [];
    // A newly placed List has no client model yet: initialize with defaults.
    // @see addNewComponentToLayout AppThunk in layoutModelSlice.ts
    if ($values === []) {
      return $this->getDefaultExplicitInput();
    }
    $settings = $this->formValuesToSettings($values);
    // Conversion happens in the layout update request, but the settings form
    // is rebuilt by a subsequent request; stash any dropped-settings warnings
    // for that rebuild to display.
    if ($this->lastDroppedSettings !== []) {
      $this->tempStoreFactory->get('canvas_list_element')->set($component_instance_uuid, $this->lastDroppedSettings);
    }
    return $settings;
  }

  /**
   * Claims (reads and clears) stashed dropped-settings warnings.
   *
   * @return list<string>
   */
  private function claimDroppedSettingsWarnings(string $component_instance_uuid): array {
    if ($component_instance_uuid === '') {
      return [];
    }
    $store = $this->tempStoreFactory->get('canvas_list_element');
    $dropped = $store->get($component_instance_uuid);
    if (!\is_array($dropped) || $dropped === []) {
      return [];
    }
    $dropped = \array_values(\array_filter($dropped, \is_string(...)));
    try {
      $store->delete($component_instance_uuid);
    }
    catch (\Exception) {
      // Failing to clear the stash only risks showing the warning again.
    }
    return $dropped;
  }

  /**
   * Maps the client model (form values) to canonical stored settings.
   *
   * @phpstan-impure
   *
   * Conditions and sorts referencing fields that do not exist on the selected
   * bundle are dropped and recorded for the same-request form rebuild to warn
   * about; empty "add another" rows are discarded; scalars are cast to their
   * canonical types.
   */
  private function formValuesToSettings(array $values): array {
    $selection = (string) ($values['source']['selection'] ?? '');
    if (\str_starts_with($selection, 'field:')) {
      return $this->fieldSourceSettings(\substr($selection, \strlen('field:')), $values);
    }
    // The bundle select predates the combined source control; accept both so a
    // client model written by either shape converts.
    $bundle = \str_starts_with($selection, 'bundle:')
      ? \substr($selection, \strlen('bundle:'))
      : (string) ($values['source']['bundle'] ?? '');
    $fields = [];
    $sortable = [];
    if ($bundle !== '' && \array_key_exists($bundle, $this->bundleInfo->getBundleInfo('node'))) {
      $fields = $this->fieldInfo->getFilterableFields('node', $bundle);
      $sortable = $this->fieldInfo->getSortableFields('node', $bundle);
    }

    $mode_select = (string) ($values['display']['mode_select'] ?? 'title_linked');
    $display = \str_starts_with($mode_select, 'view_mode:')
      ? ['mode' => 'view_mode', 'view_mode' => \substr($mode_select, \strlen('view_mode:'))]
      : ['mode' => $mode_select];
    // If the selected view mode is not available for the (possibly changed)
    // bundle, fall back to the built-in display instead of storing an invalid
    // reference.
    if ($display['mode'] === 'view_mode' && !\array_key_exists($display['view_mode'], $this->getViewModeOptions($bundle))) {
      $this->lastDroppedSettings[] = (string) $this->t('View mode @view_mode (replaced with Title (linked))', ['@view_mode' => $display['view_mode']]);
      $display = ['mode' => 'title_linked'];
    }

    // The client sends unchecked checkboxes as FALSE, '0', or the string
    // 'false' depending on the code path.
    // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponent::buildAnonymousFormForBlockPlugin()
    $raw_unlimited = $values['limit']['unlimited'] ?? FALSE;
    $unlimited = !empty($raw_unlimited) && $raw_unlimited !== 'false';
    $limit = $unlimited ? NULL : \max((int) ($values['limit']['count'] ?? 3), 1);

    $pagination_mode = (string) ($values['pagination']['mode'] ?? 'none');
    // UI enforcement of the "no limit implies infinite scroll" rule; the
    // settings validator rejects any other combination.
    if ($limit === NULL) {
      $pagination_mode = 'infinite_scroll';
    }
    $pagination = [
      'mode' => $pagination_mode,
      'page_size' => \min(\max((int) ($values['pagination']['page_size'] ?? 10), 1), ListElementSettingsValidator::MAX_PAGE_SIZE),
    ];

    $conditions = [];
    foreach ($values['filters']['conditions'] ?? [] as $row) {
      $field_name = (string) ($row['field'] ?? '');
      if ($field_name === '') {
        continue;
      }
      if (!\array_key_exists($field_name, $fields)) {
        $this->lastDroppedSettings[] = (string) $this->t('Filter on @field', ['@field' => $this->droppedFieldLabel($field_name)]);
        continue;
      }
      $conditions[] = $this->formRowToCondition($row, $fields[$field_name]);
    }

    $sorts = [];
    foreach ($values['sorts'] ?? [] as $row) {
      $field_name = (string) ($row['field'] ?? '');
      if ($field_name === '') {
        continue;
      }
      if (!\array_key_exists($field_name, $sortable)) {
        $this->lastDroppedSettings[] = (string) $this->t('Sort by @field', ['@field' => $this->droppedFieldLabel($field_name)]);
        continue;
      }
      // Row order is the sort order: earlier rows take precedence.
      $sorts[] = [
        'field' => $field_name,
        'direction' => ($row['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc',
      ];
    }

    $layout = self::formValuesToLayout($values);

    return [
      'source' => ['entity_type' => 'node', 'bundle' => $bundle],
      'display' => $display,
      'limit' => $limit,
      'pagination' => $pagination,
      'filters' => [
        'conjunction' => ($values['filters']['conjunction'] ?? 'and') === 'or' ? 'or' : 'and',
        'conditions' => $conditions,
      ],
      'sorts' => $sorts,
      'layout' => $layout,
    ];
  }

  /**
   * Maps form values to the canonical layout settings, shared by both kinds.
   */
  private static function formValuesToLayout(array $values): array {
    $layout_mode = (string) ($values['layout']['mode'] ?? 'stack');
    if (!\in_array($layout_mode, ListElementSettingsValidator::LAYOUT_MODES, TRUE)) {
      $layout_mode = 'stack';
    }
    $gap = (string) ($values['layout']['gap'] ?? 'medium');
    $layout = [
      'mode' => $layout_mode,
      'gap' => \in_array($gap, ListElementSettingsValidator::GAPS, TRUE) ? $gap : 'medium',
    ];
    if ($layout_mode === 'stack') {
      $stack_options = [
        'distribute' => ListElementSettingsValidator::DISTRIBUTIONS,
        'align' => ListElementSettingsValidator::ALIGNMENTS,
      ];
      foreach ($stack_options as $key => $allowed) {
        if (isset($values['layout'][$key]) && \in_array($values['layout'][$key], $allowed, TRUE)) {
          $layout[$key] = $values['layout'][$key];
        }
      }
    }
    if ($layout_mode === 'row') {
      $layout['items_per_row'] = \min(\max((int) ($values['layout']['items_per_row'] ?? 3), 1), ListElementSettingsValidator::MAX_PER_ROW);
    }
    if ($layout_mode === 'grid') {
      $layout['max_per_row'] = \min(\max((int) ($values['layout']['max_per_row'] ?? 3), 1), ListElementSettingsValidator::MAX_PER_ROW);
    }
    return $layout;
  }

  /**
   * Maps form values to canonical settings for a field source.
   *
   * Switching to a field source keeps the item template subtree and the layout,
   * and drops the settings that only shape a query. The dropped ones are named
   * in the same inline notice a bundle change already uses.
   */
  private function fieldSourceSettings(string $field_name, array $values): array {
    foreach ([
      'filters' => (string) $this->t('Filters'),
      'sorts' => (string) $this->t('Sorting'),
    ] as $key => $label) {
      if (($key === 'filters' ? ($values['filters']['conditions'] ?? []) : ($values[$key] ?? [])) !== []) {
        $this->lastDroppedSettings[] = $label;
      }
    }
    if (($values['pagination']['mode'] ?? 'none') !== 'none') {
      $this->lastDroppedSettings[] = (string) $this->t('Pagination');
    }

    $raw_unlimited = $values['limit']['unlimited'] ?? FALSE;
    $unlimited = !empty($raw_unlimited) && $raw_unlimited !== 'false';

    return [
      'source' => ['kind' => ListElementSettingsValidator::SOURCE_FIELD, 'field_name' => $field_name],
      // A field's values are only displayable through an item template.
      'display' => ['mode' => 'item_template'],
      'limit' => $unlimited ? NULL : \max((int) ($values['limit']['count'] ?? 3), 1),
      'pagination' => ['mode' => 'none', 'page_size' => 10],
      'filters' => ['conjunction' => 'and', 'conditions' => []],
      'sorts' => [],
      'layout' => self::formValuesToLayout($values),
    ];
  }

  /**
   * Resolves a human-readable label for a field dropped by a bundle switch.
   *
   * The field no longer exists on the newly selected bundle, so its label is
   * looked up on whichever bundle still defines it.
   */
  private function droppedFieldLabel(string $field_name): string {
    foreach (\array_keys($this->bundleInfo->getBundleInfo('node')) as $bundle) {
      $fields = $this->fieldInfo->getFilterableFields('node', $bundle);
      if (\array_key_exists($field_name, $fields)) {
        return $fields[$field_name]['label'];
      }
    }
    return $field_name;
  }

  /**
   * Maps one filter condition form row to its canonical stored form.
   *
   * @param array{label: string, family: ListElementFieldTypeFamily, has_target: bool, definition: FieldDefinitionInterface} $field
   */
  private function formRowToCondition(array $row, array $field): array {
    $family = $field['family'];
    \assert($family instanceof ListElementFieldTypeFamily);
    $allowed = $family->allowedOperators($field['has_target']);
    $operator = (string) ($row['operator'] ?? '');
    if (!\in_array($operator, $allowed, TRUE)) {
      // A new condition defaults to the first operator the select offers,
      // which is the family's foremost value operator.
      $operator = (string) \array_key_first(\array_intersect_key(self::operatorLabels(), \array_flip($allowed)));
    }
    $condition = ['field' => $field['definition']->getName(), 'operator' => $operator];

    if (\in_array($operator, [ListElementFieldTypeFamily::OP_IS_SET, ListElementFieldTypeFamily::OP_NOT_SET], TRUE)) {
      return $condition;
    }
    if ($operator === ListElementFieldTypeFamily::OP_BETWEEN) {
      $value = \array_filter([
        'min' => (string) ($row['value']['min'] ?? ''),
        'max' => (string) ($row['value']['max'] ?? ''),
      ], static fn (string $v): bool => $v !== '');
      if ($value !== []) {
        $condition['value'] = $value;
      }
      return $condition;
    }

    $raw = $row['value'] ?? NULL;
    if ($raw === NULL || $raw === '' || \is_array($raw)) {
      return $condition;
    }
    $condition['value'] = self::castConditionValue((string) $raw, $family, $field['definition']);
    // A value that cannot be cast (e.g. unresolvable autocomplete input)
    // stays an inert, value-less condition.
    if ($condition['value'] === NULL) {
      unset($condition['value']);
    }
    return $condition;
  }

  /**
   * Casts a raw form value to the condition value's canonical type.
   */
  private static function castConditionValue(string $raw, ListElementFieldTypeFamily $family, FieldDefinitionInterface $definition): string|int|float|bool|null {
    switch ($family) {
      case ListElementFieldTypeFamily::Reference:
        $id = EntityAutocomplete::extractEntityIdFromAutocompleteInput($raw) ?? (\ctype_digit($raw) ? $raw : NULL);
        return $id === NULL ? NULL : (int) $id;

      case ListElementFieldTypeFamily::Number:
        return \is_numeric($raw) ? $raw + 0 : NULL;

      case ListElementFieldTypeFamily::Options:
        // Assigned rather than returned directly: the scope indent sniff
        // misjudges the first arm of a match returned inside a switch case.
        $value = match ($definition->getType()) {
          'boolean' => $raw === '1',
          'list_integer' => (int) $raw,
          'list_float' => (float) $raw,
          default => $raw,
        };
        return $value;

      default:
        return $raw;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateComponentInput(array $inputValues, string $component_instance_uuid, ?FieldableEntityInterface $entity, ?ComponentTreeItem $item = NULL): ConstraintViolationListInterface {
    return $this->translateConstraintPropertyPathsAndRoot(
      ['' => \sprintf('inputs.%s.', $component_instance_uuid)],
      $this->settingsValidator->validate(
        $inputValues,
        self::resolveHostBundleContext($item) ?? self::hostBundleContextOf($entity),
        // A component tree stored in config is validated one node at a time,
        // detached from the config entity that owns it, so there is nothing to
        // read a bundle context from. Absence is then not evidence of absence.
        host_context_is_known: $entity !== NULL || $item?->getRoot() instanceof EntityAdapter,
      ),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildComponentInstanceForm(
    array $form,
    FormStateInterface $form_state,
    ComponentEntity $component,
    string $component_instance_uuid = '',
    array $inputValues = [],
    ?EntityInterface $entity = NULL,
    array $settings = [],
  ): array {
    $values = $inputValues !== [] ? $inputValues : $this->getDefaultExplicitInput();
    $is_field_source = ListElementSettingsValidator::sourceKind($values) === ListElementSettingsValidator::SOURCE_FIELD;
    $bundle = (string) ($values['source']['bundle'] ?? '');
    $bundles = $this->bundleInfo->getBundleInfo('node');
    $bundle_is_valid = !$is_field_source && \array_key_exists($bundle, $bundles);
    $fields = $bundle_is_valid ? $this->fieldInfo->getFilterableFields('node', $bundle) : [];
    $sortable = $bundle_is_valid ? $this->fieldInfo->getSortableFields('node', $bundle) : [];
    // A field source is only offered where the tree has a bundle-specific host
    // entity to read the field from.
    // @see \Drupal\canvas\ListBuilder\ListElementSettingsValidator::validate()
    $host_context = self::hostBundleContextOf($entity);
    $iterable_fields = $host_context === NULL
      ? []
      : $this->fieldInfo->getMultiValueFields($host_context['entity_type'], $host_context['bundle']);

    $form['#tree'] = TRUE;

    // Settings dropped by the most recent conversion (e.g. conditions on
    // fields the newly selected content type lacks) surface as an inline
    // warning on the rebuilt form.
    $dropped = [...$this->lastDroppedSettings, ...$this->claimDroppedSettingsWarnings($component_instance_uuid)];
    if ($dropped !== []) {
      $form['dropped_warning'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['canvas-list-element-form__dropped-warning']],
        '#value' => $this->t('Removed because they do not apply to the selected content type: @dropped.', ['@dropped' => \implode(', ', $dropped)]),
      ];
    }

    // One control: an editor picks what the list is *of*, and the rest of the
    // panel follows from that choice.
    // The two origins are spelled out per option rather than expressed as
    // `<optgroup>`s. Canvas renders Drupal selects through React, and that
    // renderer flattens `#options` to one level and emits every entry as an
    // `<option>`: a grouped select would show the two group labels as its only
    // choices and silently drop every real one, so the source could never be
    // saved. Grouping needs the twig/propsify/Select chain to carry nested
    // options first.
    // @see ui/src/components/form/components/Select.tsx
    // @see docs/adr/0021-item-template-data-context-is-a-field-item.md
    $selection_options = [];
    foreach ($bundles as $bundle_name => $info) {
      $selection_options['bundle:' . $bundle_name] = (string) $this->t('Content query: @bundle', ['@bundle' => $info['label']]);
    }
    foreach ($iterable_fields as $field_name => $field) {
      $selection_options['field:' . $field_name] = (string) $this->t("This entity's fields: @field", ['@field' => $field['label']]);
    }
    $form['source'] = [
      '#type' => 'details',
      '#title' => $this->t('Content source'),
      '#open' => TRUE,
      'selection' => [
        '#type' => 'select',
        '#title' => $this->t('Show'),
        '#options' => $selection_options,
        '#default_value' => $is_field_source
          ? 'field:' . ($values['source']['field_name'] ?? '')
          : 'bundle:' . $bundle,
      ],
    ];

    $view_mode_options = $this->getViewModeOptions($bundle);
    $display_options = ['title_linked' => (string) $this->t('Title (linked)')];
    foreach ($view_mode_options as $id => $label) {
      $display_options['view_mode:' . $id] = (string) $label;
    }
    $display_options['item_template'] = (string) $this->t('Components (item template)');
    if ($is_field_source) {
      // A view mode or a link renders an entity; a field item is not one.
      $display_options = ['item_template' => $display_options['item_template']];
    }
    $form['display'] = [
      '#type' => 'details',
      '#title' => $this->t('Item display'),
      '#open' => TRUE,
      'mode_select' => [
        '#type' => 'select',
        '#title' => $this->t('Display items as'),
        '#options' => $display_options,
        '#default_value' => ($values['display']['mode'] ?? 'title_linked') === 'view_mode'
          ? 'view_mode:' . ($values['display']['view_mode'] ?? '')
          : ($values['display']['mode'] ?? 'title_linked'),
        '#description' => $this->getManageDisplayDescription($bundle, $bundle_is_valid),
      ],
    ];

    $unlimited = !\array_key_exists('limit', $values) || $values['limit'] === NULL;
    $form['limit'] = [
      '#type' => 'details',
      '#title' => $this->t('Number of items'),
      '#open' => TRUE,
      'unlimited' => [
        '#type' => 'checkbox',
        '#title' => $this->t('No limit (show all items)'),
        '#default_value' => $unlimited,
        '#description' => $this->t('Lists without a limit always load further items as the visitor scrolls.'),
      ],
    ];
    if (!$unlimited) {
      $form['limit']['count'] = [
        '#type' => 'number',
        '#title' => $this->t('Maximum items'),
        '#min' => 1,
        '#default_value' => $values['limit'] ?? 3,
      ];
    }

    // Filters, sorts and pagination shape a query. A field's values are host
    // entity data in the order the content editor arranged them, so those
    // groups are hidden rather than disabled: a disabled filter builder invites
    // "why can I not use this", a hidden one does not.
    if ($is_field_source) {
      $form['limit']['unlimited']['#description'] = $this->t('Shows every value of this field.');
      return $form + $this->buildLayoutForm($values);
    }

    $form['pagination'] = [
      '#type' => 'details',
      '#title' => $this->t('Pagination'),
      '#open' => FALSE,
      'mode' => [
        '#type' => 'select',
        '#title' => $this->t('Style'),
        '#options' => $unlimited
          ? ['infinite_scroll' => (string) $this->t('Infinite scroll')]
          : [
            'none' => (string) $this->t('None'),
            'load_more' => (string) $this->t('Load more button'),
            'infinite_scroll' => (string) $this->t('Infinite scroll'),
          ],
        '#default_value' => $unlimited ? 'infinite_scroll' : ($values['pagination']['mode'] ?? 'none'),
      ],
    ];
    if ($unlimited || ($values['pagination']['mode'] ?? 'none') !== 'none') {
      $form['pagination']['page_size'] = [
        '#type' => 'number',
        '#title' => $this->t('Items per page'),
        '#min' => 1,
        '#max' => ListElementSettingsValidator::MAX_PAGE_SIZE,
        '#default_value' => $values['pagination']['page_size'] ?? 10,
      ];
    }

    $conditions = $values['filters']['conditions'] ?? [];
    $form['filters'] = [
      '#type' => 'details',
      '#title' => $this->t('Filters'),
      '#open' => $conditions !== [],
    ];
    if (\count($conditions) > 1) {
      $form['filters']['conjunction'] = [
        '#type' => 'select',
        '#title' => $this->t('Items must match'),
        '#options' => [
          'and' => (string) $this->t('All conditions'),
          'or' => (string) $this->t('Any condition'),
        ],
        '#default_value' => $values['filters']['conjunction'] ?? 'and',
      ];
    }
    $form['filters']['conditions'] = [];
    foreach ($conditions as $delta => $condition) {
      $form['filters']['conditions'][$delta] = $this->buildConditionRow($condition, $fields);
    }
    // One trailing empty row acts as the "add a condition" control: selecting
    // a field in it creates the condition, and the AJAX rebuild appends a
    // fresh empty row.
    if ($bundle_is_valid) {
      $form['filters']['conditions'][] = $this->buildConditionRow(NULL, $fields);
    }

    $sorts = $values['sorts'] ?? [];
    $form['sorts'] = [
      '#type' => 'details',
      '#title' => $this->t('Sorting'),
      '#open' => FALSE,
      '#description' => \count($sorts) > 1 ? $this->t('Sorts apply in the order listed.') : NULL,
    ];
    foreach ($sorts as $delta => $sort) {
      $form['sorts'][$delta] = $this->buildSortRow($sort, $sortable);
    }
    if ($bundle_is_valid) {
      $form['sorts'][] = $this->buildSortRow(NULL, $sortable);
    }

    $form += $this->buildLayoutForm($values);
    return $form;
  }

  /**
   * Builds the layout group, which both source kinds share.
   */
  private function buildLayoutForm(array $values): array {
    $form = [];
    $layout = $values['layout'] ?? ['mode' => 'stack', 'gap' => 'medium'];
    $form['layout'] = [
      '#type' => 'details',
      '#title' => $this->t('Layout'),
      '#open' => FALSE,
      'mode' => [
        '#type' => 'select',
        '#title' => $this->t('Arrange items as'),
        '#options' => [
          'stack' => (string) $this->t('Stack'),
          'row' => (string) $this->t('Row'),
          'grid' => (string) $this->t('Grid'),
        ],
        '#default_value' => $layout['mode'],
      ],
      'gap' => [
        '#type' => 'select',
        '#title' => $this->t('Item spacing'),
        '#options' => [
          'none' => (string) $this->t('None'),
          'small' => (string) $this->t('Small'),
          'medium' => (string) $this->t('Medium'),
          'large' => (string) $this->t('Large'),
        ],
        '#default_value' => $layout['gap'],
      ],
    ];
    if ($layout['mode'] === 'stack') {
      $form['layout']['distribute'] = [
        '#type' => 'select',
        '#title' => $this->t('Distribution'),
        '#options' => [
          'start' => (string) $this->t('Start'),
          'center' => (string) $this->t('Center'),
          'end' => (string) $this->t('End'),
          'space_between' => (string) $this->t('Space between'),
        ],
        '#default_value' => $layout['distribute'] ?? 'start',
      ];
      $form['layout']['align'] = [
        '#type' => 'select',
        '#title' => $this->t('Horizontal alignment'),
        '#options' => [
          'stretch' => (string) $this->t('Stretch'),
          'start' => (string) $this->t('Start'),
          'center' => (string) $this->t('Center'),
          'end' => (string) $this->t('End'),
        ],
        '#default_value' => $layout['align'] ?? 'stretch',
      ];
    }
    if ($layout['mode'] === 'row') {
      $form['layout']['items_per_row'] = [
        '#type' => 'number',
        '#title' => $this->t('Items per row'),
        '#min' => 1,
        '#max' => ListElementSettingsValidator::MAX_PER_ROW,
        '#default_value' => $layout['items_per_row'] ?? 3,
      ];
    }
    if ($layout['mode'] === 'grid') {
      $form['layout']['max_per_row'] = [
        '#type' => 'number',
        '#title' => $this->t('Maximum items per row'),
        '#min' => 1,
        '#max' => ListElementSettingsValidator::MAX_PER_ROW,
        '#default_value' => $layout['max_per_row'] ?? 3,
      ];
    }

    return $form;
  }

  /**
   * Builds one filter condition row.
   *
   * @param array|null $condition
   *   The stored condition, or NULL for the trailing "add a condition" row.
   * @param array<string, array{label: string, family: ListElementFieldTypeFamily, has_target: bool, definition: FieldDefinitionInterface}> $fields
   */
  private function buildConditionRow(?array $condition, array $fields): array {
    $field_options = \array_map(static fn (array $field): string => $field['label'], $fields);
    \asort($field_options);
    $row = [
      '#type' => 'container',
      '#attributes' => ['class' => ['canvas-list-element-form__condition']],
      'field' => [
        '#type' => 'select',
        '#title' => $condition === NULL ? $this->t('Add a condition') : $this->t('Field'),
        '#options' => $field_options,
        '#empty_option' => $condition === NULL ? $this->t('- Select a field -') : $this->t('- Remove this condition -'),
        '#empty_value' => '',
        '#default_value' => $condition['field'] ?? '',
      ],
    ];
    if ($condition === NULL || !\array_key_exists($condition['field'], $fields)) {
      return $row;
    }

    $field = $fields[$condition['field']];
    $family = $field['family'];
    \assert($family instanceof ListElementFieldTypeFamily);
    $operator_labels = self::operatorLabels();
    $row['operator'] = [
      '#type' => 'select',
      '#title' => $this->t('Operator'),
      '#options' => \array_intersect_key($operator_labels, \array_flip($family->allowedOperators($field['has_target']))),
      '#default_value' => $condition['operator'],
    ];

    $value_element = $this->buildConditionValueElement($condition, $family, $field['definition']);
    if ($value_element !== NULL) {
      $row['value'] = $value_element;
    }
    return $row;
  }

  /**
   * Builds the value element for a condition row, or NULL for presence ops.
   */
  private function buildConditionValueElement(array $condition, ListElementFieldTypeFamily $family, FieldDefinitionInterface $definition): ?array {
    $operator = $condition['operator'];
    if (\in_array($operator, [ListElementFieldTypeFamily::OP_IS_SET, ListElementFieldTypeFamily::OP_NOT_SET], TRUE)) {
      return NULL;
    }
    $value = $condition['value'] ?? NULL;

    if ($operator === ListElementFieldTypeFamily::OP_BETWEEN) {
      return [
        'min' => [
          '#type' => 'date',
          '#title' => $this->t('From'),
          '#default_value' => $value['min'] ?? '',
        ],
        'max' => [
          '#type' => 'date',
          '#title' => $this->t('To'),
          '#default_value' => $value['max'] ?? '',
        ],
      ];
    }

    switch ($family) {
      case ListElementFieldTypeFamily::Date:
        return [
          '#type' => 'date',
          '#title' => $this->t('Value'),
          '#default_value' => $value ?? '',
        ];

      case ListElementFieldTypeFamily::Number:
        return [
          '#type' => 'number',
          '#title' => $this->t('Value'),
          '#step' => 'any',
          '#default_value' => $value ?? '',
        ];

      case ListElementFieldTypeFamily::Options:
        if ($definition->getType() === 'boolean') {
          // Until a value is chosen the condition is inert, so the select
          // must not pretend one is selected.
          return [
            '#type' => 'select',
            '#title' => $this->t('Value'),
            '#options' => ['1' => (string) $this->t('Yes'), '0' => (string) $this->t('No')],
            '#empty_option' => $this->t('- Select -'),
            '#empty_value' => '',
            '#default_value' => $value === NULL ? '' : (string) ((int) $value),
          ];
        }
        $allowed_values = $definition->getFieldStorageDefinition()->getSetting('allowed_values');
        if (\is_array($allowed_values) && $allowed_values !== []) {
          return [
            '#type' => 'select',
            '#title' => $this->t('Value'),
            '#options' => \array_map(strval(...), $allowed_values),
            '#empty_option' => $this->t('- Select -'),
            '#empty_value' => '',
            '#default_value' => $value === NULL ? '' : (string) $value,
          ];
        }
        return [
          '#type' => 'textfield',
          '#title' => $this->t('Value'),
          '#default_value' => $value === NULL ? '' : (string) $value,
        ];

      case ListElementFieldTypeFamily::Reference:
        $target_type = $definition->getFieldStorageDefinition()->getSetting('target_type');
        $handler_settings = $definition->getSetting('handler_settings') ?? [];
        return [
          '#type' => 'entity_autocomplete',
          '#title' => $this->t('Value'),
          '#target_type' => $target_type,
          '#selection_handler' => $definition->getSetting('handler') ?? 'default:' . $target_type,
          '#selection_settings' => $handler_settings,
          '#default_value' => \is_int($value) ? $this->entityTypeManager->getStorage($target_type)->load($value) : NULL,
        ];

      default:
        return [
          '#type' => 'textfield',
          '#title' => $this->t('Value'),
          '#default_value' => $value === NULL ? '' : (string) $value,
        ];
    }
  }

  /**
   * Builds one sort row.
   */
  private function buildSortRow(?array $sort, array $sortable): array {
    $field_options = \array_map(static fn (array $field): string => $field['label'], $sortable);
    \asort($field_options);
    $row = [
      '#type' => 'container',
      '#attributes' => ['class' => ['canvas-list-element-form__sort']],
      'field' => [
        '#type' => 'select',
        '#title' => $sort === NULL ? $this->t('Add a sort') : $this->t('Field'),
        '#options' => $field_options,
        '#empty_option' => $sort === NULL ? $this->t('- Select a field -') : $this->t('- Remove this sort -'),
        '#empty_value' => '',
        '#default_value' => $sort['field'] ?? '',
      ],
    ];
    if ($sort === NULL || !\array_key_exists($sort['field'], $sortable)) {
      return $row;
    }

    $family = $sortable[$sort['field']]['family'];
    \assert($family instanceof ListElementFieldTypeFamily);
    $direction_labels = match ($family) {
      ListElementFieldTypeFamily::Date => ['asc' => $this->t('Old to new'), 'desc' => $this->t('New to old')],
      ListElementFieldTypeFamily::Number => ['asc' => $this->t('Low to high'), 'desc' => $this->t('High to low')],
      default => ['asc' => $this->t('A to Z'), 'desc' => $this->t('Z to A')],
    };
    $row['direction'] = [
      '#type' => 'select',
      '#title' => $this->t('Order'),
      '#options' => \array_map(strval(...), $direction_labels),
      '#default_value' => $sort['direction'],
    ];
    return $row;
  }

  /**
   * The human-readable labels of all condition operators.
   *
   * The order here is the order of the operator select options, and the first
   * operator a field family allows is the default for a new condition: value
   * operators lead because they are what conditions are almost always about,
   * and the presence checks close the list.
   *
   * @return array<string, string>
   */
  private static function operatorLabels(): array {
    return [
      ListElementFieldTypeFamily::OP_CONTAINS => (string) new TranslatableMarkup('Contains'),
      ListElementFieldTypeFamily::OP_NOT_CONTAINS => (string) new TranslatableMarkup('Does not contain'),
      ListElementFieldTypeFamily::OP_STARTS_WITH => (string) new TranslatableMarkup('Starts with'),
      ListElementFieldTypeFamily::OP_ENDS_WITH => (string) new TranslatableMarkup('Ends with'),
      ListElementFieldTypeFamily::OP_EQUALS => (string) new TranslatableMarkup('Is'),
      ListElementFieldTypeFamily::OP_NOT_EQUALS => (string) new TranslatableMarkup('Is not'),
      ListElementFieldTypeFamily::OP_BETWEEN => (string) new TranslatableMarkup('Is between'),
      ListElementFieldTypeFamily::OP_GREATER_THAN => (string) new TranslatableMarkup('Greater than'),
      ListElementFieldTypeFamily::OP_GREATER_THAN_OR_EQUAL => (string) new TranslatableMarkup('Greater than or equal to'),
      ListElementFieldTypeFamily::OP_LESS_THAN => (string) new TranslatableMarkup('Less than'),
      ListElementFieldTypeFamily::OP_LESS_THAN_OR_EQUAL => (string) new TranslatableMarkup('Less than or equal to'),
      ListElementFieldTypeFamily::OP_IS_SET => (string) new TranslatableMarkup('Is set'),
      ListElementFieldTypeFamily::OP_NOT_SET => (string) new TranslatableMarkup('Is not set'),
    ];
  }

  /**
   * Returns the view mode options for a bundle.
   *
   * @return array<string, string>
   */
  private function getViewModeOptions(string $bundle): array {
    if ($bundle === '' || !\array_key_exists($bundle, $this->bundleInfo->getBundleInfo('node'))) {
      return [];
    }
    return \array_map(strval(...), $this->displayRepository->getViewModeOptionsByBundle('node', $bundle));
  }

  /**
   * Builds the manage-display link shown under the display select.
   */
  private function getManageDisplayDescription(string $bundle, bool $bundle_is_valid): TranslatableMarkup|string {
    if (!$bundle_is_valid) {
      return '';
    }
    try {
      $url = Url::fromRoute('entity.entity_view_display.node.default', ['node_type' => $bundle]);
      if ($url->access()) {
        return $this->t('<a href=":url" target="_blank">Manage this content type\'s view modes</a> to change how items can be displayed.', [':url' => $url->toString()]);
      }
    }
    catch (\Exception) {
      // The route does not exist without the Field UI module; omit the link.
    }
    return '';
  }

}
