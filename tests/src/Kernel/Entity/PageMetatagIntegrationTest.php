<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Entity;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Controller\EntityFormController;
use Drupal\canvas\Entity\Page;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityDefinitionUpdateManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Extension\ThemeInstallerInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Drupal\Core\Routing\RequestContext;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\metatag\MetatagManager;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\PageTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\TestFileCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests Page Metatag Integration.
 */
#[RunTestsInSeparateProcesses]
#[RequiresMethod(MetatagManager::class, 'tagsFromEntity')]
#[Group('canvas')]
final class PageMetatagIntegrationTest extends CanvasKernelTestBase {

  use MediaTypeCreationTrait;
  use PageTrait;
  use TestFileCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...self::PAGE_TEST_MODULES,
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installPageEntitySchema();
    $this->installEntitySchema('file');
    $this->installSchema('file', 'file_usage');
    $this->installEntitySchema('media');
    $this->installEntitySchema('user');
  }

  public function testTags(): void {
    self::assertArrayNotHasKey(
      'metatags',
      $this->container->get(EntityFieldManagerInterface::class)
        ->getFieldDefinitions(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID)
    );
    $this->container->get(ModuleInstallerInterface::class)->install(['metatag']);
    self::assertArrayHasKey(
      'metatags',
      $this->container->get(EntityFieldManagerInterface::class)
        ->getFieldDefinitions(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID)
    );
    $changes = $this->container->get(EntityDefinitionUpdateManagerInterface::class)->getChangeList();
    self::assertArrayNotHasKey(Page::ENTITY_TYPE_ID, $changes);

    $media_type = $this->createMediaType('image');
    $image_file = File::create([
      // @phpstan-ignore-next-line
      'uri' => $this->getTestFiles('image')[0]->uri,
    ]);
    $image_file->save();
    $media_image = Media::create([
      'bundle' => $media_type->id(),
      'name' => 'Test image',
      'field_media_image' => [
        'target_id' => $image_file->id(),
        'alt' => 'default alt',
        'title' => 'default title',
      ],
    ]);
    $media_image->save();

    $sut = Page::create([
      'title' => 'Test page',
      'description' => 'This is a test page.',
      'path' => ['alias' => '/test-page'],
      'components' => [],
      'image' => $media_image->id(),
    ]);
    self::assertSaveWithoutViolations($sut);
    // The canonical URL comes from config, not the field: a stored value would
    // override metatag's front page defaults.
    // @see https://www.drupal.org/i/3558305
    self::assertArrayNotHasKey('canonical_url', Json::decode($sut->get('metatags')->value));

    self::assertMetatags($sut, [
      [
        [
          '#tag' => 'meta',
          '#attributes' => [
            'name' => 'title',
            'content' => 'Test page |',
          ],
        ],
        'title',
      ],
      [
        [
          '#tag' => 'meta',
          '#attributes' => [
            'name' => 'description',
            'content' => 'This is a test page.',
          ],
        ],
        'description',
      ],
      [
        [
          '#tag' => 'link',
          '#attributes' => [
            'rel' => 'canonical',
            'href' => '/test-page',
          ],
        ],
        'canonical_url',
      ],
      [
        [
          '#tag' => 'link',
          '#attributes' => [
            'rel' => 'image_src',
            'href' => $image_file->createFileUrl(FALSE),
          ],
        ],
        'image_src',
      ],
    ]);
  }

  /**
   * @see \canvas_post_update_0031_canvas_page_canonical_url_metatag_default()
   */
  public function testCanonicalUrlPostUpdate(): void {
    $this->container->get(ModuleInstallerInterface::class)->install(['metatag']);
    $unchanged = Page::create([
      'title' => 'Unchanged default',
      'path' => ['alias' => '/unchanged'],
      'metatags' => Json::encode(['title' => '[canvas_page:title]', 'canonical_url' => '[canvas_page:url]']),
    ]);
    self::assertSaveWithoutViolations($unchanged);
    $customized = Page::create([
      'title' => 'Customized',
      'metatags' => Json::encode(['canonical_url' => 'https://example.com/elsewhere']),
    ]);
    self::assertSaveWithoutViolations($customized);
    $revision_id = $unchanged->getRevisionId();
    // Simulate a site that has not got the config default yet.
    $this->container->get(EntityTypeManagerInterface::class)->getStorage('metatag_defaults')->load(Page::ENTITY_TYPE_ID)?->delete();

    $this->container->get(ModuleHandlerInterface::class)->loadInclude('canvas', 'php', 'canvas.post_update');
    $sandbox = [];
    do {
      \canvas_post_update_0031_canvas_page_canonical_url_metatag_default($sandbox);
    } while ($sandbox['#finished'] < 1);

    $storage = $this->container->get(EntityTypeManagerInterface::class)->getStorage(Page::ENTITY_TYPE_ID);
    $storage->resetCache();
    $unchanged = $storage->load((int) $unchanged->id());
    \assert($unchanged instanceof Page);
    $customized = $storage->load((int) $customized->id());
    \assert($customized instanceof Page);
    // Only the unchanged default is removed, without a new revision.
    self::assertSame(['title' => '[canvas_page:title]'], Json::decode($unchanged->get('metatags')->value));
    self::assertSame($revision_id, $unchanged->getRevisionId());
    self::assertSame('https://example.com/elsewhere', Json::decode($customized->get('metatags')->value)['canonical_url']);
    // The config default is recreated, and still yields the page's own URL.
    self::assertNotNull($this->container->get(EntityTypeManagerInterface::class)->getStorage('metatag_defaults')->load(Page::ENTITY_TYPE_ID));
    $canonical = \array_filter(metatag_get_tags_from_route($unchanged)['#attached']['html_head'], fn (array $tag) => $tag[1] === 'canonical_url');
    self::assertSame('/unchanged', \reset($canonical)[0]['#attributes']['href']);
  }

  /**
   * Tests the canonical URL of a Canvas page set as the front page.
   *
   * It comes from metatag's front page defaults, not the page's own URL.
   *
   * @see https://www.drupal.org/i/3558305
   */
  public function testFrontPageCanonicalUrl(): void {
    $this->container->get(ModuleInstallerInterface::class)->install(['metatag']);
    $page = Page::create([
      'title' => 'Home',
      'path' => ['alias' => '/home'],
    ]);
    self::assertSaveWithoutViolations($page);
    $this->config('system.site')->set('page.front', '/page/' . $page->id())->save();
    // The path matcher keeps the front page it looked up while the page was
    // saved, so start a new container as a new request would.
    $this->container->get('kernel')->rebuildContainer();

    // Route a request for "/" the way PathSubscriber and RouterListener do.
    $request = Request::create('/');
    $request->setSession($this->container->get(RequestStack::class)->getSession());
    $path = $this->container->get(InboundPathProcessorInterface::class)->processInbound('/', $request);
    $this->container->get(CurrentPathStack::class)->setPath($path, $request);
    $request->attributes->add($this->container->get('router.no_access_checks')->matchRequest($request));
    $this->container->get(RequestStack::class)->push($request);

    $canonical = \array_filter(metatag_get_tags_from_route($page)['#attached']['html_head'], fn (array $tag) => $tag[1] === 'canonical_url');
    // The site's base URL, including the base path of a site in a subdirectory.
    self::assertSame($this->container->get(RequestContext::class)->getCompleteBaseUrl() . '/', \reset($canonical)[0]['#attributes']['href']);
  }

  private static function assertMetatags(Page $page, array $expected): void {
    $metatags = metatag_get_tags_from_route($page);
    self::assertEquals($expected, $metatags['#attached']['html_head']);
  }

  public function testSeoSettingsForm(): void {
    \Drupal::service(ThemeInstallerInterface::class)->install(['canvas_stark']);
    $this->container->get(ModuleInstallerInterface::class)->install(['metatag']);
    $page = Page::create([
      'title' => 'Test page',
      'description' => 'This is a test page.',
      'path' => ['alias' => '/test-page'],
      'components' => [],
    ]);
    self::assertSaveWithoutViolations($page);
    $themeHandler = $this->container->get(ThemeHandlerInterface::class);
    \assert($themeHandler instanceof ThemeHandlerInterface);
    $sut = new EntityFormController($this->container->get(AutoSaveManager::class), $this->container->get(RequestStack::class), $themeHandler);
    $form = $sut->form(Page::ENTITY_TYPE_ID, $page, 'default');
    self::assertArrayHasKey('image', $form['seo_settings']);
    self::assertArrayHasKey('description', $form['seo_settings']);
    self::assertEquals('seo_settings', $form['metatags']['widget'][0]['basic']['title']['#group']);
  }

}
