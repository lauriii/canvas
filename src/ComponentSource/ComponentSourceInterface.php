<?php

declare(strict_types=1);

namespace Drupal\experience_builder\ComponentSource;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Plugin\DependentPluginInterface;
use Drupal\Component\Plugin\DerivativeInspectionInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContextAwarePluginInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Defines an interface for component source plugins.
 *
 * A Component is a config entity created by a site builder that allows
 * placement of that component in Experience Builder.
 *
 * Each Component config entity is handled by a component source. For example
 * there might be:
 * - an SDC component source — which renders a single-directory component and
 *   needs values for each required SDC prop
 * - a block plugin component source — which renders the a block and needs
 *   settings for the block plugin
 *
 * Not all component sources support slots. A source that supports slots should
 * implement \Drupal\experience_builder\ComponentSource\ComponentSourceWithSlotsInterface.
 *
 * @phpstan-import-type PropSourceArray from \Drupal\experience_builder\PropSource\PropSourceBase
 * @phpstan-import-type SingleComponentInputArray from \Drupal\experience_builder\Plugin\DataType\ComponentInputs
 *
 * @see \Drupal\experience_builder\Attribute\ComponentSource
 * @see \Drupal\experience_builder\ComponentSource\ComponentSourceBase
 * @see \Drupal\experience_builder\ComponentSource\ComponentSourceManager
 * @see \Drupal\experience_builder\ComponentSource\ComponentSourceWithSlotsInterface
 */
interface ComponentSourceInterface extends PluginInspectionInterface, DerivativeInspectionInterface, ConfigurableInterface, PluginFormInterface, DependentPluginInterface, ContextAwarePluginInterface {

  /**
   * Gets referenced plugin classes for this instance.
   *
   * This is used in validation to allow component tree items to limit the type
   * of plugins that can be referenced. For example, the main content block
   * can't be referenced by a content entity's component tree.
   *
   * @return class-string|null
   *   An FQCN of any plugin classes that this source plugin is referencing. For
   *   example a block source plugin might return the block plugin class it is
   *   referencing here.
   */
  public function getReferencedPluginClass(): ?string;

  /**
   * Gets a description of the component.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   Description.
   */
  public function getComponentDescription(): TranslatableMarkup;

  /**
   * Renders a component for the given instance.
   *
   * @param array $inputs
   *   Component inputs — both implicit and explicit.
   * @param string $componentUuid
   *   Component UUID.
   * @param bool $isPreview
   *   TRUE if is preview.
   *
   * @return array
   *   Render array.
   */
  public function renderComponent(array $inputs, string $componentUuid, bool $isPreview): array;

  public function generateVersionHash(): string;

  /**
   * Whether this component requires explicit input or not.
   */
  public function requiresExplicitInput(): bool;

  /**
   * Returns the default explicit input (prop sources) for this component.
   *
   * @phpcs:ignore
   * @return SingleComponentInputArray
   *   An array of prop sources to use for the inputs of this component, keyed
   *   by input name.
   */
  public function getDefaultExplicitInput(): array;

  /**
   * Retrieves the component instance's explicit (possibly empty) input.
   *
   * @todo Add ::getImplicitInput() in https://www.drupal.org/project/experience_builder/issues/3485502 — SDCs don't have implicit inputs, but Block plugins do: contexts
   */
  public function getExplicitInput(string $uuid, ComponentTreeItem $item): array;

  /**
   * Hydrates a component with its explicit input plus slots (if any).
   *
   * Note that the result contains the default slot value, because this method
   * only handles a single component instance, not a component tree. Populating
   * slots with component instance happens later.
   *
   * @return array{'slots'?: array<string, string>}
   *
   * @see \Drupal\experience_builder\ComponentSource\ComponentSourceWithSlotsInterface::setSlots()
   */
  public function hydrateComponent(array $explicit_input): array;

  /**
   * Normalizes explicit inputs to the data model expected by the client.
   *
   * Note that the result MUST NOT contain slot information.
   *
   * @param array $explicit_input
   *
   * @return array
   *
   * @see openapi.yml
   * @see ::clientModelToInput()
   * @see \Drupal\experience_builder\Entity\XbHttpApiEligibleConfigEntityInterface::normalizeForClientSide
   */
  public function inputToClientModel(array $explicit_input): array;

  /**
   * Gets the plugin definition.
   *
   * @return array
   *   Plugin definition.
   */
  public function getPluginDefinition(): array;

  /**
   * Returns information the client side needs for the XB UI.
   *
   * @param \Drupal\experience_builder\Entity\Component $component
   *   A component config entity that uses this source.
   *
   * @return array{'source'?: string, 'build': array<string, mixed>, propSources?: array<string, array>}
   *   Client side metadata including a build array for the default markup.
   *
   * @see \Drupal\experience_builder\Controller\ApiComponentsController
   */
  public function getClientSideInfo(Component $component): array;

  /**
   * Configuration form constructor.
   *
   * @param array $form
   *   An associative array containing the initial structure of the plugin form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param \Drupal\experience_builder\Entity\Component|null $component
   *   The component configuration entity.
   * @param string $component_instance_uuid
   *   The component instance UUID.
   * @param array $client_model
   *   Current client model values for the component from the incoming request.
   * @param \Drupal\Core\Entity\EntityInterface|null $entity
   *   The host entity (for evaluated input).
   * @param array $settings
   *   The component configuration entity settings.
   *
   * @return array
   *   The form structure.
   */
  public function buildConfigurationForm(
    array $form,
    FormStateInterface $form_state,
    ?Component $component = NULL,
    string $component_instance_uuid = '',
    array $client_model = [],
    ?EntityInterface $entity = NULL,
    array $settings = [],
  ): array;

  /**
   *
   * @param string $component_instance_uuid
   *   Component instance UUID.
   * @param \Drupal\experience_builder\Entity\Component $component
   *   Component for this instance.
   * @param array{source: SingleComponentInputArray, resolved: array<string, mixed>} $client_model
   *   Client model for this component.
   * @param \Symfony\Component\Validator\ConstraintViolationListInterface|null $violations
   *   If validation should be performed, a violation constraint list, or NULL
   *   otherwise. Use ::addViolation to add violations detected during conversion.
   *
   * @phpcs:ignore
   * @return SingleComponentInputArray
   * @todo Refactor to use the Symfony denormalizer infrastructure?
   * @see ::inputToClientModel()
   */
  public function clientModelToInput(string $component_instance_uuid, Component $component, array $client_model, ?ConstraintViolationListInterface $violations = NULL): array;

  /**
   * Validates component input.
   *
   * @param array $inputValues
   *   Input values stored for this component.
   * @param string $component_instance_uuid
   *   Component instance UUID.
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $entity
   *   Host entity.
   *
   * @return \Symfony\Component\Validator\ConstraintViolationListInterface
   *   Any violations.
   */
  public function validateComponentInput(array $inputValues, string $component_instance_uuid, ?FieldableEntityInterface $entity): ConstraintViolationListInterface;

  /**
   * Checks if component meets requirements.
   *
   * @throws \Drupal\experience_builder\ComponentDoesNotMeetRequirementsException
   *   When the component does not meet requirements.
   */
  public function checkRequirements(): void;

  /**
   * Optimize component inputs prior to saving.
   *
   * For example a component source plugin may with to store a normalized
   * representation of its data.
   *
   * @param array $values
   *   Input values to optimize.
   *
   * @return array
   *   Optimized values.
   */
  public function optimizeExplicitInput(array $values): array;

}
