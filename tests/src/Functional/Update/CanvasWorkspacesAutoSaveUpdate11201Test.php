<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional\Update;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\AutoSave\Workspace\AutoSaveWorkspace;
use Drupal\canvas\Entity\Page;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Workspaces auto-save upgrade path end-to-end on the bare Canvas dump.
 *
 * {@link canvas_update_11201()} installs the staging entity schema, ensures the
 * Workspaces module is enabled, and creates the shared auto-save workspace.
 * {@link canvas_post_update_0023_migrate_auto_save_to_workspace()} moves legacy
 * `canvas.auto_save` key-value entries into workspace staging. This test seeds
 * legacy KV data before updates and asserts it is migrated away afterward (see
 * {@link \Drupal\canvas\AutoSave\Workspace\LegacyAutoSaveMigrator}).
 *
 * @legacy-covers \canvas_update_11201
 * @legacy-covers \canvas_post_update_0023_migrate_auto_save_to_workspace
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('canvas_update_path')]
final class CanvasWorkspacesAutoSaveUpdate11201Test extends CanvasUpdatePathTestBase {

  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles(): void {
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/drupal-11.2.10-with-canvas-1.2.0.bare.php.gz';
  }

  /**
   * Ensures legacy KV auto-save is migrated into workspace staging after updates.
   */
  public function testLegacyKvAutoSaveMigratedToWorkspaceAfterUpdates(): void {
    $storage = \Drupal::entityTypeManager()->getStorage(Page::ENTITY_TYPE_ID);
    $ids = $storage->getQuery()->accessCheck(FALSE)->range(0, 1)->execute();
    self::assertNotEmpty($ids, 'The update fixture must contain at least one canvas page.');
    $page = $storage->load(reset($ids));
    self::assertInstanceOf(Page::class, $page);

    $key = AutoSaveManager::getAutoSaveKey($page);
    $legacy = [
      'entity_type' => Page::ENTITY_TYPE_ID,
      'entity_id' => (string) $page->id(),
      'data' => [
        'title' => 'Legacy KV auto-save migration test',
        'components' => [],
      ],
      'langcode' => $page->language()->getId(),
      'label' => 'Legacy KV auto-save migration test',
      'data_hash' => 'legacy_workspace_migration_hash',
      'client_id' => NULL,
      'owner' => 1,
      'updated' => 1700000000,
    ];

    $kv = \Drupal::keyValue(AutoSaveManager::AUTO_SAVE_STORE);
    $kv->set($key, $legacy);
    self::assertSame($legacy, $kv->get($key));

    $this->runUpdates();

    self::assertNull($kv->get($key), 'Legacy key-value auto-save must be removed after migration to workspace staging.');

    /** @var \Drupal\workspaces\WorkspaceInterface $workspace */
    $workspace = \Drupal::entityTypeManager()->getStorage('workspace')->load(AutoSaveWorkspace::ID);
    self::assertNotNull($workspace);
    self::assertSame(AutoSaveWorkspace::LABEL, $workspace->label());
    self::assertSame('canvas', $workspace->get('provider')->value);

    // Migration preserves attribution: the pending change stays attributed to
    // the legacy editor with the legacy edit time, not to the migration run.
    $auto_save_manager = \Drupal::service(AutoSaveManager::class);
    \assert($auto_save_manager instanceof AutoSaveManager);
    $list = $auto_save_manager->getAllAutoSaveList(FALSE, FALSE);
    self::assertArrayHasKey($key, $list);
    self::assertSame(1, $list[$key]['owner']);
    self::assertSame(1700000000, $list[$key]['updated']);
    self::assertSame('Legacy KV auto-save migration test', $list[$key]['label']);
  }

}
