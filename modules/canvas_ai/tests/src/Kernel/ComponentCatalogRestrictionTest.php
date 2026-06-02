<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel;

use Drupal\canvas\Entity\Page;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests that hook_component_access filters the AI component catalog.
 *
 * @see \Drupal\canvas_ai_agents_Test\Hook\CanvasAiAgentsTestHooks::canvasAiAgentsTestComponentAccess()
 */
#[Group('canvas_ai')]
final class ComponentCatalogRestrictionTest extends CanvasKernelTestBase {

  use GenerateComponentConfigTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   *
   * These configs are provided by the ai_agents_test module
   * and are excluded because they fail config schema validation.
   */
  protected static $configSchemaCheckerExclusions = [
    'views.view.ai_agents_test_group_result',
    'views.view.ai_agents_test_result',
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'ai',
    'ai_agents',
    'canvas_ai',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installSchema('user', 'users_data');
    $this->generateComponentConfig();

    $user = $this->createUser([Page::CREATE_PERMISSION]);
    \assert($user !== FALSE);
    $this->container->get('current_user')->setAccount($user);
  }

  /**
   * Verifies the component catalog is filtered by hook_component_access.
   */
  public function testCatalogFilteredByHook(): void {
    // Step 1 — without canvas_ai_agents_test.
    $catalog = $this->getAccessibleComponentIds();

    $this->assertNotEmpty(
      array_filter($catalog, static fn(string $id) => str_starts_with($id, 'block.')),
      'Before canvas_ai_agents_test is installed, block components must be present.',
    );
    $this->assertNotEmpty(
      array_filter($catalog, static fn(string $id) => str_starts_with($id, 'sdc.canvas_test_sdc.')),
      'Before canvas_ai_agents_test is installed, canvas_test_sdc SDC components must be present.',
    );

    // Step 2 — with canvas_ai_agents_test installed.
    $this->container->get('module_installer')->install(['canvas_ai_agents_test']);

    $catalog = $this->getAccessibleComponentIds();

    $this->assertEmpty(
      array_filter($catalog, static fn(string $id) => str_starts_with($id, 'block.')),
      'While canvas_ai_agents_test is installed, block components must be absent.',
    );
    $this->assertNotEmpty(
      array_filter($catalog, static fn(string $id) => str_starts_with($id, 'sdc.canvas_test_sdc.')),
      'While canvas_ai_agents_test is installed, canvas_test_sdc SDC components must remain accessible.',
    );

    // Step 3 — after uninstalling canvas_ai_agents_test.
    $this->container->get('module_installer')->uninstall(['canvas_ai_agents_test']);

    $catalog = $this->getAccessibleComponentIds();

    $this->assertNotEmpty(
      array_filter($catalog, static fn(string $id) => str_starts_with($id, 'block.')),
      'After canvas_ai_agents_test is uninstalled, block components must be visible again.',
    );
    $this->assertNotEmpty(
      array_filter($catalog, static fn(string $id) => str_starts_with($id, 'sdc.canvas_test_sdc.')),
      'After canvas_ai_agents_test is uninstalled, canvas_test_sdc SDC components must remain accessible.',
    );
  }

  /**
   * Returns Component config entity IDs accessible to the current user.
   *
   * @return string[]
   *   Component config entity IDs that pass the access check.
   */
  private function getAccessibleComponentIds(): array {
    $yaml = $this->container->get('canvas_ai.page_builder_helper')
      ->getComponentContextForAi();
    /** @var array<string, mixed> $context */
    $context = Yaml::parse($yaml) ?? [];
    return \array_keys($context);
  }

}
