<?php

declare(strict_types=1);

namespace Drupal\canvas_test_deferred_slots\Plugin\Canvas\ComponentSource;

use Drupal\canvas\Attribute\ComponentSource;
use Drupal\canvas\ComponentSource\ComponentSourceBase;
use Drupal\canvas\ComponentSource\ComponentSourceWithDeferredSlotsInterface;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * A minimal source with a deferred slot, for kernel tests.
 *
 * Renders a wrapper that records how many raw deferred items it received, so a
 * test can assert the tree handed the subtree over instead of rendering it.
 *
 * @phpstan-ignore classExtendsInternalClass.classExtendsInternalClass
 */
#[ComponentSource(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Test deferred slots'),
  supportsImplicitInputs: TRUE,
  discovery: FALSE,
)]
final class TestDeferredSlots extends ComponentSourceBase implements ComponentSourceWithDeferredSlotsInterface {

  public const string PLUGIN_ID = 'test_deferred';

  public const string SLOT_NAME = 'the_deferred_slot';

  /**
   * The raw deferred items renderComponent() last received, for assertions.
   *
   * @var array<int, array<string, mixed>>|null
   */
  public static ?array $lastReceivedDeferredItems = NULL;

  /**
   * {@inheritdoc}
   */
  public function isBroken(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getSourceSpecificComponentId(): string {
    return $this->configuration['local_source_id'] ?? '';
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
    return new TranslatableMarkup('Component source with a deferred slot');
  }

  /**
   * {@inheritdoc}
   */
  public function renderComponent(array $inputs, array $slot_definitions, string $componentUuid, bool $isPreview): array {
    $deferred = $inputs[ComponentSourceWithDeferredSlotsInterface::DEFERRED_ITEMS_KEY] ?? [];
    self::$lastReceivedDeferredItems = $deferred;
    return [
      '#markup' => \sprintf('<div data-deferred-count="%d"></div>', \count($deferred)),
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function getExplicitInputDefinitions(): array {
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
    // Return default slot values on purpose, like a normal slot source would:
    // the tree must discard them for a deferred source, or renderify() would
    // call setSlots() and let defaults overwrite the source's own rendering.
    return $explicit_input + [
      'slots' => [self::SLOT_NAME => 'DEFAULT SLOT VALUE MUST BE DISCARDED'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function inputToClientModel(array $explicit_input): array {
    return ['resolved' => $explicit_input];
  }

  /**
   * {@inheritdoc}
   */
  public function getClientSideInfo(Component $component): array {
    return ['build' => []];
  }

  /**
   * {@inheritdoc}
   */
  public function buildComponentInstanceForm(array $form, FormStateInterface $form_state, Component $component, string $component_instance_uuid = '', array $inputValues = [], ?EntityInterface $entity = NULL, array $settings = []): array {
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function clientModelToInput(string $component_instance_uuid, Component $component, array $client_model, ?FieldableEntityInterface $host_entity, ?ConstraintViolationListInterface $violations = NULL): array {
    return $client_model['resolved'] ?? [];
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
  public function checkRequirements(): void {
  }

  /**
   * {@inheritdoc}
   */
  public function getSlotDefinitions(): array {
    return [
      self::SLOT_NAME => [
        'title' => 'Deferred slot',
        'description' => 'Rendered by the source, not by the tree.',
        'examples' => [],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function setSlots(array &$build, array $slots): void {
    // The tree must never call this for a deferred source; hydrated slots are
    // discarded before renderify().
    throw new \LogicException('setSlots() must not be called for a deferred-slots source.');
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(): array {
    return [];
  }

}
