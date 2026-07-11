<?php

declare(strict_types=1);

namespace Drupal\canvas_page_template_component\Plugin\Canvas\ComponentSource;

use Drupal\canvas\Attribute\ComponentSource;
use Drupal\canvas\ComponentDoesNotMeetRequirementsException;
use Drupal\canvas\ComponentSource\ComponentSourceBase;
use Drupal\canvas\ComponentSource\ComponentSourceWithSlotsInterface;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Exposes an installed theme's page template as a Canvas component.
 *
 * Each component provided by this source corresponds to one installed theme:
 * its `source_local_id` is the theme's machine name. The theme's declared
 * regions become the component's slots, and rendering builds a `#theme => page`
 * render array for that theme with each slot's content wrapped in the theme's
 * `region` theme hook (region.html.twig and its per-region suggestions).
 * Placing the component in a page variant therefore reproduces the theme's
 * page-level and region-level markup, with the variant's content inside the
 * original wrappers.
 *
 * Like the marker and personalization sources, this source has no discovery and
 * no explicit inputs: its components are shipped as enforced-dependency config.
 *
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\Marker
 * @see \Drupal\canvas\Entity\PageVariant
 * @see \Drupal\canvas_page_template_component\PageTemplateComponentUninstallValidator
 *
 * @phpstan-ignore classExtendsInternalClass.classExtendsInternalClass
 */
#[ComponentSource(
  id: self::SOURCE_PLUGIN_ID,
  label: new TranslatableMarkup('Theme page template'),
  supportsImplicitInputs: FALSE,
  discovery: FALSE,
)]
final class ThemePageTemplate extends ComponentSourceBase implements
  ComponentSourceWithSlotsInterface,
  ContainerFactoryPluginInterface {

  public const string SOURCE_PLUGIN_ID = 'theme_page_template';

  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
    private readonly ThemeHandlerInterface $themeHandler,
  ) {
    \assert(\array_key_exists('local_source_id', $configuration));
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('theme_handler'),
    );
  }

  /**
   * The machine name of the theme this component renders the page template of.
   */
  protected function getThemeName(): string {
    \assert(\is_string($this->configuration['local_source_id']));
    return $this->configuration['local_source_id'];
  }

  /**
   * The human-readable name of the theme, or its machine name as a fallback.
   */
  protected function getThemeLabel(): string {
    $theme_name = $this->getThemeName();
    return $this->themeHandler->themeExists($theme_name)
      ? $this->themeHandler->getName($theme_name)
      : $theme_name;
  }

  /**
   * {@inheritdoc}
   */
  public function isBroken(): bool {
    // The component only makes sense while its theme is installed: its regions
    // (and hence its slots) come from the theme's `.info.yml`.
    return !$this->themeHandler->themeExists($this->getThemeName());
  }

  /**
   * {@inheritdoc}
   */
  public function getReferencedPluginClass(): ?string {
    // This source is not backed by another plugin; it renders a theme template.
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getComponentDescription(): TranslatableMarkup {
    return new TranslatableMarkup("The @theme theme's page template", ['@theme' => $this->getThemeLabel()]);
  }

  /**
   * {@inheritdoc}
   */
  public function renderComponent(array $inputs, array $slot_definitions, string $componentUuid, bool $isPreview): array {
    // Build the theme's page template. Region content is placed as slots by
    // ::setSlots(), keyed by region machine name, so that page.html.twig prints
    // each region exactly where the theme expects it. Empty regions are left
    // unset and initialized to an empty array during page preprocessing, just
    // like core's block layout.
    // @see \Drupal\Core\Theme\ThemePreprocess::preprocessPage()
    // @see \Drupal\Core\Render\MainContent\HtmlRenderer::prepare()
    return [
      '#theme' => 'page',
      // Consumed by ::setSlots() to annotate slots in previews. The Twig-level
      // annotation cannot: it wraps prints of bare slot variables in component
      // templates, but page.html.twig prints regions as `page.<region>`.
      // @see \Drupal\canvas\Twig\CanvasWrapperNode
      '#canvas_component_uuid' => $componentUuid,
      '#canvas_is_preview' => $isPreview,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getSlotDefinitions(): array {
    if (!$this->themeHandler->themeExists($this->getThemeName())) {
      return [];
    }
    $regions = $this->themeHandler->getTheme($this->getThemeName())->info['regions'] ?? [];
    $slot_definitions = [];
    foreach ($regions as $region_name => $region_label) {
      $slot_definitions[$region_name] = [
        'title' => (string) $region_label,
        'description' => (string) new TranslatableMarkup('The @region region.', ['@region' => $region_label]),
        'examples' => [''],
      ];
    }
    return $slot_definitions;
  }

  /**
   * {@inheritdoc}
   */
  public function setSlots(array &$build, array $slots): void {
    $uuid = $build['#canvas_component_uuid'] ?? NULL;
    $is_preview = $build['#canvas_is_preview'] ?? FALSE;
    // Wrap each region's content in the theme's `region` theme hook, matching
    // how core renders regions placed by block layout. This resolves
    // region.html.twig and its per-region suggestions (region__<name>).
    // @see \Drupal\Core\Render\MainContent\HtmlRenderer::prepare()
    // @see \Drupal\Core\Theme\ThemePreprocess::preprocessRegion()
    foreach ($slots as $region_name => $region_build) {
      if (empty($region_build)) {
        continue;
      }
      // Outside previews, a region whose only content is the empty slot
      // default renders nothing at all, matching how core block layout skips
      // empty regions. In previews the empty-slot placeholder becomes the
      // region's drop target, so the region wrapper must render.
      // @see \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList::renderify()
      if (!$is_preview
        && ($region_build['#plain_text'] ?? $region_build['#markup'] ?? NULL) === ''
        && Element::children($region_build) === []) {
        continue;
      }
      // In previews, annotate each slot with the HTML comments the editor uses
      // to locate slots for overlays and drop targets. TRICKY: the comments
      // must render INSIDE the region wrapper (the editor associates a slot
      // with the comment's parent element; outside the wrapper, every slot
      // would resolve to the shared page container), so the slot content nests
      // one level below the element carrying the `region` theme wrapper.
      // @see ui/src/utils/function-utils.ts
      if ($is_preview && \is_string($uuid)) {
        $region_build['#prefix'] = Markup::create(\sprintf('<!-- canvas-slot-start-%s/%s -->', $uuid, $region_name));
        $region_build['#suffix'] = Markup::create(\sprintf('<!-- canvas-slot-end-%s/%s -->', $uuid, $region_name));
      }
      $build[$region_name] = [
        '#theme_wrappers' => ['region'],
        '#region' => $region_name,
        'content' => $region_build,
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function hydrateComponent(array $explicit_input, array $slot_definitions, array $active_required_explicit_inputs): array {
    $hydrated = $explicit_input;
    if (!empty($slot_definitions)) {
      // Use the first example defined for each slot as its default value.
      $hydrated['slots'] = \array_map(fn (array $slot): mixed => $slot['examples'][0], $slot_definitions);
    }
    return $hydrated;
  }

  /**
   * {@inheritdoc}
   */
  public function requiresExplicitInput(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultExplicitInput(bool $only_required = FALSE): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getExplicitInput(string $uuid, ComponentTreeItem $item, ?FieldableEntityInterface $host_entity = NULL): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function inputToClientModel(array $explicit_input): array {
    return ['resolved' => []];
  }

  /**
   * {@inheritdoc}
   */
  public function getClientSideInfo(Component $component): array {
    // @todo Offer a richer library preview once page variants have an editor UX for the theme page template component in https://www.drupal.org/project/canvas/issues/3526189
    return [
      'build' => [],
      'metadata' => [
        'slots' => $this->getSlotDefinitions(),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildComponentInstanceForm(
    array $form,
    FormStateInterface $form_state,
    Component $component,
    string $component_instance_uuid = '',
    array $inputValues = [],
    ?EntityInterface $entity = NULL,
    array $settings = [],
  ): array {
    // A theme page template has no configurable inputs.
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function clientModelToInput(string $component_instance_uuid, Component $component, array $client_model, ?FieldableEntityInterface $host_entity, ?ConstraintViolationListInterface $violations = NULL): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function validateComponentInput(array $inputValues, string $component_instance_uuid, ?FieldableEntityInterface $entity): ConstraintViolationListInterface {
    // A theme page template has no inputs to validate.
    return new ConstraintViolationList();
  }

  /**
   * {@inheritdoc}
   */
  public function checkRequirements(): void {
    if (!$this->themeHandler->themeExists($this->getThemeName())) {
      throw new ComponentDoesNotMeetRequirementsException([
        \sprintf('The "%s" theme is not installed.', $this->getThemeName()),
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(): array {
    // Because these components have no settings, the only dependency is on the
    // module that provides this source. The theme coupling is intentionally not
    // a config dependency: a missing theme makes the component broken, not the
    // config invalid, mirroring how block components handle missing providers.
    return [
      'module' => [
        'canvas_page_template_component',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function getExplicitInputDefinitions(): array {
    // A theme page template has no explicit inputs.
    return [];
  }

}
