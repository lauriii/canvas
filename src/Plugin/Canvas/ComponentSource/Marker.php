<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Canvas\ComponentSource;

use Drupal\canvas\Attribute\ComponentSource;
use Drupal\canvas\ComponentSource\ComponentSourceBase;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Provides Canvas "marker" components.
 *
 * A marker is an intrinsic placeholder, not a normal library component: it
 * stands in for content that Canvas injects at render time. Markers are never
 * offered in the component library and are managed intrinsically by Canvas.
 *
 * The first marker is the page content marker (`marker.page_content`), which
 * designates where a page variant injects the route's main content. The second
 * is the empty slot marker (`marker.empty_slot`), which as the sole root row of
 * an exposed slot's backing field means "this entity's slot renders nothing"
 * (as opposed to an empty field, which means "inherit the template default").
 * Further markers are additional local ids of this same source, so the "hide
 * from the library" and intrinsic handling key on the source, not on each
 * marker.
 *
 * Markers have no settings and no explicit inputs.
 *
 * @see \Drupal\canvas\Entity\PageVariant
 * @see \Drupal\canvas\Plugin\Validation\Constraint\PageVariantHasContentMarkerConstraint
 * @see \Drupal\canvas\Plugin\Validation\Constraint\ComponentTreeStructureConstraintValidator
 */
#[ComponentSource(
  id: self::SOURCE_PLUGIN_ID,
  label: new TranslatableMarkup('Marker'),
  supportsImplicitInputs: FALSE,
  discovery: FALSE,
)]
final class Marker extends ComponentSourceBase {

  public const string SOURCE_PLUGIN_ID = 'marker';

  /**
   * The local source id of the page content marker.
   */
  public const string PAGE_CONTENT_LOCAL_ID = 'page_content';

  /**
   * The full Component config entity id of the page content marker.
   */
  public const string PAGE_CONTENT_COMPONENT_ID = self::SOURCE_PLUGIN_ID . '.' . self::PAGE_CONTENT_LOCAL_ID;

  /**
   * The local source id of the empty slot marker.
   */
  public const string EMPTY_SLOT_LOCAL_ID = 'empty_slot';

  /**
   * The full Component config entity id of the empty slot marker.
   */
  public const string EMPTY_SLOT_COMPONENT_ID = self::SOURCE_PLUGIN_ID . '.' . self::EMPTY_SLOT_LOCAL_ID;

  /**
   * The marker's local source id (which marker this is).
   */
  protected function getLocalId(): string {
    \assert(\is_string($this->configuration['local_source_id']));
    return $this->configuration['local_source_id'];
  }

  /**
   * {@inheritdoc}
   */
  public function isBroken(): bool {
    // Markers are hard-coded, provided as module config.
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getReferencedPluginClass(): ?string {
    // A marker is not backed by another plugin; it is detected by component id.
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getComponentDescription(): TranslatableMarkup {
    return match ($this->getLocalId()) {
      self::PAGE_CONTENT_LOCAL_ID => new TranslatableMarkup('Page content'),
      self::EMPTY_SLOT_LOCAL_ID => new TranslatableMarkup('Empty slot'),
      default => new TranslatableMarkup('Marker'),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function renderComponent(array $inputs, array $slot_definitions, string $componentUuid, bool $isPreview): array {
    // Only the page content marker takes part in the page variant's content
    // injection, and only it gets a visible editor placeholder. Any other
    // marker (the empty slot marker, for one) renders nothing anywhere: it
    // stands for the absence of content, so a placeholder card would be
    // wrong, and suspending its fiber would make the variant renderer inject
    // the route's main content in its place, because that renderer recognizes
    // the marker by its source rather than by its id.
    // @see \Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant::renderComponentTree()
    if ($this->getLocalId() !== self::PAGE_CONTENT_LOCAL_ID) {
      return [];
    }

    // A marker renders nothing on its own. In a page variant, the renderer
    // injects the route's main content in its place by resuming the fiber with
    // that content: suspending yields this source so the variant can recognize
    // the marker. TRICKY: a current fiber does not imply the variant renderer,
    // because core renders placeholders in fibers too; those resume with NULL,
    // so only an array counts as injected content.
    // @see \Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant::build()
    // @see \Drupal\Core\Render\Renderer::replacePlaceholders()
    if (\Fiber::getCurrent() !== NULL) {
      $injected = \Fiber::suspend($this);
      if (\is_array($injected)) {
        return $injected;
      }
    }
    // Nothing was injected: the variant tree itself is being rendered. In a
    // preview that means the variant is being edited, so render a visible
    // placeholder that can be selected and moved.
    if ($isPreview) {
      // The same "disc" glyph the editor uses for the marker elsewhere.
      // @see ui/src/components/sidePanel/SidebarNode.tsx
      $icon = '<svg class="canvas--page-content-marker-placeholder__icon" width="24" height="24" viewBox="0 0 15 15" fill="none" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" clip-rule="evenodd" fill="currentColor" d="M7.49991 0.877075C3.84222 0.877075 0.877075 3.84222 0.877075 7.49991C0.877075 11.1576 3.84222 14.1227 7.49991 14.1227C11.1576 14.1227 14.1227 11.1576 14.1227 7.49991C14.1227 3.84222 11.1576 0.877075 7.49991 0.877075ZM1.82708 7.49991C1.82708 4.36689 4.36689 1.82707 7.49991 1.82707C10.6329 1.82707 13.1727 4.36689 13.1727 7.49991C13.1727 10.6329 10.6329 13.1727 7.49991 13.1727C4.36689 13.1727 1.82708 10.6329 1.82708 7.49991ZM8.37287 7.50006C8.37287 7.98196 7.98221 8.37263 7.5003 8.37263C7.01839 8.37263 6.62773 7.98196 6.62773 7.50006C6.62773 7.01815 7.01839 6.62748 7.5003 6.62748C7.98221 6.62748 8.37287 7.01815 8.37287 7.50006ZM9.32287 7.50006C9.32287 8.50664 8.50688 9.32263 7.5003 9.32263C6.49372 9.32263 5.67773 8.50664 5.67773 7.50006C5.67773 6.49348 6.49372 5.67748 7.5003 5.67748C8.50688 5.67748 9.32287 6.49348 9.32287 7.50006Z"/></svg>';
      return [
        '#type' => 'container',
        '#attributes' => ['class' => ['canvas--page-content-marker-placeholder']],
        'icon' => ['#markup' => Markup::create($icon)],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'strong',
          '#value' => $this->t('Page content'),
          '#attributes' => ['class' => ['canvas--page-content-marker-placeholder__title']],
        ],
        'caption' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $this->t("Each page's own content renders here."),
          '#attributes' => ['class' => ['canvas--page-content-marker-placeholder__caption']],
        ],
        '#attached' => ['library' => ['canvas/preview']],
      ];
    }
    return [];
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
  public function hydrateComponent(array $explicit_input, array $slot_definitions, array $active_required_explicit_inputs): array {
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
    return [
      'build' => [],
      'metadata' => [
        'slots' => [],
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
    // Markers have no configurable inputs.
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
    // Markers have no inputs to validate.
    return new ConstraintViolationList();
  }

  /**
   * {@inheritdoc}
   */
  public function checkRequirements(): void {
    // Markers are hard-coded module config, always available.
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(): array {
    return [
      'module' => ['canvas'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function getExplicitInputDefinitions(): array {
    // Markers have no explicit inputs.
    return [];
  }

}
