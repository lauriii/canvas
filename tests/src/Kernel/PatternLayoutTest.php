<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas\Exception\ConstraintViolationException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Extension\ThemeInstallerInterface;
use Drupal\Core\Url;
use Drupal\Tests\canvas\TestSite\CanvasTestSetup;
use Drupal\Tests\canvas\Traits\AutoSaveRequestTestTrait;
use Drupal\Tests\canvas\Traits\CanvasFieldTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\ConstraintViolationInterface;

/**
 * Tests editing a stored Pattern config entity via the layout controller.
 *
 * @legacy-covers \Drupal\canvas\Controller\ApiLayoutController::get
 * @legacy-covers \Drupal\canvas\Controller\ApiLayoutController::post
 * @legacy-covers \Drupal\canvas\Controller\ApiLayoutController::patch
 */
#[Group('canvas')]
#[Group('#slow')]
#[RunTestsInSeparateProcesses]
final class PatternLayoutTest extends ApiLayoutControllerTestBase {

  use AutoSaveRequestTestTrait;
  use CanvasFieldTrait;

  private const string PATTERN_ID = 'test_pattern';
  private const string COMPONENT_UUID = '5f71027b-d9d3-4f3d-8990-a6502c0ba676';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get(ModuleInstallerInterface::class)->install(['system', 'block', 'user']);
    $this->container->get(ThemeInstallerInterface::class)->install(['stark']);
    $this->container->get(ConfigFactoryInterface::class)->getEditable('system.theme')->set('default', 'stark')->save();

    // @todo Refactor this away in https://www.drupal.org/project/canvas/issues/3531679
    (new CanvasTestSetup())->setup(TRUE);
    $this->setUpCurrentUser([], [Pattern::ADMIN_PERMISSION]);
  }

  /**
   * Creates a stored Pattern with a single heading component.
   */
  private static function createPattern(string $label = 'Original name'): Pattern {
    $pattern = Pattern::create([
      'id' => self::PATTERN_ID,
      'label' => $label,
      'status' => TRUE,
    ]);
    $pattern->setComponentTree([
      [
        'uuid' => self::COMPONENT_UUID,
        'component_id' => 'sdc.canvas_test_sdc.heading',
        'component_version' => '8c01a2bdb897a810',
        'inputs' => [
          'text' => 'Original heading',
          'element' => 'h1',
        ],
      ],
    ])->save();
    self::assertCount(0, $pattern->getTypedData()->validate());
    return $pattern;
  }

  private static function getPatternLayoutUrl(string $route_name = 'canvas.api.layout.get.pattern'): string {
    return Url::fromRoute($route_name, ['entity' => self::PATTERN_ID])->toString();
  }

  /**
   * Stores a draft of the pattern with its component tree emptied.
   */
  private function createEmptyTreeDraft(): void {
    $url = self::getPatternLayoutUrl();
    // Load current state.
    $getResponse = $this->parentRequest(Request::create($url));
    $originalContent = $getResponse->getContent();
    self::assertIsString($originalContent);

    // POST a modified tree (component removed), reusing the autoSaves handshake.
    $postArray = \json_decode($this->filterLayoutForPost($originalContent), TRUE);
    $postArray['layout'][0]['components'] = [];
    $postArray['model'] = new \stdClass();
    $this->request(Request::create($url, method: 'POST', content: \json_encode($postArray, JSON_THROW_ON_ERROR)));
  }

  /**
   * GET returns the pattern's tree in a single content region, plus its name.
   */
  public function testGet(): void {
    $pattern = self::createPattern();
    $response = $this->request(Request::create(self::getPatternLayoutUrl()));
    $data = self::decodeResponse($response);

    // The layout is a single "content" region (patterns have no global regions).
    self::assertArrayHasKey('layout', $data);
    self::assertCount(1, $data['layout']);
    self::assertSame('region', $data['layout'][0]['nodeType']);
    self::assertSame('content', $data['layout'][0]['id']);
    self::assertCount(1, $data['layout'][0]['components']);
    self::assertSame(self::COMPONENT_UUID, $data['layout'][0]['components'][0]['uuid']);

    // Patterns carry no entity form fields (the name is edited separately, via
    // the config API, not the layout flow).
    self::assertArrayNotHasKey('entity_form_fields', $data);
    // An existing pattern is not considered new.
    self::assertFalse($data['isNew']);
    self::assertNotEmpty($data['html']);
    // No draft exists yet.
    $autoSave = $this->container->get(AutoSaveManager::class);
    self::assertTrue($autoSave->getAutoSaveEntity($pattern)->isEmpty());
  }

  /**
   * POST stores an edited component tree as a draft without touching config.
   */
  public function testPostStoresDraftWithoutTouchingConfig(): void {
    $pattern = self::createPattern();
    $this->createEmptyTreeDraft();

    // A draft with the emptied tree was created...
    $autoSave = $this->container->get(AutoSaveManager::class);
    $draftData = $autoSave->getAutoSaveEntity($pattern);
    self::assertFalse($draftData->isEmpty());
    $draft = $draftData->entity;
    self::assertInstanceOf(Pattern::class, $draft);
    self::assertNull($draft->getComponentTree()->getComponentTreeItemByUuid(self::COMPONENT_UUID));

    // ...but the stored config entity still has the component until publish.
    $stored = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $stored);
    self::assertNotNull($stored->getComponentTree()->getComponentTreeItemByUuid(self::COMPONENT_UUID));
  }

  /**
   * PATCH updates a single component instance in the pattern draft.
   */
  public function testPatchComponent(): void {
    $pattern = self::createPattern();
    $url = self::getPatternLayoutUrl();
    $patchUrl = self::getPatternLayoutUrl('canvas.api.layout.patch.pattern');

    $getResponse = $this->request(Request::create($url));
    $data = self::decodeResponse($getResponse);
    self::assertArrayHasKey(self::COMPONENT_UUID, $data['model']);
    $model = $data['model'][self::COMPONENT_UUID];
    $model['resolved']['text'] = 'Updated heading';

    $patchArray = [
      'model' => $model,
      'componentType' => 'sdc.canvas_test_sdc.heading@8c01a2bdb897a810',
      'componentInstanceUuid' => self::COMPONENT_UUID,
    ] + $this->getPatchContentsDefaults([$pattern], addRegions: FALSE);

    $response = $this->request(Request::create($patchUrl, method: 'PATCH', content: \json_encode($patchArray, JSON_THROW_ON_ERROR)));
    $patched = self::decodeResponse($response);
    self::assertSame('Updated heading', $patched['model'][self::COMPONENT_UUID]['resolved']['text']);

    // The change lives in a draft; the stored config is untouched.
    $stored = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $stored);
    $storedText = $stored->getComponentTree()->getComponentTreeItemByUuid(self::COMPONENT_UUID);
    self::assertNotNull($storedText);
  }

  /**
   * Renaming a pattern with a pending draft keeps both the draft and the name.
   *
   * A rename is a config PATCH: it is published immediately, while layout edits
   * wait in the auto-save draft. The draft's snapshot contains every config
   * entity property, including the label, so it must be updated by the rename —
   * publishing an outdated snapshot would restore the pre-rename label.
   *
   * @see \Drupal\canvas\AutoSave\AutoSaveManager::onCanvasConfigEntitySave()
   * @see ui/src/features/pattern/RenamePatternDialog.tsx
   */
  public function testRenameWithPendingDraft(): void {
    $this->setUpCurrentUser([], [Pattern::ADMIN_PERMISSION, AutoSaveManager::PUBLISH_PERMISSION]);
    $pattern = self::createPattern('Original name');
    $this->createEmptyTreeDraft();
    $autoSave = $this->container->get(AutoSaveManager::class);
    self::assertSame('Original name', $autoSave->getAutoSaveEntity($pattern)->entity?->label());

    // Rename the pattern the way the rename dialog does: only the name is sent,
    // because a config entity ID is immutable.
    $renameUrl = Url::fromRoute('canvas.api.config.patch', [
      'canvas_config_entity_type_id' => Pattern::ENTITY_TYPE_ID,
      'canvas_config_entity' => self::PATTERN_ID,
    ])->toString();
    $renameResponse = $this->request(Request::create($renameUrl, method: 'PATCH', content: \json_encode(['name' => 'Renamed'], JSON_THROW_ON_ERROR)));
    self::assertSame(Response::HTTP_OK, $renameResponse->getStatusCode());

    // The new name is stored right away, and the draft survives the rename: its
    // snapshot now carries the new label too.
    $renamed = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $renamed);
    self::assertSame('Renamed', $renamed->label());
    $draftData = $autoSave->getAutoSaveEntity($pattern, TRUE);
    self::assertFalse($draftData->isEmpty());
    self::assertSame('Renamed', $draftData->entity?->label());
    self::assertSame(['Renamed'], \array_column($this->getAutoSaveStatesFromServer(), 'label'));

    // Publishing the draft applies the layout change and keeps the new name.
    self::assertSame(Response::HTTP_OK, $this->makePublishAllRequest()->getStatusCode());
    $published = Pattern::load(self::PATTERN_ID);
    self::assertInstanceOf(Pattern::class, $published);
    self::assertSame('Renamed', $published->label());
    self::assertNull($published->getComponentTree()->getComponentTreeItemByUuid(self::COMPONENT_UUID));
  }

  /**
   * A pattern's ID is immutable: the config PATCH endpoint rejects changing it.
   */
  public function testConfigPatchCannotChangeId(): void {
    self::createPattern();
    $url = Url::fromRoute('canvas.api.config.patch', [
      'canvas_config_entity_type_id' => Pattern::ENTITY_TYPE_ID,
      'canvas_config_entity' => self::PATTERN_ID,
    ])->toString();

    try {
      $this->request(Request::create($url, method: 'PATCH', content: \json_encode(['id' => 'renamed_id'], JSON_THROW_ON_ERROR)));
      $this->fail('Expected exception');
    }
    catch (ConstraintViolationException $e) {
      self::assertSame(["The 'id' property cannot be changed."], \array_map(
        static fn (ConstraintViolationInterface $violation): string => (string) $violation->getMessage(),
        \iterator_to_array($e->getConstraintViolationList()),
      ));
    }

    self::assertInstanceOf(Pattern::class, Pattern::load(self::PATTERN_ID));
    self::assertNull(Pattern::load('renamed_id'));
  }

}
