<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Entity;

use Drupal\canvas\Entity\Page;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\pathauto\PathautoState;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\PageTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Page Pathauto Integration.
 */
#[RunTestsInSeparateProcesses]
#[RequiresMethod(PathautoState::class, 'getPathautoStateKey')]
#[Group('canvas')]
final class PagePathautoIntegrationTest extends CanvasKernelTestBase {

  use PageTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...self::PAGE_TEST_MODULES,
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installPageEntitySchema();
    $this->container->get(ModuleInstallerInterface::class)->install(['pathauto']);
  }

  /**
   * @see \Drupal\canvas\Hook\PageHooks::ensurePathautoSkipped()
   */
  public function testPathautoSkippedOnSave(): void {
    // A page whose path field is never set: the computed path field always
    // materializes one item.
    $page = Page::create(['title' => 'Page without alias']);
    self::assertSaveWithoutViolations($page);
    self::assertSame(PathautoState::SKIP, $this->getPathautoState($page));

    // A page with an explicitly set empty alias, as the publish flow
    // produces: the item is filtered out as empty before presave hooks run.
    $page = Page::create([
      'title' => 'Page with explicitly empty alias',
      'path' => ['alias' => ''],
    ]);
    self::assertSaveWithoutViolations($page);
    self::assertSame(PathautoState::SKIP, $this->getPathautoState($page));

    $page = Page::create([
      'title' => 'Page with alias',
      'path' => ['alias' => '/page-with-alias'],
    ]);
    self::assertSaveWithoutViolations($page);
    self::assertSame(PathautoState::SKIP, $this->getPathautoState($page));
    self::assertSame('/page-with-alias', $page->get('path')->first()?->getValue()['alias']);
  }

  /**
   * Gets the persisted pathauto state for a page.
   */
  private function getPathautoState(Page $page): mixed {
    return $this->container->get('keyvalue')
      ->get('pathauto_state.' . Page::ENTITY_TYPE_ID)
      ->get((string) PathautoState::getPathautoStateKey((int) $page->id()));
  }

}
