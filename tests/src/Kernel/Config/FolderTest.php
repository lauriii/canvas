<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Config;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\CanvasNotificationHandler;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Folder;
use Drupal\canvas\Entity\Page;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[Group('canvas')]
#[RunTestsInSeparateProcesses]
class FolderTest extends CanvasKernelTestBase {

  use UserCreationTrait;

  protected Folder $entity;

  protected function setUp(): void {
    parent::setUp();
    $this->entity = Folder::create([
      'name' => 'Test folder, please ignore',
      'configEntityTypeId' => Component::ENTITY_TYPE_ID,
    ]);
    $this->entity->save();
  }

  public function testFolderAutoCreationValidation(): void {
    $folders = Folder::loadMultiple();
    // 1. At the start, the ::setUp()-created Folder must exist.
    // Additional Folders may already exist due to auto-generated Block
    // Components.
    $this->assertArrayHasKey($this->entity->id(), $folders);
    $this->enableModules(['canvas_test_sdc']);

    // 2. Generate Component config entities, this will create additional Folder
    // entities.
    $this->container->get(ComponentSourceManager::class)->generateComponents();
    $folders = Folder::loadMultiple();

    // 3. Folder created during ::setUp() still exists.
    $this->assertArrayHasKey($this->entity->id(), $folders);

    // 4. It is not the only Folder entity anymore.
    $folder_labels = \array_map(fn (Folder $folder) => $folder->label(), \array_values($folders));
    \sort($folder_labels);
    $this->assertEquals([
      'Atom/Media',
      'Atom/Tabs',
      'Atom/Text',
      'Container',
      'Container/Special',
      'Forms',
      'Menus',
      'Other',
      'Status',
      'System',
      'Test folder, please ignore',
      'core',
    ], $folder_labels);

    // 5. Delete all folders and regenerate Components. As no new Components
    // will be created, no Folder entities will be created either.
    foreach ($folders as $folder) {
      $folder->delete();
    }
    $this->container->get(ComponentSourceManager::class)->generateComponents();
    $folders = Folder::loadMultiple();

    $folder_labels = \array_map(fn (Folder $folder) => $folder->label(), \array_values($folders));
    \sort($folder_labels);
    $this->assertEquals([], $folder_labels);

    // 6. Delete all Components and folders and regenerate Components. As new
    // Components will created, Folder entities matching Component default
    // folder values will be created.
    foreach ($folders as $folder) {
      $folder->delete();
    }
    foreach (Component::loadMultiple() as $component) {
      $component->delete();
    }

    $this->container->get(ComponentSourceManager::class)->generateComponents();
    $folders = Folder::loadMultiple();

    $folder_labels = \array_map(fn (Folder $folder) => $folder->label(), \array_values($folders));
    \sort($folder_labels);
    $this->assertEquals([
      'Atom/Media',
      'Atom/Tabs',
      'Atom/Text',
      'Container',
      'Container/Special',
      'Forms',
      'Menus',
      'Other',
      'Status',
      'System',
      'core',
    ], $folder_labels);
  }

  public function testComponentDeletionModifiesFolder(): void {
    $this->enableModules(['canvas_test_sdc']);
    $this->container->get(ComponentSourceManager::class)->generateComponents();

    // 1. Load Folder that contains 'sdc.canvas_test_sdc.card'.
    $original_folder = Folder::loadByItemAndConfigEntityTypeId('sdc.canvas_test_sdc.card', Component::ENTITY_TYPE_ID);
    \assert($original_folder instanceof Folder);

    // 2. Remove 'sdc.canvas_test_sdc.card' from its default Folder.
    $original_folder->removeItem('sdc.canvas_test_sdc.card')->save();

    // 3. Add 'sdc.canvas_test_sdc.card' to our test Folder.
    $this->entity->addItems(['sdc.canvas_test_sdc.card'])->save();
    $this->assertEquals(['sdc.canvas_test_sdc.card'], $this->entity->get('items'));

    // 4. Delete Component entity, this should remove it from test Folder.
    Component::load('sdc.canvas_test_sdc.card')?->delete();
    $this->assertEquals([], Folder::load($this->entity->id())?->get('items'));

    // 5. Delete another Component from its default Folder, this should remove
    // the Component from Folder items list.
    $default_folder_id = Folder::loadByItemAndConfigEntityTypeId('sdc.canvas_test_sdc.attributes', Component::ENTITY_TYPE_ID);
    \assert($default_folder_id instanceof Folder);
    Component::load('sdc.canvas_test_sdc.attributes')?->delete();
    $this->assertNotContains('sdc.canvas_test_sdc.attributes', Folder::load($default_folder_id->id())?->get('items'));
  }

  /**
   * A Folder of pages lists page UUIDs, and forgets a deleted page.
   */
  public function testPageFolder(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('media');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $page = Page::create(['title' => 'A page']);
    $page->save();
    $unknown_uuid = $this->container->get('uuid')->generate();

    $folder = Folder::create([
      'name' => 'Campaigns',
      'configEntityTypeId' => Page::ENTITY_TYPE_ID,
      'items' => ['not-a-uuid'],
    ]);
    $violations = $folder->getTypedData()->validate();
    $this->assertCount(1, $violations);
    $this->assertSame("The 'not-a-uuid' folder item must be a UUID.", (string) $violations->get(0)->getMessage());

    // A UUID that matches no page is valid: config is deployed between
    // environments that do not hold the same pages.
    $folder->set('items', [$page->uuid(), $unknown_uuid]);
    self::assertEntityIsValid($folder);
    $folder->save();
    // Core sorts dependencies.
    $expected_dependencies = ["canvas_page:canvas_page:{$page->uuid()}", "canvas_page:canvas_page:$unknown_uuid"];
    sort($expected_dependencies);
    $this->assertSame($expected_dependencies, $folder->getDependencies()['content']);

    $page->delete();
    $this->assertSame([$unknown_uuid], Folder::load($folder->id())?->get('items'));
  }

  /**
   * The alias of a page starts with the URL path of its folder.
   *
   * @see docs/adr/0027-page-folders-can-set-the-url-path-of-their-pages.md
   */
  public function testPageFolderPath(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('media');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $auto_save = $this->container->get(AutoSaveManager::class);
    // The alias in the unpublished changes of a page, or NULL without any.
    $unpublished_alias = static function (Page $page) use ($auto_save): ?string {
      $entity = $auto_save->getAutoSaveEntity($page, TRUE)->entity;
      return $entity instanceof Page ? (string) $entity->get('path')->alias : NULL;
    };
    $create_page = static function (string $title, ?string $alias, bool $published = FALSE): Page {
      $page = Page::create(['title' => $title, 'status' => $published, 'path' => $alias === NULL ? NULL : ['alias' => $alias]]);
      $page->save();
      return $page;
    };
    $summer = $create_page('Summer', '/summer', TRUE);
    $no_alias = $create_page('Summer sale!', NULL);
    $landing = $create_page('Campaigns', '/campaigns');
    $offers = $create_page('Offers', '/offers');
    // Not in the folder, and already at the alias that $offers would get.
    $create_page('Other offers', '/campaigns/offers');

    $folder = Folder::create([
      'name' => 'Campaigns',
      'configEntityTypeId' => Page::ENTITY_TYPE_ID,
      'items' => [],
    ]);
    foreach (['Campaigns', '/campaigns/', '/Campaigns'] as $invalid_path) {
      $folder->set('path', $invalid_path);
      $violations = $folder->getTypedData()->validate();
      $this->assertCount(1, $violations, $invalid_path);
      $this->assertSame('path', $violations->get(0)->getPropertyPath());
    }
    $folder->set('path', '/products/shoes');
    self::assertEntityIsValid($folder);

    // A folder without a path changes no alias.
    $folder->set('path', NULL)->set('items', [$summer->uuid()])->save();
    $this->assertNull($unpublished_alias($summer));

    // Giving the folder a path, and adding pages to it, rewrites each alias as
    // an unpublished change. The live alias stays.
    $folder->set('path', '/campaigns')->set('items', [
      $summer->uuid(),
      $no_alias->uuid(),
      $landing->uuid(),
      $offers->uuid(),
    ])->save();
    $this->assertSame('/campaigns/summer', $unpublished_alias($summer));
    $this->assertSame('/summer', Page::load($summer->id())?->get('path')->alias);
    $this->assertSame('/campaigns/summer-sale', $unpublished_alias($no_alias));
    $this->assertNull($unpublished_alias($landing));
    $this->assertSame('/campaigns/offers-2', $unpublished_alias($offers));
    // A notification lists the live URLs that will change: only $summer is
    // published.
    $notifications = $this->container->get(CanvasNotificationHandler::class)->getRecent(1);
    $this->assertCount(1, $notifications);
    $this->assertSame('Summer will get a new URL', $notifications[0]['title']);
    $this->assertSame('It moves from /summer to /campaigns/summer when you publish it.', $notifications[0]['message']);

    // A page whose alias is outside the path of its folder is not valid.
    $alias_violations = static fn (Page $page): array => \array_values(\array_filter(
      \array_map(static fn ($violation) => $violation->getPropertyPath(), \iterator_to_array($page->validate())),
      static fn (string $property_path): bool => str_starts_with($property_path, 'path'),
    ));
    $this->assertSame(['path.0.alias'], $alias_violations($summer));
    $this->assertSame([], $alias_violations($landing));
    $summer_draft = $auto_save->getAutoSaveEntity($summer, TRUE)->entity;
    \assert($summer_draft instanceof Page);
    $this->assertSame([], $alias_violations($summer_draft));

    // A config import does not rewrite aliases.
    $folder->set('path', '/imported')->setSyncing(TRUE)->save();
    $this->assertSame('/campaigns/summer', $unpublished_alias($summer));
    $folder->set('path', '/campaigns')->save();
    $folder->setSyncing(FALSE);

    // A new path replaces the old one.
    $folder->set('path', '/deals')->save();
    $this->assertSame('/deals/summer', $unpublished_alias($summer));
    $this->assertSame('/deals/summer-sale', $unpublished_alias($no_alias));
    $this->assertSame('/deals', $unpublished_alias($landing));

    // A page that leaves the folder loses the path. That is its live alias
    // again, so nothing is left to publish.
    $folder->set('items', [$no_alias->uuid(), $landing->uuid(), $offers->uuid()])->save();
    $this->assertNull($unpublished_alias($summer));
    // The warning about its URL is replaced.
    $notifications = $this->container->get(CanvasNotificationHandler::class)->getRecent(1);
    $this->assertSame(['Summer keeps its URL'], \array_column($notifications, 'title'));
    $this->assertSame('It stays at /summer.', $notifications[0]['message']);
  }

  /**
   * Users who can see components can also see the folders grouping them.
   */
  public function testViewAccess(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    // Occupy uid 1, which bypasses access checks.
    $this->createUser();
    // Any permission that grants Canvas UI access works. This one is simplest.
    $canvas_user = $this->createUser([ContentTemplate::ADMIN_PERMISSION]);
    $other_user = $this->createUser();
    \assert($canvas_user instanceof User && $other_user instanceof User);

    self::assertTrue($this->entity->access('view', $canvas_user));
    self::assertTrue($this->entity->access('view label', $canvas_user));
    self::assertFalse($this->entity->access('update', $canvas_user));
    self::assertFalse($this->entity->access('delete', $canvas_user));
    self::assertFalse($this->entity->access('view', $other_user));
  }

}
