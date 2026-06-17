<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Traits;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\canvas\Entity\JavaScriptComponent;

/**
 * Asserts a config entity stays valid when a component instance falls back.
 *
 * When a component's defining module or theme is removed, its instances switch
 * to the Fallback source, whose generated `inputs` config schema mapping is
 * empty. Strict config schema validation then rejects every stored input key as
 * unsupported unless the fallback generator accepts the instance's actual input
 * keys. Every component-tree config entity (ContentTemplate, PageRegion,
 * Pattern) must remain valid in that state.
 *
 * @see \Drupal\canvas\ComponentSource\FallbackComponentInstanceInputsConfigSchemaGenerator::refineForInstance()
 */
trait FallbackComponentTreeConfigValidationTestTrait {

  /**
   * Tests that a Fallback component instance's stored inputs stay valid.
   */
  public function testFallbackComponentInputsRemainValid(): void {
    // Create a code component and place an instance of it carrying two explicit
    // inputs, so a component with inputs is present to be affected by the
    // fallback.
    $js_component = JavaScriptComponent::create([
      'machineName' => 'fallback_test_cta',
      'name' => 'Fallback Test CTA',
      'status' => TRUE,
      'props' => [
        'text' => [
          'type' => 'string',
          'title' => 'Text',
          'examples' => ['Hello world'],
        ],
        'href' => [
          'type' => 'string',
          'format' => 'uri',
          'title' => 'URL',
          'examples' => ['https://example.com'],
        ],
      ],
      'required' => [],
      'js' => ['original' => '', 'compiled' => ''],
      'css' => ['original' => '', 'compiled' => ''],
      'dataDependencies' => [],
    ]);
    $js_component->save();

    $component_id = 'js.fallback_test_cta';
    $component = Component::load($component_id);
    \assert($component instanceof ComponentInterface);
    $active_version = $component->getActiveVersion();

    $tree = $this->entity->get('component_tree') ?? [];
    $tree[] = [
      'uuid' => 'f0a1b2c3-d4e5-46f7-88a9-bacbdcedfe01',
      'component_id' => $component_id,
      'component_version' => $active_version,
      'inputs' => [
        'text' => 'Hello world',
        'href' => 'https://example.com',
      ],
    ];
    $this->entity->set('component_tree', $tree)->save();

    // The entity is valid while the component source is available.
    $this->assertValidationErrors([]);

    // Delete the code component the instance depends on. Because the instance is
    // saved (a usage exists), Component::onDependencyRemoval() converts the
    // Component to the Fallback source through its real code path instead of
    // deleting it — the same strategy as
    // \Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource\FallbackInputTest::testFallbackInputCanBeRecovered,
    // which triggers a fallback by deleting the config the component depends on.
    $js_component->delete();
    self::assertSame(
      ComponentInterface::FALLBACK_VERSION,
      Component::load($component_id)?->getComponentSource()->getPluginId(),
    );

    // The stored `text` and `href` inputs must still validate against the
    // now-empty fallback config schema mapping.
    $this->assertValidationErrors([]);
  }

}
