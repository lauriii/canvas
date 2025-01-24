<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\experience_builder\Controller\ClientServerConversionTrait;
use Drupal\experience_builder\Exception\ConstraintViolationException;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\BlockComponent;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemInstantiatorTrait;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * @ConfigEntityType(
 *    id = \Drupal\experience_builder\Entity\PageTemplate::PLUGIN_ID,
 *    label = @Translation("Page template"),
 *    label_singular = @Translation("page template"),
 *    label_plural = @Translation("page templates"),
 *    label_collection = @Translation("Page templates"),
 *    admin_permission = "access administration pages",
 *    entity_keys = {
 *      "id" = "theme",
 *    },
 *    config_export = {
 *      "theme",
 *      "component_trees",
 *      "editable",
 *    },
 *    lookup_keys = {
 *      "theme",
 *    }
 *  )
 */
final class PageTemplate extends ConfigEntityBase {

  public const PLUGIN_ID = 'page_template';
  use ComponentTreeItemInstantiatorTrait;
  use ClientServerConversionTrait;

  /**
   * The theme that this defines the XB Page Template for.
   *
   * @var string
   */
  protected string $theme;

  /**
   * Component trees for each region.
   *
   * Keys are region names, values are either:
   * - if empty: `NULL`
   * - otherwise: a `type: experience_builder.component_tree`, which consists of
   *   a `tree` + `inputs` key-value pair.
   */
  protected ?array $component_trees;

  /**
   * Editable flag for each region.
   *
   * Keys are region names, values are boolean.
   *
   * @see experience_builder_form_system_theme_settings_alter()
   */
  protected ?array $editable;

  /**
   * {@inheritdoc}
   */
  public function label(): TranslatableMarkup {
    return new TranslatableMarkup('@theme global template', ['@theme' => \Drupal::service(ThemeExtensionList::class)->getName($this->theme)]);
  }

  /**
   * Creates a page template instance for the given auto-save data.
   *
   * @param array $autoSaveData
   *   Autosave data with 'layout' and 'model' keys.
   *
   * @return static
   *   New instance with given values.
   *
   * @throws \Drupal\experience_builder\Exception\ConstraintViolationException
   *   If violations exist and $throwOnViolations is TRUE.
   */
  public function forAutoSaveData(array $autoSaveData): static {
    $values = $this->toArray();
    // We always keep the original content region, that holds the main content
    // block.
    $treeItems = \array_intersect_key($values['component_trees'] ?? [], \array_flip(['content']));
    $allViolations = new ConstraintViolationList();
    foreach ($autoSaveData['layout'] as $region) {
      // Ignore auto-saved regions that are no longer editable.
      if (!$this->isEditableRegion($region['id'])) {
        continue;
      }

      try {
        $tree = self::clientLayoutToServerTree($region);
      }
      catch (ConstraintViolationException $e) {
        $allViolations->addAll($e->getConstraintViolationList());
        continue;
      }

      if (\count($tree[ComponentTreeStructure::ROOT_UUID]) === 0) {
        // Empty region.
        $treeItems[$region['id']] = NULL;
        continue;
      }

      // @todo This probably should be a method on ComponentTreeStructure, we
      // have the same code in several places.
      $definition = DataDefinition::create('component_tree_structure');
      $component_tree_structure = new ComponentTreeStructure($definition, 'component_tree_structure');
      $component_tree_structure->setValue(json_encode($tree, JSON_UNESCAPED_UNICODE));

      try {
        $inputs = $this->clientModelToInput($tree, \array_intersect_key($autoSaveData['model'], \array_flip($component_tree_structure->getComponentInstanceUuids())));
      }
      catch (ConstraintViolationException $e) {
        $allViolations->addAll($e->getConstraintViolationList());
        continue;
      }

      $treeItems[$region['id']] = [
        'tree' => \json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR),
        'inputs' => \json_encode($inputs, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR),
      ];
    }
    if ($allViolations->count() > 0) {
      throw new ConstraintViolationException($allViolations);
    }

    // Fall back to the stored component tree for regions that are either:
    // - not editable
    // - editable, but absent from the auto-saved data (because the region was
    //   not yet editable at the time of auto-saving)
    $values['component_trees'] = $treeItems + $this->component_trees;

    $autosaved_page_template = static::create($values);
    $violations = $autosaved_page_template->getTypedData()->validate();
    if ($violations->count()) {
      throw new ConstraintViolationException($violations);
    }
    return $autosaved_page_template;
  }

  /**
   * {@inheritdoc}
   */
  public function id() {
    return $this->theme;
  }

  /**
   * @return \Generator<string, ComponentTreeItem>
   *   One (dangling) component tree per (populated) region.
   */
  public function getComponentTrees(): \Generator {
    assert(is_array($this->component_trees));

    // Instantiate a single (dangling) XB component tree field item object to
    // subsequently clone and assign a different value for each region that has
    // a component tree defined.
    $field_item = $this->createDanglingComponentTree();
    foreach ($this->component_trees as $region_name => $component_tree) {
      if ($component_tree === NULL) {
        continue;
      }
      $xb_component_tree = clone $field_item;
      $xb_component_tree->setValue($component_tree);
      yield $region_name => $xb_component_tree;
    }
  }

  /**
   * @return string[]
   *   The theme regions that are marked as editable in this page template.
   */
  public function getEditableRegions(): array {
    return array_keys(array_filter($this->get('editable')));
  }

  /**
   * @param string $region_name
   *   A region in this page template's theme.
   */
  public function isEditableRegion(string $region_name): bool {
    assert(array_key_exists($region_name, system_region_list(\Drupal::service('theme_handler')->getTheme($this->theme))));
    return in_array($region_name, $this->getEditableRegions(), TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    parent::calculateDependencies();
    $this->addDependency('theme', $this->theme);

    foreach ($this->getComponentTrees() as $component_tree) {
      assert($component_tree instanceof ComponentTreeItem);
      $tree = $component_tree->get('tree');
      assert($tree instanceof ComponentTreeStructure);
      $this->addDependencies($tree->getDependencies());

      // TRICKY: in theory, dependencies must also be calculated for the `inputs`
      // field prop. But, currently it can only contain StaticPropSources, and the
      // the dependencies for those are tracked in the Component config entity.
      // @see \Drupal\experience_builder\Entity\Component::calculateDependencies()
      // @todo Revisit this when allowing more complex values in `inputs`, that are not dictated by/captured in the Component config entity.
      // @todo Revisit this in https://www.drupal.org/project/experience_builder/issues/3484666, where the above MIGHT change.
    }

    return $this;
  }

  /**
   * Loads the page template entity for the active theme.
   *
   * @return static|null
   *   The page template entity, or NULL if none is active.
   */
  public static function forActiveTheme(): ?static {
    $theme = \Drupal::service('theme.manager')->getActiveTheme()->getName();
    $template = \Drupal::service('entity_type.manager')->getStorage(PageTemplate::PLUGIN_ID)->load($theme);
    assert($template instanceof PageTemplate || $template === NULL);
    return $template && $template->status() ? $template : NULL;
  }

  /**
   * Creates a page template entity based on the block layout of a theme.
   *
   * @param string $theme
   *   The theme to use.
   *
   * @return static
   */
  public static function createFromBlockLayout(string $theme): static {
    $blocks = \Drupal::service('entity_type.manager')->getStorage('block')->loadByProperties(['theme' => $theme]);
    $regions = [];
    foreach ($blocks as $block) {
      $component_id = BlockComponent::componentIdFromBlockPluginId($block->getPluginId());
      if (!Component::load($component_id)) {
        // This block isn't supported by XB.
        // @see \experience_builder_block_alter().
        continue;
      }
      // We can't key these by component ID because you can place the same
      // block twice with different settings.
      $regions[$block->getRegion()][] = [
        'component' => $component_id,
        'settings' => \array_diff_key($block->get('settings'), \array_flip([
          // Remove these as they can be calculated and hence need not be
          // stored.
          'id',
          'provider',
        ])),
        'uuid' => $block->uuid(),
      ];
    }

    $theme_info = \Drupal::service('theme_handler')->getTheme($theme);
    $region_names = array_keys($theme_info->info['regions']);
    $component_trees = array_fill_keys($region_names, NULL);
    foreach ($region_names as $region) {
      if (isset($regions[$region])) {
        $component_trees[$region] = [
          'tree' => json_encode([
            ComponentTreeStructure::ROOT_UUID => array_map(
              static fn(array $block) => \array_intersect_key($block, \array_flip([
                'component',
                'uuid',
              ])),
              $regions[$region],
            ),
          ]),
          'inputs' => \json_encode(\array_reduce($regions[$region], static fn(array $carry, array $block) => $carry + [
            $block['uuid'] => $block['settings'],
          ],
            [])),
        ];
      }
    }

    return static::create([
      'theme' => $theme,
      'component_trees' => $component_trees,
      // All regions are editable by default.
      'editable' => array_fill_keys($region_names, TRUE),
    ]);
  }

}
