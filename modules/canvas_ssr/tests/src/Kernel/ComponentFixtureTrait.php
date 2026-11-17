<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel;

use Drupal\canvas\Entity\JavaScriptComponent;

/**
 * Creates components, for tests that don't need a runner.
 *
 * @see \Drupal\Tests\canvas_ssr\Kernel\RenderTrait
 */
trait ComponentFixtureTrait {

  /**
   * Source for components whose code doesn't matter.
   */
  public const string INERT_JS = 'export default () => null;';

  /**
   * Creates a published component.
   *
   * @param string $label
   *   The component's label.
   * @param string $source
   *   The authored source.
   * @param string|null $compiled
   *   The compiled JS. NULL means the same as $source, which is fine for
   *   plain JS.
   * @param array<string, array<int, string>> $data_dependencies
   *   Data dependencies, such as ['drupalSettings' => ['v0.baseUrl']].
   * @param array<int, string> $imports
   *   Machine names of the components this one imports. These go through
   *   updateFromClientSide(), since only that records them as config
   *   dependencies.
   * @param string|null $machine_name
   *   The machine name. NULL means a random one, which is fine unless
   *   another component imports this one.
   */
  protected function createComponent(
    string $label,
    string $source,
    ?string $compiled = NULL,
    array $data_dependencies = [],
    array $imports = [],
    ?string $machine_name = NULL,
  ): JavaScriptComponent {
    $compiled ??= $source;
    $component = JavaScriptComponent::create([
      'machineName' => $machine_name ?? $this->randomMachineName(),
      'name' => $label,
      'status' => TRUE,
      'props' => [],
      'required' => [],
      'slots' => [],
      'js' => [
        'original' => $source,
        'compiled' => $compiled,
      ],
      'css' => [
        'original' => '',
        'compiled' => '',
      ],
      'dataDependencies' => $data_dependencies,
    ]);
    $component->save();

    if ($imports !== []) {
      $component->updateFromClientSide([
        'sourceCodeJs' => $source,
        'compiledJs' => $compiled,
        'importedJsComponents' => $imports,
      ]);
      $component->save();
    }

    return $component;
  }

}
