<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\EcosystemSupport;

use Drupal\canvas\Entity\Page;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests filtering JSON:API collections of Canvas pages.
 *
 * This is a regression test: as long as no module declared canvas_page filter
 * access to JSON:API, \Drupal\jsonapi\Access\TemporaryQueryGuard secured every
 * filtered collection query with an always-false condition, so every filtered
 * collection silently returned an empty result set.
 *
 * @see \Drupal\canvas\Hook\PageHooks::jsonapiPageFilterAccess()
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class JsonapiCollectionFilterTest extends CanvasKernelTestBase {

  use RequestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'jsonapi',
    'serialization',
  ];

  /**
   * A published page to filter for.
   */
  private Page $aboutPage;

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $this->installConfig(['user']);

    $this->setUpCurrentUser([], ['access content']);

    $this->aboutPage = Page::create([
      'title' => 'About',
      'status' => TRUE,
    ]);
    self::assertEntityIsValid($this->aboutPage);
    $this->aboutPage->save();
    foreach ([['title' => 'Contact', 'status' => TRUE], ['title' => 'Draft', 'status' => FALSE]] as $values) {
      $page = Page::create($values);
      self::assertEntityIsValid($page);
      $page->save();
    }
  }

  /**
   * Tests that filtered collections return the matching accessible pages.
   */
  public function testFilteredCollections(): void {
    // An unfiltered collection returns all published pages.
    self::assertSame(['About', 'Contact'], $this->getCollectionTitles([]));

    // Filtering by the published status must return the published pages, not
    // an empty result set.
    self::assertSame(['About', 'Contact'], $this->getCollectionTitles(['filter' => ['status' => 1]]));

    // Filtering by title.
    self::assertSame(['About'], $this->getCollectionTitles(['filter' => ['title' => 'About']]));

    // Filtering by the internal entity ID.
    self::assertSame(['About'], $this->getCollectionTitles(['filter' => ['drupal_internal__id' => $this->aboutPage->id()]]));

    // Without any canvas_page permission, only published pages can be filtered
    // among, so filtering for the unpublished page finds nothing.
    self::assertSame([], $this->getCollectionTitles(['filter' => ['title' => 'Draft']]));
  }

  /**
   * Tests that users with a Canvas page permission can filter among all pages.
   */
  public function testFilteredCollectionsWithEditPermission(): void {
    $this->setUpCurrentUser([], ['access content', Page::EDIT_PERMISSION]);

    self::assertSame(['About', 'Contact', 'Draft'], $this->getCollectionTitles([]));
    self::assertSame(['Draft'], $this->getCollectionTitles(['filter' => ['title' => 'Draft']]));
  }

  /**
   * Requests the canvas_page collection and returns the resulting page titles.
   *
   * @param array $query
   *   The query parameters for the collection request.
   *
   * @return list<string>
   *   The sorted titles of the pages in the response document's data.
   */
  private function getCollectionTitles(array $query): array {
    $request = Request::create('/jsonapi/canvas_page/canvas_page', 'GET', $query);
    $request->headers->set('Accept', 'application/vnd.api+json');
    $response = $this->request($request);
    $content = (string) $response->getContent();
    self::assertSame(200, $response->getStatusCode(), $content);
    $document = \json_decode($content, TRUE);
    self::assertIsArray($document);
    self::assertArrayHasKey('data', $document);
    $titles = \array_map(static fn (array $item): string => $item['attributes']['title'], $document['data']);
    \sort($titles);
    return $titles;
  }

}
