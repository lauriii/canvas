<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Kernel;

use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests external component restoration during Canvas Headless uninstall.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas_headless')]
final class ExternalComponentUninstallTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...self::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'serialization',
    'consumers',
    'simple_oauth',
    'custom_elements',
    'canvas_headless',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    include_once __DIR__ . '/../../../canvas_headless.install';
  }

  /**
   * Tests that uninstall restores external components with fallback code.
   */
  public function testComponentsWithFallbackImplementationsAreRestored(): void {
    $dependency = JavaScriptComponent::create([
      'machineName' => 'local_dependency',
      'name' => 'Local dependency',
      'status' => TRUE,
      'props' => [],
      'required' => [],
      'slots' => [],
      'js' => ['original' => '', 'compiled' => ''],
      'css' => ['original' => '', 'compiled' => ''],
      'dataDependencies' => [],
    ]);
    $dependency->save();

    $js = [
      'original' => 'export default function Restored() {}',
      'compiled' => 'export default function Restored() {}',
    ];
    $css = [
      'original' => '.restored { display: block; }',
      'compiled' => '.restored{display:block}',
    ];
    JavaScriptComponent::create([
      'machineName' => 'restored',
      'name' => 'Restored component',
      'status' => TRUE,
      'type' => 'external',
      'props' => [],
      'required' => [],
      'slots' => [],
      'js' => $js,
      'css' => $css,
      'dataDependencies' => ['drupalSettings' => ['v0.pageTitle']],
      'dependencies' => [
        'enforced' => [
          'config' => [$dependency->getConfigDependencyName()],
        ],
      ],
    ])->save();
    JavaScriptComponent::create([
      'machineName' => 'metadata_only',
      'name' => 'Metadata-only component',
      'status' => TRUE,
      'type' => 'external',
      'props' => [],
      'required' => [],
      'slots' => [],
      'dataDependencies' => [],
    ])->save();

    canvas_headless_uninstall();

    $restored = JavaScriptComponent::load('restored');
    self::assertInstanceOf(JavaScriptComponent::class, $restored);
    self::assertFalse($restored->isExternal());
    self::assertSame('react', $restored->getComponentType());
    self::assertSame($js, $restored->get('js'));
    self::assertSame($css, $restored->get('css'));
    self::assertSame(['drupalSettings' => ['v0.pageTitle']], $restored->get('dataDependencies'));
    self::assertContains($dependency->getConfigDependencyName(), $restored->getDependencies()['config']);

    $metadata_only = JavaScriptComponent::load('metadata_only');
    self::assertInstanceOf(JavaScriptComponent::class, $metadata_only);
    self::assertTrue($metadata_only->isExternal());

    // Repeated execution is harmless.
    canvas_headless_uninstall();
    self::assertSame('react', JavaScriptComponent::load('restored')?->getComponentType());
    self::assertTrue(JavaScriptComponent::load('metadata_only')?->isExternal());
  }

}
