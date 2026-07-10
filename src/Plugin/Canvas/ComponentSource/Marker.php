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
 * designates where a page variant injects the route's main content. Future
 * markers (for example an exposed-slot marker) are additional local ids of this
 * same source, so the "hide from the library" and intrinsic handling key on the
 * source, not on each marker.
 *
 * Markers have no settings and no explicit inputs.
 *
 * @see \Drupal\canvas\Entity\PageVariant
 * @see \Drupal\canvas\Plugin\Validation\Constraint\PageVariantHasContentMarkerConstraint
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
      default => new TranslatableMarkup('Marker'),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function renderComponent(array $inputs, array $slot_definitions, string $componentUuid, bool $isPreview): array {
    // A marker renders nothing on its own. In a page variant, the renderer
    // replaces it with the route's main content.
    // @see \Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant
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
