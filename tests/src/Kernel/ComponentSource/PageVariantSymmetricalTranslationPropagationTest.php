<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\ComponentSource;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\EntityHandlers\StagedLanguageConfigOverrideStorage;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Marker;
use Drupal\Core\Url;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests component instance update propagation to PageVariant translations.
 *
 * Page variants replaced editable global page regions: previewing a content
 * entity no longer reconciles any PageRegion, so the symmetrical translation
 * propagation flows are covered against a page variant, which is previewed
 * (and edited) directly through the layout endpoints.
 */
#[CoversClass(ComponentSourceManager::class)]
#[CoversClass(StagedLanguageConfigOverrideStorage::class)]
#[Group('canvas')]
#[Group('canvas_component_sources')]
#[Group('canvas_data_model')]
#[Group('canvas_translation')]
#[Group('slow')]
final class PageVariantSymmetricalTranslationPropagationTest extends ConfigEntitySymmetricalTranslationPropagationTestBase {

  /**
   * The UUID of the "Page content" marker instance in the fixture tree.
   */
  private const string MARKER_INSTANCE_UUID = '22222222-2222-4222-8222-222222222222';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    // PageVariant::postDelete() queries canvas_page entities that reference a
    // deleted variant.
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);

    // A page variant tree must contain exactly one "Page content" marker. The
    // marker component ships in the module's default config, which the base
    // class installs.
    // @see \Drupal\canvas\Plugin\Validation\Constraint\PageVariantHasContentMarkerConstraint
    $marker = Component::load(Marker::PAGE_CONTENT_COMPONENT_ID);
    self::assertInstanceOf(Component::class, $marker);

    $this->entity = PageVariant::create([
      'id' => 'test_variant',
      'label' => 'Test variant',
      'component_tree' => [
        [
          'uuid' => self::MARKER_INSTANCE_UUID,
          'component_id' => Marker::PAGE_CONTENT_COMPONENT_ID,
          'component_version' => $marker->getActiveVersion(),
          'inputs' => [],
        ],
        ...self::populateActiveComponentVersionPlaceholders($this->translatableComponentTree),
      ],
    ]);
    self::assertEntityIsValid($this->entity);
    self::assertSame(SAVED_NEW, $this->entity->save());
  }

  /**
   * {@inheritdoc}
   *
   * A page variant is previewed directly through the generic layout GET
   * endpoint; unlike a ContentTemplate, it needs no preview entity.
   */
  protected function previewThroughLayoutController(string $preview_langcode): void {
    \assert($this->entity instanceof PageVariant);
    \Drupal::entityTypeManager()->getStorage(Component::ENTITY_TYPE_ID)->resetCache();
    $path = Url::fromRoute('canvas.api.layout.get', [
      'entity_type' => PageVariant::ENTITY_TYPE_ID,
      'entity' => $this->entity->id(),
    ])->toString();
    $prefix = $preview_langcode === 'en' ? '' : "/$preview_langcode";
    $response = $this->request(Request::create($prefix . $path));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
  }

  /**
   * {@inheritdoc}
   */
  protected function additionalPreviewPermissions(): array {
    // The layout GET route only requires edit access to the page variant
    // itself, which its admin permission (already granted) provides. But the
    // auto-saves endpoints used after previewing sit behind the Canvas UI
    // access check, which the page variant admin permission does not satisfy
    // on its own: the user must also be able to edit a content entity type
    // with a Canvas field.
    // @see \Drupal\canvas\Access\CanvasUiAccessCheck
    return [Page::EDIT_PERMISSION];
  }

}
