<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Event\PublishedEvent;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\canvas\Traits\AutoSaveRequestTestTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests that publishing dispatches PublishedEvent and auto-saving does not.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class PublishedEventTest extends CanvasKernelTestBase {

  use AutoSaveRequestTestTrait;
  use GenerateComponentConfigTrait;
  use RequestTrait;
  use UserCreationTrait;

  private const string UUID_A = 'a5c8f2e1-4b3d-4a6e-8f9c-1d2e3f4a5b6c';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...self::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'canvas_test_sdc',
    'field',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $this->generateComponentConfig();
    $this->setUpCurrentUser(permissions: [
      Page::EDIT_PERMISSION,
      AutoSaveManager::PUBLISH_PERMISSION,
    ]);
  }

  /**
   * Tests the event fires on publish, but not on auto-save, with the entities.
   */
  public function testPublishDispatchesEvent(): void {
    /** @var \Drupal\canvas\AutoSave\AutoSaveManager $autoSave */
    $autoSave = $this->container->get(AutoSaveManager::class);
    $version = $this->getHeadingComponentVersion();

    $page = Page::create([
      'title' => 'Original title',
      'status' => TRUE,
      'components' => [
        [
          'uuid' => self::UUID_A,
          'component_id' => 'sdc.canvas_test_sdc.heading',
          'component_version' => $version,
          'inputs' => ['text' => 'Hello', 'element' => 'h1'],
          'label' => 'A',
        ],
      ],
    ]);
    self::assertEntityIsValid($page);
    $page->save();

    // Capture every PublishedEvent the container dispatches.
    $captured = [];
    $this->container->get(EventDispatcherInterface::class)->addListener(
      PublishedEvent::class,
      function (PublishedEvent $event) use (&$captured): void {
        $captured[] = $event;
      },
    );

    // Auto-saving an edit must not dispatch the event.
    $page->set('title', 'Edited title');
    $autoSave->saveEntity($page);
    self::assertSame([], $captured, 'Auto-saving does not dispatch PublishedEvent.');

    // Publishing the auto-saved draft dispatches the event once, with the
    // published page among its entities.
    $auto_save_data = $this->getAutoSaveStatesFromServer();
    $page_key = AutoSaveManager::getAutoSaveKey($page);
    self::assertArrayHasKey($page_key, $auto_save_data);
    $response = $this->makePublishAllRequest([$page_key => $auto_save_data[$page_key]]);
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

    self::assertCount(1, $captured, 'Publishing dispatched PublishedEvent exactly once.');
    $references = $captured[0]->getEntityReferences();
    $page_refs = array_filter(
      $references,
      static fn (array $ref): bool => $ref['entityType'] === 'canvas_page' && $ref['id'] === (string) $page->id(),
    );
    self::assertCount(1, $page_refs, 'The published page is referenced.');
    $ref = array_values($page_refs)[0];
    self::assertSame($page->uuid(), $ref['uuid']);
    self::assertSame('en', $ref['langcode']);
  }

  private function getHeadingComponentVersion(): string {
    $component = $this->container->get(EntityTypeManagerInterface::class)
      ->getStorage('component')
      ->load('sdc.canvas_test_sdc.heading');
    \assert($component instanceof Component);
    return $component->getActiveVersion();
  }

}
