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
 * Defines a component source that renders nothing.
 *
 * This source powers a single, non-placeable Component config entity (the
 * "empty slot marker") that represents an *empty override* of an exposed slot:
 * as the sole root row of the slot's backing field it means "this entity's slot
 * renders nothing", as opposed to an empty field, which means "inherit the
 * template default".
 *
 * It is shipped as a `status: false` Component config entity, which excludes it
 * from the component library (only `status: TRUE` Components are listed) while
 * leaving it loadable, referenceable and renderable in a saved component tree.
 * Rendering it produces literally nothing.
 *
 * ⚠️ Like other `discovery: FALSE` sources, this provides exactly one known
 * Component; that Component config entity is provided in this module's
 * `config/install` directory.
 *
 * @see \Drupal\canvas\Entity\ComponentInterface::EMPTY_SLOT_MARKER_ID
 * @see \Drupal\canvas\Controller\ApiConfigControllers::list()
 */
#[ComponentSource(
  id: self::SOURCE_PLUGIN_ID,
  label: new TranslatableMarkup('Empty slot marker'),
  supportsImplicitInputs: FALSE,
  discovery: FALSE,
  updater: FALSE,
)]
final class EmptySlotMarker extends ComponentSourceBase {

  public const string SOURCE_PLUGIN_ID = 'canvas_slot_empty';

  /**
   * {@inheritdoc}
   */
  public function isBroken(): bool {
    // The single component provided by this source is hard-coded.
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
    return new TranslatableMarkup('Empty slot marker');
  }

  /**
   * {@inheritdoc}
   *
   * ⚠️ Renders literally nothing: this marks an exposed slot as empty.
   */
  public function renderComponent(array $inputs, array $slot_definitions, string $componentUuid, bool $isPreview): array {
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
    return $item->getInputs() ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function hydrateComponent(array $explicit_input, array $slot_definitions, array $active_required_explicit_inputs): array {
    // This source has no slots and no explicit input.
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function inputToClientModel(array $explicit_input): array {
    // Keep things as is.
    return $explicit_input;
  }

  /**
   * {@inheritdoc}
   */
  public function clientModelToInput(string $component_instance_uuid, Component $component, array $client_model, ?FieldableEntityInterface $host_entity, ?ConstraintViolationListInterface $violations = NULL): array {
    // Keep things as is.
    return $client_model;
  }

  /**
   * {@inheritdoc}
   */
  public function validateComponentInput(array $inputValues, string $component_instance_uuid, ?FieldableEntityInterface $entity): ConstraintViolationListInterface {
    return new ConstraintViolationList();
  }

  /**
   * {@inheritdoc}
   */
  public function getClientSideInfo(Component $component): array {
    // This component is never placeable, so the client never needs a build.
    return [
      'build' => [],
      'metadata' => [],
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
    // This component has no configurable input.
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function checkRequirements(): void {
    // Do nothing: this component is provided as module config, not discovered.
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(): array {
    // This source has no settings, so its Component config entity has no
    // additional dependencies beyond the module that provides the source.
    return [
      'module' => [
        'canvas',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function getExplicitInputDefinitions(): array {
    return [];
  }

}
