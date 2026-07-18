<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Icon;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Icon\IconPropShape;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponentDiscovery;
use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\canvas\PropShape\PropShape;
use Drupal\canvas\PropShape\PropShapeRepositoryInterface;
use Drupal\Core\Render\Component\Exception\InvalidComponentException;
use Drupal\Core\Theme\Component\ComponentValidator;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the icon prop shape: matching, scope enforcement, and resolution.
 *
 * @legacy-covers \Drupal\canvas\Icon\IconPropShape
 * @legacy-covers \Drupal\canvas\Icon\IconResolver
 */
#[Group('canvas')]
final class IconPropShapeKernelTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...self::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    // Provides the `canvas_test` icon pack with 14 icons.
    'canvas_test_icons',
  ];

  /**
   * The icon shape maps to a string field using the icon picker widget.
   */
  public function testStorablePropShape(): void {
    $repository = $this->container->get(PropShapeRepositoryInterface::class);

    $unscoped = $repository->getStorablePropShape(PropShape::normalize([
      'type' => 'string',
      '$ref' => IconPropShape::SCHEMA_REF,
    ]));
    $this->assertNotNull($unscoped);
    $this->assertSame('canvas_icon', $unscoped->fieldWidget);
    $this->assertSame('ℹ︎string␟value', (string) $unscoped->fieldTypeProp);

    $scoped = $repository->getStorablePropShape(PropShape::normalize([
      'type' => 'string',
      '$ref' => IconPropShape::SCHEMA_REF,
      'pattern' => IconPropShape::buildScopePattern(['canvas_test', 'phosphor']),
    ]));
    $this->assertNotNull($scoped);
    $this->assertSame('canvas_icon', $scoped->fieldWidget);

    // A dereferenced icon shape (no `$ref`, only the generated pattern), as
    // produced by schema resolution for scoped props, is recognized too.
    $dereferenced = $repository->getStorablePropShape(PropShape::normalize([
      'type' => 'string',
      'pattern' => '^(canvas_test):.+$',
    ]));
    $this->assertNotNull($dereferenced);
    $this->assertSame('canvas_icon', $dereferenced->fieldWidget);
  }

  /**
   * Pack scoping is enforced server-side by JSON Schema validation.
   */
  public function testScopeEnforcement(): void {
    $js_component = $this->createIconPropComponent();
    $plugin = JsComponentDiscovery::buildEphemeralSdcPluginInstance($js_component);
    $validator = $this->container->get(ComponentValidator::class);

    // An icon from an allowed pack passes.
    $this->assertTrue($validator->validateProps(['icon' => 'canvas_test:star'], $plugin));

    // An icon from a pack not allowed for this prop is rejected, even though
    // the value is a well-formed icon id.
    $this->expectException(InvalidComponentException::class);
    $this->expectExceptionMessageMatches('/pattern/');
    $validator->validateProps(['icon' => 'phosphor:acorn'], $plugin);
  }

  /**
   * Stored icon ids resolve to renderable values at render time.
   */
  public function testRenderTimeResolution(): void {
    $this->createIconPropComponent()->save();
    $component = Component::load('js.icon_test');
    $this->assertInstanceOf(Component::class, $component);
    $source = $component->getComponentSource();
    $this->assertInstanceOf(JsComponent::class, $source);

    $island = $source->renderComponent([
      'props' => ['icon' => new EvaluationResult('canvas_test:star')],
    ], [], 'test-uuid');
    $resolved = $island['#props']['icon'];
    $this->assertIsArray($resolved);
    $this->assertSame('canvas_test:star', $resolved['id']);
    $this->assertStringStartsWith('<svg', $resolved['svg']);
    $this->assertStringContainsString('xmlns="http://www.w3.org/2000/svg"', $resolved['svg']);
    $this->assertArrayNotHasKey('url', $resolved);
    // Resolved icon values depend on the installed icon packs.
    $this->assertContains('icon_pack_plugin', $island['#cache']['tags']);
    $this->assertContains('icon_pack_collector', $island['#cache']['tags']);
    $this->assertContains('config:icon_library_list', $island['#cache']['tags']);

    // A stored id whose icon no longer exists resolves to NULL: the component
    // renders nothing for it, and a warning is logged.
    // @see \Drupal\canvas\Icon\IconResolver::resolve()
    $island = $source->renderComponent([
      'props' => ['icon' => new EvaluationResult('canvas_test:does_not_exist')],
    ], [], 'test-uuid');
    $this->assertNull($island['#props']['icon']);
  }

  /**
   * Creates a code component with an icon prop scoped to the test pack.
   */
  private function createIconPropComponent(): JavaScriptComponent {
    $js_component = JavaScriptComponent::create([
      'machineName' => 'icon_test',
      'name' => 'Icon test',
      'status' => TRUE,
      'props' => [
        'icon' => [
          'title' => 'Icon',
          'type' => 'string',
          '$ref' => IconPropShape::SCHEMA_REF,
          'pattern' => IconPropShape::buildScopePattern(['canvas_test']),
          'examples' => ['canvas_test:star'],
        ],
      ],
      'required' => [],
      'js' => [
        'original' => 'export default function IconTest({ icon }) { return null; }',
        'compiled' => 'export default function IconTest({ icon }) { return null; }',
      ],
      'css' => ['original' => '', 'compiled' => ''],
      'dataDependencies' => [],
    ]);
    return $js_component;
  }

}
