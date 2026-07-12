<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Kernel;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the preview-safe permission ceiling granularity plugin.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas_headless')]
class PreviewSafePermissionsTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'serialization',
    'consumers',
    'simple_oauth',
    'canvas_headless',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('path_alias');
  }

  /**
   * Tests that the ceiling contains only defined, declared permissions.
   */
  public function testCeilingDropsUndefinedPermissions(): void {
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();

    /** @var \Drupal\simple_oauth\Plugin\ScopeGranularityInterface $granularity */
    $granularity = $this->container->get('plugin.manager.scope_granularity')
      ->createInstance('canvas_headless_safe_permissions', []);

    $permissions = $granularity->getPermissions();

    // Declared and defined (node module is installed).
    $this->assertContains('access content', $permissions);
    $this->assertTrue($granularity->hasPermission('access content'));

    // Per-bundle revision viewing, declared per existing node type for
    // editors who hold it instead of the site-wide permission.
    $this->assertContains('view article revisions', $permissions);

    // Declared but undefined (the content_moderation module is not
    // installed): dropped.
    $this->assertNotContains('view latest version', $permissions);
    $this->assertFalse($granularity->hasPermission('view latest version'));

    // Never declared: write permissions must not be in the ceiling.
    $this->assertNotContains('bypass node access', $permissions);
    $this->assertFalse($granularity->hasPermission('administer nodes'));

    // The ceiling's one deliberate exception to view-only: unpublished
    // Canvas pages are viewable only through the canvas_page write
    // permissions, so the baseline declares them preview-safe.
    $this->assertContains('create canvas_page', $permissions);
    $this->assertContains('edit canvas_page', $permissions);
    $this->assertContains('delete canvas_page', $permissions);

    // The preview permission itself stays out of the ceiling: a preview
    // token never needs it, and minting is cookie-session-only anyway.
    $this->assertNotContains('access canvas headless preview', $permissions);

    // Every permission in the ceiling is a defined permission.
    $defined = \array_keys($this->container->get('user.permissions')->getPermissions());
    $this->assertSame([], array_diff($permissions, $defined));
  }

  /**
   * Tests that the granularity rejects any configuration.
   *
   * The ceiling is computed from hook implementations, so configuration
   * would be silently ignored — validateConfiguration() rejects it.
   */
  public function testConfigurationMustBeEmpty(): void {
    /** @var \Drupal\simple_oauth\Plugin\ScopeGranularityInterface $granularity */
    $granularity = $this->container->get('plugin.manager.scope_granularity')
      ->createInstance('canvas_headless_safe_permissions', []);

    // An empty configuration is the valid configuration.
    $granularity->validateConfiguration([]);

    $this->expectException(PluginException::class);
    $granularity->validateConfiguration(['permissions' => ['access content']]);
  }

}
