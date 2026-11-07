<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Canvas\OptionsSource;

use Drupal\canvas\Attribute\OptionsSource;
use Drupal\canvas\OptionsSource\OptionsSourceInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityReferenceSelection\SelectionInterface;
use Drupal\Core\Entity\EntityReferenceSelection\SelectionPluginManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\views\Views;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Offers the results of a view's "Entity Reference" display, by entity ID.
 *
 * Site builders control which entities are offered, their order and their
 * labels in the view, without changing the component.
 *
 * @code
 * x-canvas-options:
 *   source: view
 *   view: featured_categories
 *   display: entity_reference_1
 * @endcode
 */
#[OptionsSource(
  id: 'view',
  label: new TranslatableMarkup('View'),
)]
final class ViewOptionsSource extends PluginBase implements OptionsSourceInterface, ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly SelectionPluginManagerInterface $selectionManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(ModuleHandlerInterface::class),
      $container->get(SelectionPluginManagerInterface::class),
    );
  }

  public function getOptions(): array {
    $view = Views::getView($this->configuration['view']);
    $entity_type = $view?->getBaseEntityType();
    if ($entity_type === NULL || $entity_type === FALSE) {
      return [];
    }
    $handler = $this->selectionManager->createInstance('views', [
      'target_type' => $entity_type->id(),
      'view' => [
        'view_name' => $this->configuration['view'],
        'display_name' => $this->configuration['display'],
        'arguments' => $this->configuration['arguments'] ?? [],
      ],
    ]);
    \assert($handler instanceof SelectionInterface);
    $options = [];
    foreach ($handler->getReferenceableEntities() as $bundle_options) {
      foreach ($bundle_options as $id => $label) {
        // The view renders the labels as HTML.
        $options[$id] = trim(Html::decodeEntities(strip_tags((string) $label)));
      }
    }
    return $options;
  }

  public function validateConfiguration(): array {
    if (!$this->moduleHandler->moduleExists('views')) {
      return ['The "view" source needs the Views module.'];
    }
    $unknown = \array_diff(\array_keys($this->configuration), ['view', 'display', 'arguments']);
    if ($unknown) {
      return [\sprintf('Unknown keys for the "view" source: %s. Use `view`, `display` and optionally `arguments`.', implode(', ', $unknown))];
    }
    $view_id = $this->configuration['view'] ?? NULL;
    $display_id = $this->configuration['display'] ?? NULL;
    $view = \is_string($view_id) ? Views::getView($view_id) : NULL;
    if ($view === NULL) {
      return [\sprintf('`view` must be an existing view, but is %s.', json_encode($view_id))];
    }
    if (!\is_string($display_id) || !$view->setDisplay($display_id) || $view->display_handler->getPluginId() !== 'entity_reference') {
      return [\sprintf('`display` must be an "Entity Reference" display of the "%s" view, but is %s.', $view_id, json_encode($display_id))];
    }
    $arguments = $this->configuration['arguments'] ?? [];
    if (!\is_array($arguments) || !array_is_list($arguments) || \array_filter($arguments, fn ($argument) => !\is_scalar($argument))) {
      return ['`arguments` must be a list of contextual filter values.'];
    }
    return [];
  }

  public function getSuggestions(): array {
    if (!$this->moduleHandler->moduleExists('views')) {
      return [];
    }
    $suggestions = [];
    foreach (Views::getApplicableViews('entity_reference_display') as [$view_id, $display_id]) {
      $view = Views::getView($view_id);
      if ($view === NULL || !$view->setDisplay($display_id)) {
        continue;
      }
      $suggestions[] = [
        'label' => \sprintf('%s: %s', $view->storage->label(), $view->display_handler->display['display_title']),
        'configuration' => ['view' => $view_id, 'display' => $display_id],
      ];
    }
    return $suggestions;
  }

}
