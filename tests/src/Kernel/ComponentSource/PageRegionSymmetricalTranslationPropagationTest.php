<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\ComponentSource;

// cspell:ignore Hola opcional

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\EntityHandlers\StagedLanguageConfigOverrideStorage;
use Drupal\Core\Url;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests component instance update propagation to PageRegion translations.
 */
#[CoversClass(ComponentSourceManager::class)]
#[CoversClass(StagedLanguageConfigOverrideStorage::class)]
#[Group('canvas')]
#[Group('canvas_component_sources')]
#[Group('canvas_data_model')]
#[Group('canvas_translation')]
#[Group('slow')]
final class PageRegionSymmetricalTranslationPropagationTest extends ConfigEntitySymmetricalTranslationPropagationTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    \Drupal::service('theme_installer')->install(['stark']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    // Make stark the active theme so the PageRegion is loaded as a global region
    // when a Page is previewed.
    $this->config('system.theme')->set('default', 'stark')->save();
    \Drupal::service('theme.manager')->resetActiveTheme();

    $this->entity = PageRegion::create([
      'theme' => 'stark',
      'region' => 'sidebar_first',
      'component_tree' => self::populateActiveComponentVersionPlaceholders($this->translatableComponentTree),
    ]);
    self::assertEntityIsValid($this->entity);
    self::assertSame(SAVED_NEW, $this->entity->save());
  }

  /**
   * {@inheritdoc}
   *
   * A PageRegion is not previewed directly: it is reconciled as a global region
   * while a Page is previewed through the layout GET endpoint.
   */
  protected function previewThroughLayoutController(string $preview_langcode): void {
    \assert($this->entity instanceof PageRegion);
    $page = Page::create(['title' => 'Preview host', 'components' => []]);
    self::assertSame(SAVED_NEW, $page->save());
    \Drupal::entityTypeManager()->getStorage(Component::ENTITY_TYPE_ID)->resetCache();
    $path = Url::fromRoute('canvas.api.layout.get', [
      'entity_type' => Page::ENTITY_TYPE_ID,
      'entity' => $page->id(),
    ])->toString();
    $prefix = $preview_langcode === 'en' ? '' : "/$preview_langcode";
    $response = $this->request(Request::create($prefix . $path));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
  }

  /**
   * {@inheritdoc}
   */
  protected function additionalPreviewPermissions(): array {
    // The PageRegion is reconciled while previewing a Page; the layout GET route
    // requires edit access to that Page.
    return [Page::EDIT_PERMISSION];
  }

}
