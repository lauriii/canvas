<?php

declare(strict_types=1);

namespace Drupal\canvas_views\Plugin\Canvas\ComponentSource;

use Drupal\canvas\Attribute\ComponentSource;
use Drupal\canvas\ComponentDoesNotMeetRequirementsException;
use Drupal\canvas\ComponentSource\ComponentSourceBase;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas_views\Entity\CanvasViewsDisplay;
use Drupal\canvas_views\ViewsDisplayDiscovery;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Exposes each Canvas views display as a placeable component.
 *
 * Placement-only: the design lives in the CanvasViewsDisplay entity, edited
 * in the Canvas editor; placing the component just selects where the display
 * appears. Rendering executes the view and renders the designed tree once
 * per result row.
 *
 * @phpstan-ignore classExtendsInternalClass.classExtendsInternalClass
 */
#[ComponentSource(
  id: self::SOURCE_PLUGIN_ID,
  label: new TranslatableMarkup('Views display'),
  supportsImplicitInputs: TRUE,
  discovery: ViewsDisplayDiscovery::class,
)]
final class ViewsDisplayComponent extends ComponentSourceBase implements ContainerFactoryPluginInterface {

  public const string SOURCE_PLUGIN_ID = 'views_display';

  /**
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(EntityTypeManagerInterface::class),
    );
  }

  private function getDisplayEntity(): ?CanvasViewsDisplay {
    $entity = $this->entityTypeManager
      ->getStorage(CanvasViewsDisplay::ENTITY_TYPE_ID)
      ->load($this->configuration['display_id'] ?? '');
    return $entity instanceof CanvasViewsDisplay ? $entity : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function isBroken(): bool {
    return $this->getDisplayEntity() === NULL;
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
    return new TranslatableMarkup('Views display: @id', ['@id' => $this->configuration['display_id'] ?? '']);
  }

  /**
   * {@inheritdoc}
   */
  public function checkRequirements(): void {
    if ($this->getDisplayEntity() === NULL) {
      throw new ComponentDoesNotMeetRequirementsException([
        \sprintf('Canvas views display "%s" does not exist.', $this->configuration['display_id'] ?? ''),
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(): array {
    $dependencies = ['module' => ['canvas_views']];
    $entity = $this->getDisplayEntity();
    if ($entity !== NULL) {
      $dependencies['config'][] = $entity->getConfigDependencyName();
    }
    return $dependencies;
  }

  /**
   * {@inheritdoc}
   */
  protected function getExplicitInputDefinitions(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   *
   * TRUE so every placed instance gets a client model entry; the client
   * cannot select an instance without one.
   */
  public function requiresExplicitInput(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultExplicitInput(bool $only_required = FALSE): array {
    return ['settings' => []];
  }

  /**
   * {@inheritdoc}
   */
  public function getExplicitInput(string $uuid, ComponentTreeItem $item, ?FieldableEntityInterface $host_entity = NULL): array {
    return ($item->getInputs() ?? []) + ['settings' => []];
  }

  /**
   * {@inheritdoc}
   */
  public function hydrateComponent(array $explicit_input, array $slot_definitions, array $active_required_explicit_inputs): array {
    return $explicit_input;
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
  public function getClientSideInfo(Component $component): array {
    return [
      'build' => [
        '#markup' => Markup::create('<div style="padding:1rem;border:1px dashed #999;text-align:center">' . htmlspecialchars((string) $component->label()) . '</div>'),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildComponentInstanceForm(array $form, FormStateInterface $form_state, Component $component, string $component_instance_uuid = '', array $inputValues = [], ?EntityInterface $entity = NULL, array $settings = []): array {
    $display = $this->getDisplayEntity();
    if ($display !== NULL) {
      $form['info'] = [
        '#markup' => '<p>' . new TranslatableMarkup('This placement shows the %label display. Its design and field mappings are managed on the display itself.', ['%label' => (string) $display->label()]) . '</p>',
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function renderComponent(array $inputs, array $slot_definitions, string $componentUuid, bool $isPreview = FALSE): array {
    $display = $this->getDisplayEntity();
    if ($display === NULL) {
      return [];
    }
    // Placement rendering is never the display's own editing context, so no
    // editing annotations inside: the placed component is a black box here.
    return $display->build(FALSE);
  }

}
