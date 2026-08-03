<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel;

use Drupal\canvas_dev_ai\Form\CanvasDevAiAgentSelectionForm;
use Drupal\Core\Asset\AttachedAssets;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Agents & Tools settings form and the Tools it exposes to JS.
 */
#[Group('canvas_ai')]
#[CoversClass(CanvasDevAiAgentSelectionForm::class)]
final class CanvasDevAiAgentSelectionTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_ai',
    'ai',
    'ai_agents',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Uninstalling any module fires user_module_uninstall(), which deletes from
    // the users_data table. Kernel tests do not create it unless asked.
    $this->installSchema('user', ['users_data']);
    // The ai_agent entities the form filters on ship in canvas_ai's
    // config/install. CanvasKernelTestBase installs config for 'system' and
    // 'canvas' only, so without this the agents do not exist and the Tools
    // payload is silently empty.
    $this->installConfig(['canvas_ai']);
  }

  /**
   * Tests that the selected Tools are exposed to JavaScript.
   */
  public function testToolsAreExposedToJs(): void {
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_dev_ai']);
    $this->refreshContainer();

    $this->config('canvas_dev_ai.settings')
      ->set('tools', ['canvas_component_agent', 'canvas_page_builder_agent'])
      ->save();

    $settings = $this->alterJsSettings();
    $this->assertArrayHasKey('ai', $settings['canvas']);
    $tools = $settings['canvas']['ai']['tools'];
    $this->assertCount(2, $tools);

    // The labels and descriptions must come from the ai_agent entities, not
    // from config. Compare against the entities rather than hard-coded strings,
    // otherwise this would still pass if they had been copied into config.
    $storage = $this->container->get(EntityTypeManagerInterface::class)->getStorage('ai_agent');
    $expected_ids = ['canvas_component_agent', 'canvas_page_builder_agent'];

    foreach ($expected_ids as $index => $id) {
      $agent = $storage->load($id);
      $this->assertInstanceOf(ConfigEntityInterface::class, $agent);
      $this->assertSame($id, $tools[$index]['id']);
      $this->assertSame((string) $agent->label(), $tools[$index]['label']);
      $this->assertSame((string) $agent->get('description'), $tools[$index]['description']);
    }

    // The main agent is deliberately not sent to the front end.
    $this->assertArrayNotHasKey('main_agent', $settings['canvas']['ai']);
  }

  /**
   * Tests that no tools key is emitted when no Tools are selected.
   */
  public function testNoToolsKeyWhenNoneSelected(): void {
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_dev_ai']);
    $this->refreshContainer();

    $this->config('canvas_dev_ai.settings')->set('tools', [])->save();

    $settings = $this->alterJsSettings();
    $this->assertTrue($settings['canvas']['aiDevMode']);
    $this->assertArrayNotHasKey('tools', $settings['canvas']['ai'] ?? []);
  }

  /**
   * Tests that uninstalling removes the config object and the JS key.
   *
   * The local task's availability and removal are covered by
   * \Drupal\Tests\canvas_ai\Functional\Form\CanvasDevAiAgentSelectionFormTest,
   * because enumerating local task definitions in a kernel test triggers a PHP
   * warning from core's views local-task deriver, whose routes are not built.
   */
  public function testUninstallRemovesEverything(): void {
    $module_installer = $this->container->get(ModuleInstallerInterface::class);

    $module_installer->install(['canvas_dev_ai']);
    $this->refreshContainer();

    // Present while installed.
    $this->assertFalse($this->config('canvas_dev_ai.settings')->isNew());
    $this->assertArrayHasKey('tools', $this->alterJsSettings()['canvas']['ai']);

    $module_installer->uninstall(['canvas_dev_ai']);
    $this->refreshContainer();

    // Gone once uninstalled. Uninstalling a module deletes the config objects
    // prefixed with its name, so the settings object disappears with it.
    $this->assertTrue($this->config('canvas_dev_ai.settings')->isNew());
    $this->assertArrayNotHasKey('tools', $this->alterJsSettings()['canvas']['ai'] ?? []);
  }

  /**
   * Re-fetches the container after a module install or uninstall rebuild.
   */
  private function refreshContainer(): void {
    $container = \Drupal::getContainer();
    \assert($container instanceof ContainerBuilder);
    $this->container = $container;
  }

  /**
   * Runs the js_settings alter hooks on a minimal Canvas settings array.
   *
   * @return array
   *   The altered settings array.
   */
  private function alterJsSettings(): array {
    $settings = ['canvas' => ['aiExtensionAvailable' => TRUE]];
    $assets = new AttachedAssets();
    $this->container->get(ModuleHandlerInterface::class)->alter('js_settings', $settings, $assets);
    return $settings;
  }

}
