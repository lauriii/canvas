<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Storage\ComponentTreeLoader;
use Drupal\canvas\Tmgmt\ComponentInputsConfigProcessor;
use Drupal\canvas\Tmgmt\ComponentInputsTranslatablesExtractor;
use Drupal\canvas\Tmgmt\ComponentTreeFieldProcessor;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\ContentEntityFormInterface;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\tmgmt\Data;
use Drupal\tmgmt\JobItemInterface;
use Drupal\tmgmt_local\Entity\LocalTaskItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Hook implementations for TMGMT integration.
 */
final readonly class TmgmtHooks {

  public function __construct(
    private ModuleHandlerInterface $moduleHandler,
    private EntityTypeManagerInterface $entityTypeManager,
    private ComponentTreeLoader $componentTreeLoader,
    // TMGMT is an optional dependency: NULL when the module is not installed.
    #[Autowire(service: 'tmgmt.data')]
    private ?Data $tmgmtData = NULL,
  ) {}

  /**
   * Implements hook_config_schema_info_alter().
   */
  #[Hook('config_schema_info_alter')]
  public static function configSchemaInfoAlter(array &$definitions): void {
    // 'canvas.pattern.*' is intentionally left out of this list as patterns are
    // not translatable.
    $types_with_component_trees = [
      'canvas.content_template.*.*.*',
      'canvas.page_variant.*',
    ];
    foreach ($types_with_component_trees as $types_with_component_tree) {
      if (isset($definitions[$types_with_component_tree])) {
        $definitions[$types_with_component_tree]['tmgmt_config_processor'] = ComponentInputsConfigProcessor::class;
      }
    }
  }

  /**
   * Implements hook_field_info_alter().
   *
   * Registers ComponentTreeFieldProcessor for component_tree fields when
   * tmgmt_content is enabled, so each translatable prop in `inputs` appears as
   * a separate translatable string in the TMGMT review UI.
   *
   * @todo Refactor to use `HookDependsOnModule` once Canvas depends on Drupal 11.5
   * @see https://www.drupal.org/node/3548805
   */
  #[Hook('field_info_alter')]
  public function fieldInfoAlter(array &$info): void {
    if (isset($info[ComponentTreeItem::PLUGIN_ID]) && $this->moduleHandler->moduleExists('tmgmt_content')) {
      $info[ComponentTreeItem::PLUGIN_ID]['tmgmt_field_processor'] = ComponentTreeFieldProcessor::class;
    }
  }

  /**
   * Implements hook_tmgmt_data_item_text_output_alter().
   *
   * Blanks the ∅ sentinel out of both review form columns. The sentinel is an
   * internal marker only (TMGMT's Data::flatten() strips empty strings, so
   * extraction must produce a non-empty #text for empty source inputs); it
   * must never be shown to translators. With an empty $source_text, TMGMT
   * builds no source textarea at all, and formTmgmtJobItemEditFormAlter()
   * replaces it with an explanatory message.
   *
   * Only content job items whose entity has a Canvas field are altered.
   * Config job items (ContentTemplate, PageRegion, via
   * ComponentInputsConfigProcessor) still show the sentinel.
   *
   * @todo Extend the sentinel handling to config job items in https://git.drupalcode.org/project/canvas/-/work_items/3592014
   *
   * @param string $source_text
   *   The source text to display, passed by reference.
   * @param string $translation_text
   *   The translation text to display, passed by reference.
   * @param array<string, mixed> $context
   *   Context including 'data_item' and 'job_item'.
   *
   * @see \Drupal\tmgmt\Form\JobItemForm::buildSource()
   */
  #[Hook('tmgmt_data_item_text_output_alter')]
  public function tmgmtDataItemTextOutputAlter(string &$source_text, string &$translation_text, array $context): void {
    $job_item = $context['job_item'] ?? NULL;
    if (!$job_item instanceof JobItemInterface) {
      return;
    }
    $entity = $this->getContentJobItemEntity($job_item);
    if ($entity === NULL || $this->getCanvasFieldName($entity) === NULL) {
      return;
    }
    if ($source_text === ComponentInputsTranslatablesExtractor::EMPTY_SENTINEL) {
      $source_text = '';
    }
    if ($translation_text === ComponentInputsTranslatablesExtractor::EMPTY_SENTINEL) {
      $translation_text = '';
    }
  }

  /**
   * Gets the name of the Canvas field on an entity, if any.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity.
   *
   * @return string|null
   *   The Canvas field name, or NULL if the entity has none or its entity type
   *   or bundle is not (yet) supported by Canvas.
   *
   * @see \Drupal\canvas\Storage\ComponentTreeLoader::getCanvasFieldName()
   */
  private function getCanvasFieldName(FieldableEntityInterface $entity): ?string {
    try {
      return $this->componentTreeLoader->getCanvasFieldName($entity);
    }
    catch (\LogicException) {
      return NULL;
    }
  }

  /**
   * Implements hook_form_tmgmt_job_item_edit_form_alter().
   *
   * For every component_tree row on the TMGMT review form:
   * - Replaces the (absent) source textarea of inputs that are empty in the
   *   source language with an explanatory message.
   * - Wires validation handlers that temporarily mask empty translations of
   *   OPTIONAL component inputs so TMGMT's "The field is empty." validation
   *   does not fire for them: an empty translation of an optional input is
   *   valid and is stored as an explicitly empty string. Required inputs keep
   *   TMGMT's error — Canvas rejects an empty required prop in any language.
   *
   * @param array<string, mixed> $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @see \Drupal\tmgmt\Form\JobItemForm::validateJobItem()
   * @see \Drupal\canvas\Tmgmt\ComponentTreeFieldProcessor::setTranslations()
   * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::validateComponentInput()
   */
  #[Hook('form_tmgmt_job_item_edit_form_alter')]
  public function formTmgmtJobItemEditFormAlter(array &$form, FormStateInterface $form_state): void {
    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof EntityFormInterface) {
      return;
    }
    $item = $form_object->getEntity();
    if (!$item instanceof JobItemInterface) {
      return;
    }
    $entity = $this->getContentJobItemEntity($item);
    if ($entity === NULL) {
      return;
    }
    $field_name = $this->getCanvasFieldName($entity);
    if ($field_name === NULL) {
      return;
    }

    $row_paths = self::getComponentTreeRowPaths($form, $field_name);
    if ($row_paths === []) {
      return;
    }

    // This hook only fires for TMGMT's own form, so the service exists.
    \assert($this->tmgmtData !== NULL);
    $maskable_row_paths = [];
    foreach ($row_paths as [$group_key, $parent_key, $field_key]) {
      $row = &$form['review'][$group_key][$parent_key][$field_key];
      $data_item = $item->getData($this->tmgmtData->ensureArrayKey($field_key));

      // tmgmtDataItemTextOutputAlter() blanked the sentinel, so TMGMT built no
      // source element for this row; show why instead of an empty cell.
      // @see \Drupal\tmgmt\Form\JobItemForm::buildSource()
      if (($data_item['#text'] ?? '') === ComponentInputsTranslatablesExtractor::EMPTY_SENTINEL && !isset($row['source'])) {
        $row['source'] = [
          '#type' => 'item',
          '#markup' => new TranslatableMarkup('This field is empty in the source language.'),
        ];
      }

      if (!self::rowIsRequiredProp($entity, $field_name, $field_key)) {
        $maskable_row_paths[] = [$group_key, $parent_key, $field_key];
      }
      unset($row);
    }
    // Persisted for the validate callbacks below; recomputed on every
    // (re)build of the form.
    $form_state->set('canvas_component_tree_maskable_row_paths', $maskable_row_paths);

    // The accept ("Save as completed") and validate buttons run TMGMT's
    // ::validateJobItem, which errors on empty translations. Mask empty
    // component_tree translations with the sentinel before it runs and unmask
    // afterwards (so a validation error elsewhere on the form never re-renders
    // the sentinel in a textarea). ::validateJobItem reads #value from $form
    // while ::save() reads $form_state values, so the mask is invisible to
    // what gets saved.
    // @see \Drupal\tmgmt\Form\JobItemForm::actions()
    foreach (['accept', 'validate'] as $button) {
      if (isset($form['actions'][$button]['#validate'])) {
        \array_unshift(
          $form['actions'][$button]['#validate'],
          [self::class, 'maskEmptyComponentTreeTranslations'],
        );
        $form['actions'][$button]['#validate'][] = [self::class, 'unmaskEmptyComponentTreeTranslations'];
      }
    }
  }

  /**
   * Implements hook_form_tmgmt_local_task_item_edit_form_alter().
   *
   * The tmgmt_local LocalTaskItemForm is a parallel, self-contained form that
   * has no hook extension points of its own (unlike JobItemForm, which fires
   * hook_tmgmt_data_item_text_output_alter). This alter hook must therefore
   * replicate both concerns that JobItemForm delegates to hooks and to
   * formTmgmtJobItemEditFormAlter():
   *
   * 1. Sentinel display: LocalTaskItemForm::formElement() writes the raw
   *    $data[$key]['#text'] directly into the source textarea's #value. If that
   *    text is the ∅ sentinel (an empty source input), replace the textarea
   *    with an explanatory message, exactly as formTmgmtJobItemEditFormAlter()
   *    does for JobItemForm rows where the hook already blanked the source.
   *
   * 2. Validation masking: LocalTaskItemForm::validateSaveAsComplete() reads
   *    $form_state->getValues()[$field_key]['translation'] and raises "Missing
   *    translation." for any empty value. Unlike JobItemForm's
   *    validateJobItem() (which reads $form[...]['#value']), this reads form
   *    state — so the mask must temporarily set sentinel values in form state,
   *    not in $form. New static callbacks maskEmptyLocalTaskTranslations() /
   *    unmaskEmptyLocalTaskTranslations() handle this.
   *
   * @param array<string, mixed> $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @see \Drupal\tmgmt_local\Form\LocalTaskItemForm::validateSaveAsComplete()
   * @see \Drupal\canvas\Hook\TmgmtHooks::formTmgmtJobItemEditFormAlter()
   */
  #[Hook('form_tmgmt_local_task_item_edit_form_alter')]
  public function formTmgmtLocalTaskItemEditFormAlter(array &$form, FormStateInterface $form_state): void {
    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof ContentEntityFormInterface) {
      return;
    }
    $task_item = $form_object->getEntity();
    if (!$task_item instanceof LocalTaskItem) {
      return;
    }
    $job_item = $task_item->getJobItem();
    $entity = $this->getContentJobItemEntity($job_item);
    if ($entity === NULL) {
      return;
    }
    $field_name = $this->getCanvasFieldName($entity);
    if ($field_name === NULL) {
      return;
    }

    $row_paths = self::getLocalTaskItemRowPaths($form, $field_name);
    if ($row_paths === []) {
      return;
    }

    \assert($this->tmgmtData !== NULL);
    $maskable_field_keys = [];
    foreach ($row_paths as [$top_key, $field_key]) {
      $row = &$form['translation'][$top_key][$field_key];

      if (($row['source']['#value'] ?? '') === ComponentInputsTranslatablesExtractor::EMPTY_SENTINEL) {
        $row['source'] = [
          '#type' => 'item',
          '#markup' => new TranslatableMarkup('This field is empty in the source language.'),
        ];
      }

      if (!self::rowIsRequiredProp($entity, $field_name, $field_key)) {
        $maskable_field_keys[] = [$top_key, $field_key];
      }
      unset($row);
    }
    $form_state->set('canvas_local_task_maskable_field_keys', $maskable_field_keys);

    // validateSaveAsComplete() reads
    // $form_state->getValues()[$key]['translation'] and errors when it is
    // empty. Mask empty optional-prop translations in form state before it
    // runs; unmask after so a validation error from a required field never
    // re-renders the sentinel in optional textareas.
    // @see \Drupal\tmgmt_local\Form\LocalTaskItemForm::actions()
    if (isset($form['actions']['save_as_completed']['#validate'])) {
      \array_unshift(
        $form['actions']['save_as_completed']['#validate'],
        [self::class, 'maskEmptyLocalTaskTranslations'],
      );
      $form['actions']['save_as_completed']['#validate'][] = [self::class, 'unmaskEmptyLocalTaskTranslations'];
    }
  }

  /**
   * Form validate callback: masks empty local-task translations in form state.
   *
   * @param array<string, mixed> $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @see \Drupal\tmgmt_local\Form\LocalTaskItemForm::validateSaveAsComplete()
   */
  public static function maskEmptyLocalTaskTranslations(array &$form, FormStateInterface $form_state): void {
    $masked = [];
    foreach ($form_state->get('canvas_local_task_maskable_field_keys') ?? [] as [, $field_key]) {
      $value = $form_state->getValue([$field_key, 'translation']);
      if (\is_array($value)) {
        $sub_value = $value['value'] ?? NULL;
        if ($sub_value === '' || $sub_value === NULL) {
          $form_state->setValue(
            [$field_key, 'translation', 'value'],
            ComponentInputsTranslatablesExtractor::EMPTY_SENTINEL,
          );
          $masked[] = [$field_key, 'translation', 'value'];
        }
      }
      elseif ($value === '' || $value === NULL) {
        $form_state->setValue(
          [$field_key, 'translation'],
          ComponentInputsTranslatablesExtractor::EMPTY_SENTINEL,
        );
        $masked[] = [$field_key, 'translation'];
      }
    }
    $form_state->set('canvas_local_task_masked_keys', $masked);
  }

  /**
   * Form validate callback: restores masked local-task translations.
   *
   * @param array<string, mixed> $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function unmaskEmptyLocalTaskTranslations(array &$form, FormStateInterface $form_state): void {
    foreach ($form_state->get('canvas_local_task_masked_keys') ?? [] as $path) {
      $form_state->setValue($path, '');
    }
    $form_state->set('canvas_local_task_masked_keys', []);
  }

  /**
   * Form validate callback: masks empty component_tree translations with ∅.
   *
   * Runs before TMGMT's ::validateJobItem, which reads the translation from
   * $form[...]['translation']['#value'] and errors on empty values. An empty
   * translation of an OPTIONAL component input is valid (it is stored as an
   * explicitly empty string), so it is temporarily set to the non-empty
   * sentinel. Required inputs are not masked, so TMGMT's error still fires
   * for them. ::save() reads $form_state values, never these #value keys, so
   * the mask cannot leak into saved data.
   *
   * @param array<string, mixed> $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function maskEmptyComponentTreeTranslations(array &$form, FormStateInterface $form_state): void {
    $masked = [];
    foreach ($form_state->get('canvas_component_tree_maskable_row_paths') ?? [] as [$group_key, $parent_key, $field_key]) {
      $translation = &$form['review'][$group_key][$parent_key][$field_key]['translation'];
      // Rich text (text_format) is expanded to translation[value] during form
      // processing; plain text is the textarea itself.
      $value_path = isset($translation['value']) && \is_array($translation['value'])
        ? ['value', '#value']
        : ['#value'];
      $value = NestedArray::getValue($translation, $value_path);
      if ($value === '' || $value === NULL) {
        NestedArray::setValue($translation, $value_path, ComponentInputsTranslatablesExtractor::EMPTY_SENTINEL);
        $masked[] = [$group_key, $parent_key, $field_key, 'translation', ...$value_path];
      }
      unset($translation);
    }
    $form_state->set('canvas_tmgmt_masked_paths', $masked);
  }

  /**
   * Form validate callback: restores masked empty translations.
   *
   * Runs after TMGMT's ::validateJobItem. Without this, a validation error
   * elsewhere on the form (e.g. an empty required field of another type) would
   * re-render the form with the sentinel visible in the masked textareas.
   *
   * @param array<string, mixed> $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function unmaskEmptyComponentTreeTranslations(array &$form, FormStateInterface $form_state): void {
    foreach ($form_state->get('canvas_tmgmt_masked_paths') ?? [] as $path) {
      NestedArray::setValue($form['review'], $path, '');
    }
    $form_state->set('canvas_tmgmt_masked_paths', []);
  }

  /**
   * Loads the source entity of a content job item.
   *
   * @param \Drupal\tmgmt\JobItemInterface $item
   *   The TMGMT job item.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface|null
   *   The source entity, or NULL if the job item is not a content job item or
   *   its entity cannot be loaded.
   */
  private function getContentJobItemEntity(JobItemInterface $item): ?FieldableEntityInterface {
    if ($item->getPlugin() !== 'content') {
      return NULL;
    }
    if (!$this->entityTypeManager->hasDefinition($item->getItemType())) {
      return NULL;
    }
    $entity = $this->entityTypeManager->getStorage($item->getItemType())->load($item->getItemId());
    return $entity instanceof FieldableEntityInterface ? $entity : NULL;
  }

  /**
   * Determines whether a review form row belongs to a required prop.
   *
   * Canvas rejects an empty value for a required prop in any language
   * (an empty StaticPropSource counts as unset), so such rows must keep
   * TMGMT's "The field is empty." validation.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The source entity.
   * @param string $field_name
   *   The Canvas field name on the source entity.
   * @param string $field_key
   *   The '|'-joined flattened TMGMT data key, e.g. "components|0|heading" or
   *   "components|0|cta1href|uri".
   *
   * @return bool
   *   TRUE if the row belongs to a required prop, FALSE otherwise.
   *
   * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::validateComponentInput()
   */
  private static function rowIsRequiredProp(FieldableEntityInterface $entity, string $field_name, string $field_key): bool {
    if (!\str_starts_with($field_key, $field_name . '|')) {
      return FALSE;
    }
    // "<field_name>|<delta>|<prop>[|<nested…>]".
    $segments = \explode('|', \substr($field_key, \strlen($field_name) + 1));
    if (\count($segments) < 2) {
      return FALSE;
    }
    [$delta, $prop_name] = $segments;
    $field = $entity->get($field_name);
    if (!$field->offsetExists((int) $delta)) {
      return FALSE;
    }
    $item = $field->offsetGet((int) $delta);
    \assert($item instanceof ComponentTreeItem);
    // Inspect the Component version the tree item uses, not the active one:
    // a prop may have become (or stopped being) required in a newer version.
    $source = $item->getComponent()?->loadVersion($item->getComponentVersion())->getComponentSource();
    // Only JSON-schema-based component sources declare required props.
    if (!$source instanceof JsonSchemaPropsComponentSourceBase) {
      return FALSE;
    }
    $prop_field_definitions = $source->getConfiguration()['prop_field_definitions'] ?? [];
    return (bool) ($prop_field_definitions[$prop_name]['required'] ?? FALSE);
  }

  /**
   * Finds the review form rows that belong to the Canvas field.
   *
   * TMGMT's JobItemForm nests review rows three levels deep under
   * $form['review'], hence the three nested loops (the same traversal TMGMT
   * itself uses in ::validateJobItem()):
   * 1. group key: the top-level data key, i.e. the field name of the source
   *    entity (e.g. "components").
   * 2. parent key: the flattened data key minus its last segment, i.e. all
   *    rows that share a parent are grouped (e.g. "components|0" for every
   *    prop of the first tree item; "_none" for non-nested rows).
   * 3. field key: the full '|'-joined flattened data key of one row (e.g.
   *    "components|0|heading"), whose children are the row's 'source' and
   *    'translation' elements.
   *
   * @param array<string, mixed> $form
   *   The form array.
   * @param string $field_name
   *   The Canvas field name on the source entity.
   *
   * @return list<array{string, string, string}>
   *   [group key, parent key, field key] paths under $form['review'], where
   *   the field key is the '|'-joined flattened TMGMT data key (e.g.
   *   "components|0|heading").
   *
   * @see \Drupal\tmgmt\Form\JobItemForm::reviewFormElement()
   */
  private static function getComponentTreeRowPaths(array $form, string $field_name): array {
    $paths = [];
    if (!isset($form['review'])) {
      return [];
    }
    foreach (Element::children($form['review']) as $group_key) {
      foreach (Element::children($form['review'][$group_key]) as $parent_key) {
        foreach (Element::children($form['review'][$group_key][$parent_key]) as $field_key) {
          if (!isset($form['review'][$group_key][$parent_key][$field_key]['translation'])) {
            continue;
          }
          if ($field_key === $field_name || \str_starts_with((string) $field_key, $field_name . '|')) {
            $paths[] = [(string) $group_key, (string) $parent_key, (string) $field_key];
          }
        }
      }
    }
    return $paths;
  }

  /**
   * Finds the translation form rows that belong to the Canvas field.
   *
   * LocalTaskItemForm nests translation rows two levels deep under
   * $form['translation']:
   * 1. top key: the first segment of the TMGMT data key, i.e. the entity field
   *    name (e.g. "components").
   * 2. field key: the full '|'-joined flattened TMGMT data key of one row
   *    (e.g. "components|0|heading"), whose children are the row's 'source'
   *    and 'translation' elements.
   *
   * @param array<string, mixed> $form
   *   The form array.
   * @param string $field_name
   *   The Canvas field name on the source entity.
   *
   * @return list<array{string, string}>
   *   [top key, field key] paths under $form['translation'], where the field
   *   key is the '|'-joined flattened TMGMT data key (e.g.
   *   "components|0|heading").
   *
   * @see \Drupal\tmgmt_local\Form\LocalTaskItemForm::formElement()
   */
  private static function getLocalTaskItemRowPaths(array $form, string $field_name): array {
    $paths = [];
    if (!isset($form['translation'])) {
      return [];
    }
    foreach (Element::children($form['translation']) as $top_key) {
      foreach (Element::children($form['translation'][$top_key]) as $field_key) {
        if (!isset($form['translation'][$top_key][$field_key]['translation'])) {
          continue;
        }
        if ($field_key === $field_name || \str_starts_with((string) $field_key, $field_name . '|')) {
          $paths[] = [(string) $top_key, (string) $field_key];
        }
      }
    }
    return $paths;
  }

}
