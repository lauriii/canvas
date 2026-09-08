<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Controller;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\ClientDataToEntityConverter;
use Drupal\canvas\Controller\ApiContentControllers;
use Drupal\canvas\Controller\ApiLayoutController;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant;
use Drupal\canvas\Render\PreviewEnvelope;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\canvas\Traits\OpenApiSpecTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use League\OpenAPIValidation\PSR7\Exception\Validation\InvalidBody;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tests ApiContentControllers::patchLangcode().
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[CoversClass(ApiContentControllers::class)]
#[CoversMethod(ApiContentControllers::class, 'patchLangcode')]
class ApiContentControllersPatchLangcodeTest extends CanvasKernelTestBase {

  use UserCreationTrait;
  use RequestTrait;
  use OpenApiSpecTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_test_page',
    'field',
    'language',
    'content_translation',
  ];

  private const string URL = '/canvas/api/v0/content/canvas_page/%s/langcode';

  /**
   * The default draft page used by most tests.
   *
   * Individual tests may mutate and re-save this page for their own scenario.
   */
  private Page $page;

  private const string DEFAULT_LANGCODE = 'en';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('canvas_page');
    $this->installEntitySchema('path_alias');
    $this->installConfig(['system', 'field', 'filter', 'path_alias', 'language']);
    $this->config('system.theme')->set('default', 'stark')->save();

    ConfigurableLanguage::createFromLangcode('de')->save();
    ConfigurableLanguage::createFromLangcode('fr')->save();

    $this->setUpCurrentUser([], ['access content', Page::CREATE_PERMISSION, Page::EDIT_PERMISSION]);

    // The default title is what marks a stored page as a never-published draft.
    // @see \Drupal\canvas\AutoSave\AutoSaveManager::entityIsConsideredNew()
    $entity_type = $this->container->get(EntityTypeManagerInterface::class)->getDefinition(Page::ENTITY_TYPE_ID);
    $this->page = Page::create([
      'title' => (string) ApiContentControllers::defaultTitle($entity_type),
      'status' => FALSE,
      'components' => [],
    ]);
    $this->page->save();
    self::assertSame($this->page->language()->getId(), self::DEFAULT_LANGCODE);
  }

  /**
   * Sends a PATCH langcode request and asserts the response status code.
   *
   * Error responses must contain a top-level `errors` member whose entries
   * comply with the `Error` schema in openapi.yml.
   *
   * @param array $body
   *   The request body to JSON-encode.
   * @param int $expected_status
   *   The expected HTTP response status code.
   * @param string|null $expected_error_detail
   *   For error responses: the expected `detail` of the single error object.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response, for further assertions by the caller.
   */
  private function patchLangcode(array $body, int $expected_status, ?string $expected_error_detail = NULL): Response {
    $response = $this->request(Request::create(
      \sprintf(self::URL, $this->page->id()),
      'PATCH',
      server: ['CONTENT_TYPE' => 'application/json'],
      content: \json_encode($body, JSON_THROW_ON_ERROR),
    ));
    self::assertSame($expected_status, $response->getStatusCode());
    if ($expected_status >= Response::HTTP_BAD_REQUEST) {
      $data = self::decodeResponse($response);
      self::assertSame(['errors'], \array_keys($data));
      self::assertCount(1, $data['errors']);
      $this->assertDataCompliesWithApiSpecification($data['errors'][0], 'Error');
      self::assertSame($expected_error_detail, $data['errors'][0]['detail']);
    }
    return $response;
  }

  /**
   * Asserts the page's stored langcode is still the default.
   */
  private function assertLangcodeUnchanged(): void {
    self::assertSame(
      self::DEFAULT_LANGCODE,
      Page::load($this->page->id())?->language()->getId(),
      'Langcode unchanged.',
    );
  }

  /**
   * Tests that auto-saved title is preserved after switching language.
   *
   * Scenario: user creates a draft, changes the title (auto-saved), then
   * switches language. The layout GET after the switch must return the changed
   * title, not the stored "Untitled page" default.
   */
  public function testTitlePreservedAfterLanguageSwitch(): void {
    // Simulate the initial layout GET to obtain the client-side data shape.
    $get_request = Request::create('/canvas/api/v0/layout/canvas_page/' . $this->page->id());
    $envelope = \Drupal::classResolver(ApiLayoutController::class)
      ->get(request: $get_request, entity: $this->page);
    self::assertInstanceOf(PreviewEnvelope::class, $envelope);

    // Simulate the user changing the title and the debounced auto-save POST
    // completing. This is exactly what ApiLayoutController::post() does.
    $client_data = \array_intersect_key(
      $envelope->additionalData,
      \array_flip(['layout', 'model', 'entity_form_fields']),
    );
    $client_data['entity_form_fields']['title[0][value]'] = 'My Changed Title';
    $client_data['model'] = (array) $client_data['model'];

    $content_region = NULL;
    foreach ($client_data['layout'] as $region) {
      if ($region['id'] === CanvasPageVariant::MAIN_CONTENT_REGION) {
        $content_region = $region;
        break;
      }
    }
    self::assertNotNull($content_region);
    \Drupal::service(ClientDataToEntityConverter::class)->convert(
      ['layout' => $content_region] + $client_data,
      $this->page,
      validate: FALSE,
    );
    $autoSave = $this->container->get(AutoSaveManager::class);
    self::assertInstanceOf(AutoSaveManager::class, $autoSave);
    $autoSave->saveEntity($this->page);
    self::assertFalse(
      $autoSave->getAutoSaveEntity($this->page)->isEmpty(),
      'Auto-save with changed title exists before language switch.',
    );

    // Switch the entity langcode to German via the PATCH endpoint.
    $this->patchLangcode(['langcode' => 'de'], Response::HTTP_OK);

    // Reload so the entity reflects the stored langcode=de.
    $page = Page::load($this->page->id());
    self::assertInstanceOf(Page::class, $page);
    self::assertSame('de', $page->language()->getId());

    // Simulate the layout GET that the frontend fires after the switch.
    $get_after = Request::create('/canvas/api/v0/layout/canvas_page/' . $this->page->id());
    $envelope_after = \Drupal::classResolver(ApiLayoutController::class)
      ->get(request: $get_after, entity: $this->page);
    self::assertInstanceOf(PreviewEnvelope::class, $envelope_after);

    self::assertSame(
      'My Changed Title',
      $envelope_after->additionalData['entity_form_fields']['title[0][value]'] ?? NULL,
      'Auto-saved title is returned in entity_form_fields after language switch.',
    );
  }

  /**
   * Tests switching the langcode of a new (never-published) draft succeeds.
   */
  public function testPatchLangcodeOnNewDraft(): void {
    $response = $this->patchLangcode(['langcode' => 'de'], Response::HTTP_OK);
    $data = self::decodeResponse($response);
    self::assertSame('de', $data['langcode']);

    $reloaded = Page::load($this->page->id());
    self::assertInstanceOf(Page::class, $reloaded);
    self::assertSame('de', $reloaded->language()->getId(), 'Entity langcode persisted to storage.');
  }

  /**
   * Tests that an auto-save item is migrated when the langcode changes.
   */
  public function testPatchLangcodeMigratesAutoSave(): void {
    $autoSave = $this->container->get(AutoSaveManager::class);
    self::assertInstanceOf(AutoSaveManager::class, $autoSave);

    // Simulate content typed before switch.
    $this->page->set('title', 'My draft title');
    $autoSave->saveEntity($this->page);
    $old_auto_save_key = AutoSaveManager::getAutoSaveKey($this->page);
    self::assertStringEndsWith(':en', $old_auto_save_key, 'Auto-save key using en language id.');

    self::assertFalse($autoSave->getAutoSaveEntity($this->page)->isEmpty(), 'Auto-save created under en key.');

    $this->patchLangcode(['langcode' => 'de'], Response::HTTP_OK);

    // Old key must be gone.
    $store = $this->container->get('keyvalue')->get(AutoSaveManager::AUTO_SAVE_STORE);
    self::assertNull($store->get($old_auto_save_key), 'Old auto-save key deleted.');

    // New key must exist.
    $reloaded = Page::load($this->page->id());
    self::assertInstanceOf(Page::class, $reloaded);
    self::assertFalse($autoSave->getAutoSaveEntity($reloaded)->isEmpty(), 'Auto-save found under de key.');
    $new_auto_save_key = AutoSaveManager::getAutoSaveKey($reloaded);
    self::assertNotEquals($new_auto_save_key, $old_auto_save_key);
    self::assertStringEndsWith(':de', $new_auto_save_key, 'Auto-save key using de language id.');

    // Serialized langcode field inside the item must reflect the new language.
    $migrated = $store->get($new_auto_save_key);
    self::assertSame('de', $migrated['langcode'] ?? NULL);
    self::assertSame('de', $migrated['data']['langcode'][0]['value'] ?? NULL);

    // The stored entity changed (its langcode), so the migrated auto-save item's
    // recorded stored-entity hash must have been advanced: otherwise the auto-save item
    // is reported as a conflict.
    $entries = $autoSave->getAllAutoSaveList(with_entities: FALSE, with_conflicts: TRUE);
    self::assertArrayHasKey($new_auto_save_key, $entries);
    self::assertArrayNotHasKey(AutoSaveManager::AUTO_SAVE_CONFLICT_KEY, $entries[$new_auto_save_key], 'Migrated auto-save item is not a conflict.');

    // Editing continues under the new langcode, still without a conflict.
    $reloaded->set('title', 'My draft title, edited');
    $autoSave->saveEntity($reloaded);
    $entries_after_edit = $autoSave->getAllAutoSaveList(with_entities: FALSE, with_conflicts: TRUE);
    self::assertArrayNotHasKey(AutoSaveManager::AUTO_SAVE_CONFLICT_KEY, $entries_after_edit[$new_auto_save_key]);
    self::assertSame('My draft title, edited', $autoSave->getAutoSaveEntity($reloaded)->entity?->label());
  }

  /**
   * Tests that a draft with stored translations cannot change its langcode.
   */
  public function testPatchLangcodeBlockedWithTranslations(): void {
    // The translation must be unpublished too, otherwise the page counts as
    // published and the translation check is never reached.
    $this->page->addTranslation('de', ['title' => $this->page->label() . ' (de)', 'status' => FALSE]);
    $this->page->save();

    foreach (['de', 'fr'] as $langcode) {
      $this->patchLangcode(['langcode' => $langcode], Response::HTTP_UNPROCESSABLE_ENTITY, 'Language cannot be changed on a page that already has translations.');
      $this->assertLangcodeUnchanged();
    }
  }

  /**
   * Tests that a path alias auto-saved before the switch follows the langcode.
   */
  public function testPatchLangcodeMigratesPathAliasLangcode(): void {
    $this->page->set('path', ['alias' => '/my-draft']);
    $this->page->save();
    $alias_storage = $this->container->get(EntityTypeManagerInterface::class)->getStorage('path_alias');
    self::assertCount(1, $alias_storage->loadByProperties(['path' => '/page/' . $this->page->id()]));

    $autoSave = $this->container->get(AutoSaveManager::class);
    self::assertInstanceOf(AutoSaveManager::class, $autoSave);
    $this->page->set('title', 'My draft title');
    $this->page->get('path')->first()?->set('alias', '/my-draft-edited');
    $autoSave->saveEntity($this->page);

    $this->patchLangcode(['langcode' => 'de'], Response::HTTP_OK);

    $reloaded = Page::load($this->page->id());
    self::assertInstanceOf(Page::class, $reloaded);
    $auto_save_entity = $autoSave->getAutoSaveEntity($reloaded)->entity;
    self::assertInstanceOf(Page::class, $auto_save_entity);
    $path_item = $auto_save_entity->get('path')->first()?->getValue();
    self::assertSame('/my-draft-edited', $path_item['alias'] ?? NULL);
    self::assertNotSame('en', $path_item['langcode'] ?? NULL, 'Auto-saved path alias item does not keep the old langcode.');

    // Saving the auto-saved entity (what publishing does) must store the
    // alias in the new language.
    $auto_save_entity->save();
    $aliases = $alias_storage->loadByProperties(['path' => '/page/' . $this->page->id()]);
    self::assertCount(1, $aliases);
    $alias = \reset($aliases);
    self::assertSame('/my-draft-edited', $alias->getAlias());
    self::assertSame('de', $alias->language()->getId(), 'Stored path alias is in the new language.');
  }

  /**
   * Tests that switching to the same langcode is a no-op (returns 200).
   */
  public function testPatchLangcodeSameLangcodeIsNoOp(): void {
    $response = $this->patchLangcode(['langcode' => 'en'], Response::HTTP_OK);
    $data = self::decodeResponse($response);
    self::assertSame('en', $data['langcode']);
  }

  /**
   * Tests switching langcode with no auto-save item is a no-op migration.
   *
   * When the auto-save store is empty, migrateLangcode() must not create a
   * stale key or throw an error. The langcode change must still be persisted.
   */
  public function testPatchLangcodeWithNoAutoSaveIsClean(): void {
    $autoSave = $this->container->get(AutoSaveManager::class);
    self::assertInstanceOf(AutoSaveManager::class, $autoSave);

    self::assertTrue($autoSave->getAutoSaveEntity($this->page)->isEmpty(), 'No auto-save item before switch.');

    $this->patchLangcode(['langcode' => 'de'], Response::HTTP_OK);

    $reloaded = Page::load($this->page->id());
    self::assertInstanceOf(Page::class, $reloaded);
    self::assertSame('de', $reloaded->language()->getId(), 'Entity langcode persisted to storage.');

    $store = $this->container->get('keyvalue')->get(AutoSaveManager::AUTO_SAVE_STORE);
    self::assertNull($store->get(AutoSaveManager::getAutoSaveKey($reloaded)), 'No auto-save item created for the new langcode key.');
  }

  /**
   * Tests that patchLangcode() is blocked on a published page.
   *
   * A published page has a real title, so it is no longer considered new.
   *
   * @see \Drupal\canvas\AutoSave\AutoSaveManager::entityIsConsideredNew()
   */
  public function testPatchLangcodeBlockedOnPublishedPage(): void {
    $this->page->set('title', 'Live page');
    $this->page->set('status', TRUE);
    $this->page->save();

    $this->patchLangcode(['langcode' => 'de'], Response::HTTP_UNPROCESSABLE_ENTITY, 'Language can only be changed on a draft page that has never been published.');
    $this->assertLangcodeUnchanged();
  }

  /**
   * Tests that patchLangcode() is blocked on a page that was previously published.
   *
   * Unpublishing a page does not make it a draft again.
   */
  public function testPatchLangcodeBlockedOnPreviouslyPublishedPage(): void {
    $this->page->set('title', 'Was live');
    $this->page->set('status', TRUE);
    $this->page->save();
    $this->page->setUnpublished();
    $this->page->save();

    $this->patchLangcode(['langcode' => 'de'], Response::HTTP_UNPROCESSABLE_ENTITY, 'Language can only be changed on a draft page that has never been published.');
    $this->assertLangcodeUnchanged();
  }

  /**
   * Tests that an unknown langcode returns 400.
   */
  public function testPatchLangcodeUnknownLanguageReturnsBadRequest(): void {
    $this->patchLangcode(['langcode' => 'xx-not-a-language'], Response::HTTP_BAD_REQUEST, 'Unknown language: xx-not-a-language.');
    $this->assertLangcodeUnchanged();
  }

  /**
   * Tests that a missing langcode body field is rejected.
   *
   * The OpenAPI request validator (active in tests) rejects the body before
   * the controller runs; the controller's own guard returns 400 otherwise.
   */
  public function testPatchLangcodeMissingBodyReturnsBadRequest(): void {
    $this->expectException(InvalidBody::class);
    $this->request(Request::create(
      \sprintf(self::URL, $this->page->id()),
      'PATCH',
      server: ['CONTENT_TYPE' => 'application/json'],
      content: \json_encode([], JSON_THROW_ON_ERROR),
    ));
  }

  /**
   * Tests that a user without update access is denied at the routing layer.
   *
   * The route requirement _entity_access:'canvas_page.update' fires before the
   * controller, so a user without edit permission receives 403 from Drupal's
   * access layer, not the controller's 404 guard.
   */
  public function testPatchLangcodeAccessDeniedByRoute(): void {
    // Switch to a user with no edit permission.
    $this->setUpCurrentUser([], ['access content']);

    $this->expectException(AccessDeniedHttpException::class);
    $this->request(Request::create(
      \sprintf(self::URL, $this->page->id()),
      'PATCH',
      server: ['CONTENT_TYPE' => 'application/json'],
      content: \json_encode(['langcode' => 'de'], JSON_THROW_ON_ERROR),
    ));
  }

}
