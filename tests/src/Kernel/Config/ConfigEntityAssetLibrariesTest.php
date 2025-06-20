<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Config;

use Drupal\Core\Asset\LibraryDiscoveryInterface;
use Drupal\Core\Cache\CacheCollectorInterface;
use Drupal\experience_builder\Entity\AssetLibrary;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\JsComponent;
use Drupal\KernelTests\KernelTestBase;

/**
 * @covers \Drupal\experience_builder\Hook\LibraryHooks::libraryInfoBuild()
 * @group experience_builder
 */
final class ConfigEntityAssetLibrariesTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'experience_builder',
    'user',
    'system',
    'media',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['system']);
  }

  private function getXbAssetLibraries(): array {
    $library_discovery = \Drupal::service(LibraryDiscoveryInterface::class);
    assert($library_discovery instanceof CacheCollectorInterface);

    // Get the (cached) XB asset libraries.
    $discovered = $library_discovery->getLibrariesByExtension('experience_builder');

    // Simulate this having been a single request/response, and the response has
    // finished. For cache collectors, the destruct() method is called, which
    // causes its results to be written ("collected") to its cache.
    $library_discovery->destruct();

    // Prepare for the next request.
    $library_discovery->reset();

    // Return what's discovered for this "simulated request/response".
    return $discovered;
  }

  public function testLibraryGeneration(): void {
    $js_component_id = $this->randomMachineName();
    $component_id = JsComponent::componentIdFromJavascriptComponentId($js_component_id);

    // When the JS component does not exist, nor should the component config
    // entity.
    $component = Component::load($component_id);
    self::assertNull($component);

    // Create a JavaScript component.
    $some_js = 'console.log("hey");';
    $js_component = JavaScriptComponent::create([
      'machineName' => $js_component_id,
      'name' => $this->getRandomGenerator()->sentences(5),
      'status' => FALSE,
      'props' => [
        'title' => [
          'type' => 'string',
          'title' => 'Title',
          'examples' => ['Title'],
        ],
      ],
      'required' => ['title'],
      'slots' => [],
      'js' => [
        'original' => $some_js,
        'compiled' => $some_js,
      ],
      'css' => [
        'original' => '',
        'compiled' => '',
      ],
    ]);
    $js_component->save();
    // And an asset library.
    $library_id = $this->randomMachineName();
    $library = AssetLibrary::create([
      'id' => $library_id,
      'label' => 'Test',
      'css' => [
        'original' => '',
        'compiled' => '',
      ],
      'js' => [
        'original' => '',
        'compiled' => '',
      ],
    ]);
    $library->save();

    $discovered = $this->getXbAssetLibraries();
    $asset_library_draft = \sprintf('asset_library.%s.draft', $library_id);
    $asset_library = \sprintf('asset_library.%s', $library_id);
    $js_component_draft = \sprintf('astro_island.%s.draft', $js_component_id);
    $js_component_library = \sprintf('astro_island.%s', $js_component_id);
    // Even though the saved entities have no js or css, the draft libraries
    // should exist.
    self::assertArrayHasKey($asset_library_draft, $discovered);
    self::assertArrayHasKey('css', $discovered[$asset_library_draft]);
    self::assertArrayHasKey('js', $discovered[$asset_library_draft]);
    self::assertArrayHasKey($js_component_draft, $discovered);
    self::assertArrayHasKey('css', $discovered[$js_component_draft]);
    // JS is attached via an astro island and not a library.
    self::assertArrayHasKey('js', $discovered[$js_component_draft]);
    self::assertCount(0, $discovered[$js_component_draft]['js']);
    // And so should the actual libraries.
    self::assertArrayHasKey($js_component_library, $discovered);
    self::assertArrayHasKey($asset_library, $discovered);

    // Now let's add some actual CSS/JS to the AssetLibrary config entity.
    $some_css = '.big { font-size: 3rem; }';
    $library->set('js', [
      'original' => $some_js,
      'compiled' => $some_js,
    ])->set('css', [
      'original' => $some_css,
      'compiled' => $some_css,
    ])->save();
    $discovered = $this->getXbAssetLibraries();
    self::assertArrayHasKey($asset_library_draft, $discovered);
    self::assertArrayHasKey('css', $discovered[$asset_library_draft]);
    self::assertArrayHasKey('js', $discovered[$asset_library_draft]);
    self::assertArrayHasKey($js_component_draft, $discovered);
    self::assertArrayHasKey('css', $discovered[$js_component_draft]);
    // JS is attached via an astro island and not a library.
    self::assertArrayHasKey('js', $discovered[$js_component_draft]);
    self::assertCount(0, $discovered[$js_component_draft]['js']);
    // And the actual library should exist now too.
    self::assertArrayHasKey($js_component_library, $discovered);
    self::assertArrayHasKey($asset_library, $discovered);
    self::assertArrayHasKey('css', $discovered[$asset_library]);
    self::assertArrayHasKey('js', $discovered[$asset_library]);

    // Finally, add some actual CSS/JS to the JavaScriptComponent config entity.
    $js_component->set('js', [
      'original' => $some_js,
      'compiled' => $some_js,
    ])->set('css', [
      'original' => $some_css,
      'compiled' => $some_css,
    ])->save();
    $discovered = $this->getXbAssetLibraries();
    self::assertArrayHasKey($asset_library_draft, $discovered);
    self::assertArrayHasKey('css', $discovered[$asset_library_draft]);
    self::assertArrayHasKey('js', $discovered[$asset_library_draft]);
    self::assertArrayHasKey($js_component_draft, $discovered);
    self::assertArrayHasKey('css', $discovered[$js_component_draft]);
    // JS is attached via an astro island and not a library.
    self::assertArrayHasKey('js', $discovered[$js_component_draft]);
    self::assertCount(0, $discovered[$js_component_draft]['js']);
    // And the actual libraries should exist, for both the AssetLibrary config
    // entity as before, but now also for the JavaScriptComponent config entity.
    self::assertArrayHasKey($js_component_library, $discovered);
    self::assertArrayHasKey($asset_library, $discovered);
    self::assertArrayHasKey('css', $discovered[$asset_library]);
    self::assertArrayHasKey('js', $discovered[$asset_library]);
    self::assertArrayHasKey('css', $discovered[$js_component_library]);
    self::assertArrayHasKey('js', $discovered[$js_component_library]);
    self::assertCount(0, $discovered[$js_component_library]['js']);
  }

}
