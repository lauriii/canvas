<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\experience_builder\Entity\Component;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

final class SdcController extends ControllerBase {

  /**
   * Constructor for the SDC controller.
   *
   * @param \Drupal\Core\Theme\ComponentPluginManager $componentPluginManager
   * @param \Drupal\Core\Render\RendererInterface $renderer
   */
  public function __construct(
    private readonly ComponentPluginManager $componentPluginManager,
    private readonly RendererInterface $renderer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.sdc'),
      $container->get('renderer'),
    );
  }

  /**
   * Gets an array of single directory components in an xb-friendly form.
   *
   * @return array<integer, mixed>
   *   The array or single directory components.
   */
  private function getComponentsList(): array {
    $component_plugins = [];
    foreach (Component::loadMultiple() as $component) {
      $component_plugins[] = $this->componentPluginManager->find($component->getComponentMachineName());
    }

    return array_map(fn($component_plugin) => [
      'id' => $component_plugin->getPluginId(),
      'name' => $component_plugin->metadata->name,
      'metadata' => $component_plugin->metadata,
    ], $component_plugins);
  }

  /**
   * Provides a list of single directory components as JSON.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The components list.
   */
  public function components() {
    return new JsonResponse($this->getComponentsList());
  }

  /**
   * Provides one single directory component as JSON.
   *
   * @param string $component_id
   *   The component ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function component(string $component_id): JsonResponse {
    $components = array_filter($this->getComponentsList(), fn($component) => $component['id'] === $component_id);
    assert(!empty($components));
    return new JsonResponse($components[0]);
  }

  /**
   * Renders an SDC and provides the markup in a JSON response.
   *
   * @param string $component_id
   *   The component ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function renderComponent(string $component_id): JsonResponse {
    $build = [
      '#type' => 'component',
      '#component' => $component_id,
    ];

    $component_info = array_filter(
      $this->componentPluginManager->getAllComponents(),
      fn($component) => $component->getPluginId() === $component_id,
    );
    assert(!empty($component_info));
    $component = array_values($component_info)[0];
    $metadata = $component->metadata;
    $properties = $metadata->schema['properties'] ?? [];
    foreach ($properties as $prop_name => $prop_info) {
      self::populatePropValues($build, [], $prop_name, $prop_info);
    }

    $rendered_component = $this->renderer->render($build);

    return new JsonResponse(['markup' => $rendered_component, 'props' => $build['#props'] ?? [], 'metadata' => $metadata]);
  }

  /**
   * Assign values to props in the SDC render array.
   *
   * @param array<string, mixed> $build
   *   The render array.
   * @param array<string, mixed> $values
   *   Already defined prop values.
   * @param string $prop_name
   *   The prop being checked.
   * @param array<string, mixed> $prop_info
   *   The prop's metadata.
   */
  private function populatePropValues(array &$build, array $values, string $prop_name, array $prop_info):void {
    $value = '';
    if (isset($prop_info['examples'])) {
      if ($values) {
        $value = $prop_info['examples'][$values[$prop_name]] ?? $prop_info['examples'][0];
      }
      else {
        $value = $prop_info['examples'][0];
      }
    }
    elseif (isset($prop_info['enum'])) {
      $value = $prop_info['enum'][0];
    }
    elseif (isset($prop_info['type'][1]) && $prop_info['type'][1] === 'object') {
      if (isset($prop_info['format']) && $prop_info['format'] === 'uri') {
        $value = 'https://drupal.org';
      }
      elseif ($prop_info['type'][0] !== 'string') {
        $value = new $prop_info['type'][0]();
      }
    }
    elseif ($prop_info['type'][0] === 'object') {
      $value = [];
    }
    $build['#props'][$prop_name] = $value;
  }

}
