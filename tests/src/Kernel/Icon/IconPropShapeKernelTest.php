<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Icon;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Icon\IconPropShape;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponentDiscovery;
use Drupal\canvas\Plugin\Canvas\ComponentSource\SingleDirectoryComponent;
use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\canvas\PropShape\PropShape;
use Drupal\canvas\PropShape\PropShapeRepositoryInterface;
use Drupal\Core\Render\Component\Exception\InvalidComponentException;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Theme\Component\ComponentValidator;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the icon prop shape: matching, scope enforcement, and resolution.
 *
 * @legacy-covers \Drupal\canvas\Icon\IconPropShape
 * @legacy-covers \Drupal\canvas\Icon\IconResolver
 */
#[Group('canvas')]
final class IconPropShapeKernelTest extends CanvasKernelTestBase {

  use GenerateComponentConfigTrait;

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
    $this->assertSame('ℹ︎canvas_icon␟value', (string) $unscoped->fieldTypeProp);

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
   * An icon prop on an SDC gets the icon widget and resolves at render time.
   *
   * Icon handling is not a code-component feature: because it is isolated in
   * the shared prop-shape/field-type layer, any component source that declares
   * an icon-shaped prop gets the icon picker widget and render-time resolution.
   * This proves it for a plain Single-Directory Component.
   *
   * @see \Drupal\canvas\Plugin\Field\FieldType\IconItem
   * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::resolveIconProps()
   */
  public function testSdcIconPropResolves(): void {
    $this->generateComponentConfig();

    $component = Component::load('sdc.canvas_test_icons.icon-card');
    $this->assertInstanceOf(Component::class, $component);

    // The icon-shaped SDC prop maps to the dedicated icon field type and the
    // icon picker widget — the same mapping code-component props get.
    $settings = $component->getSettings();
    $this->assertSame('canvas_icon', $settings['prop_field_definitions']['icon']['field_type']);
    $this->assertSame('canvas_icon', $settings['prop_field_definitions']['icon']['field_widget']);

    $source = $component->getComponentSource();
    $this->assertInstanceOf(SingleDirectoryComponent::class, $source);

    // The stored icon id resolves to a render array carrying inline SVG, which
    // the SDC template renders without the author managing SVG sources.
    $build = $source->renderComponent([
      'props' => [
        'icon' => new EvaluationResult('canvas_test:star'),
        'label' => new EvaluationResult('Star'),
      ],
    ], [], 'test-uuid');
    $this->assertIsArray($build['#props']['icon']);
    $this->assertStringStartsWith('<svg', (string) $build['#props']['icon']['#markup']);
    // Resolved icon values depend on the installed icon packs.
    $this->assertContains('icon_pack_plugin', $build['#cache']['tags']);
    $this->assertContains('config:icon_library_list', $build['#cache']['tags']);

    // Rendering the SDC exercises core's prop validation — a render-array icon
    // value passes it — and emits the inline SVG on the page.
    $html = (string) $this->container->get(RendererInterface::class)->renderInIsolation($build);
    $this->assertStringContainsString('<svg', $html);
    $this->assertStringContainsString('Star', $html);

    // An unresolvable id resolves to NULL: the template renders no icon.
    $build = $source->renderComponent([
      'props' => [
        'icon' => new EvaluationResult('canvas_test:does_not_exist'),
        'label' => new EvaluationResult('Missing'),
      ],
    ], [], 'test-uuid');
    $this->assertNull($build['#props']['icon']);
  }

  /**
   * A multi-value icon prop resolves every id in the list.
   *
   * Multi-value icon props (`type: array` with icon `items`) are not creatable
   * through the code editor UI, but are storable via hand-authored config.
   * Single-vs-multi is decided by the prop's cardinality, so each stored id in
   * the list is resolved.
   */
  public function testMultiValueIconPropResolves(): void {
    JavaScriptComponent::create([
      'machineName' => 'multi_icon_test',
      'name' => 'Multi icon test',
      'status' => TRUE,
      'props' => [
        'icons' => [
          'title' => 'Icons',
          'type' => 'array',
          'items' => [
            'type' => 'string',
            '$ref' => IconPropShape::SCHEMA_REF,
          ],
        ],
      ],
      'required' => [],
      'js' => [
        'original' => 'export default function T({ icons }) { return null; }',
        'compiled' => 'export default function T({ icons }) { return null; }',
      ],
      'css' => ['original' => '', 'compiled' => ''],
      'dataDependencies' => [],
    ])->save();

    $component = Component::load('js.multi_icon_test');
    $this->assertInstanceOf(Component::class, $component);
    $this->assertSame('canvas_icon', $component->getSettings()['prop_field_definitions']['icons']['field_type']);

    $source = $component->getComponentSource();
    $this->assertInstanceOf(JsComponent::class, $source);

    $island = $source->renderComponent([
      'props' => ['icons' => new EvaluationResult(['canvas_test:star', 'canvas_test:heart'])],
    ], [], 'test-uuid');
    $resolved = $island['#props']['icons'];
    $this->assertIsArray($resolved);
    $this->assertCount(2, $resolved);
    $this->assertSame('canvas_test:star', $resolved[0]['id']);
    $this->assertStringStartsWith('<svg', $resolved[0]['svg']);
    $this->assertSame('canvas_test:heart', $resolved[1]['id']);
    $this->assertStringStartsWith('<svg', $resolved[1]['svg']);
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
