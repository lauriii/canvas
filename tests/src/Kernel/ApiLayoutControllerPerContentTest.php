<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\Access\ComponentTreeEditAccessCheck;
use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\CanvasUriDefinitions;
use Drupal\canvas\Controller\ApiContentControllers;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\canvas\Plugin\Menu\ContentTemplateLayoutTask;
use Drupal\canvas\PropSource\PropSource;
use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\canvas\TestSite\CanvasTestSetup;
use Drupal\Tests\canvas\Traits\CanvasFieldCreationTrait;
use Drupal\Tests\canvas\Traits\CanvasFieldTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Route;

/**
 * Tests the Layout API in per-content mode (exposed slots, Phase 5).
 *
 * Covers the merged GET (editability annotations, exposed slot metadata and
 * per-slot override state), the guarded + partitioned write (PATCH/POST), and
 * the merged per-content preview.
 *
 * @legacy-covers \Drupal\canvas\Controller\ApiLayoutController::get
 * @legacy-covers \Drupal\canvas\Controller\ApiLayoutController::patch
 * @legacy-covers \Drupal\canvas\Controller\ApiLayoutController::post
 * @legacy-covers \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList::partitionSlotFields
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('#slow')]
final class ApiLayoutControllerPerContentTest extends ApiLayoutControllerTestBase {

  use CanvasFieldCreationTrait;
  use CanvasFieldTrait;

  private const string BUNDLE = 'templated';
  // The exposed slot is backed by its own component_tree field; the field
  // machine name is the slot's key everywhere on the wire.
  private const string SLOT_FIELD = 'canvas_slot_main';
  private const string HOST_UUID = '11111111-1111-4111-8111-111111111111';

  /**
   * The active version of the exposed-slot host component (props-slots).
   */
  private string $hostVersion;

  /**
   * The active version of the entity content component (props-no-slots).
   */
  private string $contentVersion;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('module_installer')->install(['system', 'block', 'user']);
    $this->container->get('theme_installer')->install(['stark']);
    $this->container->get('config.factory')->getEditable('system.theme')->set('default', 'stark')->save();

    // @todo Refactor this away in https://www.drupal.org/project/canvas/issues/3531679
    (new CanvasTestSetup())->setup(TRUE);

    $host_component = Component::load('sdc.canvas_test_sdc.props-slots');
    self::assertInstanceOf(Component::class, $host_component);
    $this->hostVersion = $host_component->getActiveVersion();
    $content_component = Component::load('sdc.canvas_test_sdc.props-no-slots');
    self::assertInstanceOf(Component::class, $content_component);
    $this->contentVersion = $content_component->getActiveVersion();

    // A dedicated bundle so per-content mode does not affect the shared article
    // entities used by the other Layout API tests.
    NodeType::create(['type' => self::BUNDLE, 'name' => 'Templated'])->save();
    // The exposed slot's backing component_tree field on the bundle.
    $this->createComponentTreeField('node', self::BUNDLE, self::SLOT_FIELD);

    ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => self::BUNDLE,
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [
        [
          'uuid' => self::HOST_UUID,
          'component_id' => 'sdc.canvas_test_sdc.props-slots',
          'component_version' => $this->hostVersion,
          'inputs' => [
            'heading' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:' . self::BUNDLE . '␝title␞␟value',
            ],
          ],
        ],
      ],
      // Keyed by the backing field machine name.
      'exposed_slots' => [
        self::SLOT_FIELD => [
          'component_uuid' => self::HOST_UUID,
          'slot_name' => 'the_body',
          'label' => 'Main content',
        ],
      ],
    ])->setStatus(TRUE)->save();

    $this->setUpCurrentUser([], ['edit any ' . self::BUNDLE . ' content', 'access content']);
  }

  /**
   * Creates a templated node, optionally with per-entity slot content.
   *
   * @param array<int, array<string, mixed>> $slot_content
   *   Raw component tree rows for the exposed slot's backing field (an ordinary
   *   tree: roots have an empty parent_uuid and empty slot).
   */
  private static function createTemplatedNode(array $slot_content = []): NodeInterface {
    $node = Node::create([
      'type' => self::BUNDLE,
      'title' => 'A templated node',
      self::SLOT_FIELD => $slot_content,
    ]);
    $node->save();
    return $node;
  }

  /**
   * Returns an override row: a static-heading component, an ordinary slot root.
   */
  private function overrideRow(string $uuid, string $heading): array {
    return [
      'uuid' => $uuid,
      'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
      'component_version' => $this->contentVersion,
      'inputs' => [
        'heading' => [
          'sourceType' => 'static:field_item:string',
          'value' => $heading,
          'expression' => 'ℹ︎string␟value',
        ],
      ],
    ];
  }

  /**
   * The merged GET annotates editability, exposed slots and override state.
   */
  public function testMergedGetShapeAndPreview(): void {
    $override_uuid = '22222222-2222-4222-8222-222222222222';
    $node = self::createTemplatedNode([$this->overrideRow($override_uuid, "Now we're cooking!")]);

    $response = $this->request(Request::create($this->getLayoutUrl($node)->toString()));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $json = self::decodeResponse($response);

    // Exposed slot metadata (top-level).
    self::assertSame([
      self::SLOT_FIELD => [
        'label' => 'Main content',
        'slotName' => 'the_body',
        'componentUuid' => self::HOST_UUID,
      ],
    ], $json['exposedSlots']);

    // Per-slot override state (top-level): the entity overrides the slot with
    // real content, so it is overridden but not empty.
    self::assertSame([
      self::SLOT_FIELD => ['overridden' => TRUE, 'empty' => FALSE],
    ], $json['slotOverrides']);

    // The content region: the template host is locked (editable: false); the
    // entity content nested in the exposed slot is editable (editable: true).
    $content_region = $json['layout'][0];
    self::assertSame('content', $content_region['id']);
    $host_node = $content_region['components'][0];
    self::assertSame(self::HOST_UUID, $host_node['uuid']);
    self::assertFalse($host_node['editable']);
    $the_body = self::findSlot($host_node, 'the_body');
    self::assertCount(1, $the_body['components']);
    self::assertSame($override_uuid, $the_body['components'][0]['uuid']);
    self::assertTrue($the_body['components'][0]['editable']);

    // The merged preview renders both the template chrome (node title) and the
    // per-entity override content.
    self::assertStringContainsString('A templated node', (string) $json['html']);
    self::assertStringContainsString("Now we&#039;re cooking!", (string) $json['html']);
  }

  /**
   * A node without slot content inherits the default: not overridden.
   */
  public function testMergedGetInheritedSlot(): void {
    $node = self::createTemplatedNode();

    $json = self::decodeResponse($this->request(Request::create($this->getLayoutUrl($node)->toString())));
    self::assertSame([
      self::SLOT_FIELD => ['overridden' => FALSE, 'empty' => FALSE],
    ], $json['slotOverrides']);

    // The exposed slot is present (empty, no default here) and the host is still
    // annotated as locked chrome.
    $host_node = $json['layout'][0]['components'][0];
    self::assertFalse($host_node['editable']);
    self::assertSame([], self::findSlot($host_node, 'the_body')['components']);
  }

  /**
   * PATCH rejects template-owned targets and writes entity-owned components.
   */
  public function testPatchGuardAndWriteThrough(): void {
    $override_uuid = '22222222-2222-4222-8222-222222222222';
    $node = self::createTemplatedNode([$this->overrideRow($override_uuid, 'Original content')]);
    $url = $this->getLayoutUrl($node)->toString();

    // PATCH a template-owned (locked) component: explicit access denial, not the
    // incidental 404 from the entity-field lookup.
    $reject_body = [
      'componentInstanceUuid' => self::HOST_UUID,
      'componentType' => 'sdc.canvas_test_sdc.props-slots@' . $this->hostVersion,
      'model' => ['source' => [], 'resolved' => []],
    ] + $this->getPatchContentsDefaults([$node]);
    try {
      $this->request(Request::create($url, method: 'PATCH', content: \json_encode($reject_body, JSON_THROW_ON_ERROR)));
      self::fail('Expected AccessDeniedHttpException for a template-owned target.');
    }
    catch (AccessDeniedHttpException $e) {
      self::assertStringContainsString('belongs to the template', $e->getMessage());
    }

    // PATCH the entity-owned override component: writes through to the slot
    // field's auto-save.
    $patch_body = [
      'componentInstanceUuid' => $override_uuid,
      'componentType' => 'sdc.canvas_test_sdc.props-no-slots@' . $this->contentVersion,
      'model' => [
        'source' => [
          'heading' => [
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
          ],
        ],
        'resolved' => ['heading' => 'Patched content'],
      ],
    ] + $this->getPatchContentsDefaults([$node]);
    $response = $this->request(Request::create($url, method: 'PATCH', content: \json_encode($patch_body, JSON_THROW_ON_ERROR)));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());

    // The auto-saved node's slot field holds only the (updated) override row.
    $draft = $this->getAutoSaveDraft($node);
    $field = $draft->get(self::SLOT_FIELD);
    self::assertInstanceOf(ComponentTreeItemList::class, $field);
    self::assertCount(1, $field);
    $item = $field->get(0);
    self::assertInstanceOf(ComponentTreeItem::class, $item);
    self::assertSame($override_uuid, $item->getUuid());
    self::assertNull($item->getParentUuid());
    self::assertNull($item->getSlot());
    self::assertStringContainsString('Patched content', (string) \json_encode($item->getInputs(), JSON_THROW_ON_ERROR));
  }

  /**
   * POST partitions the merged tree into per-slot fields.
   */
  public function testPostPartitionsIntoSlotFields(): void {
    $node = self::createTemplatedNode();
    $url = $this->getLayoutUrl($node)->toString();

    // The inherited slot has no override yet.
    $get = self::decodeResponse($this->request(Request::create($url)));
    self::assertFalse($get['slotOverrides'][self::SLOT_FIELD]['overridden']);

    // Add an entity-owned component into the exposed slot and POST the merged
    // tree back.
    $new_uuid = '33333333-3333-4333-8333-333333333333';
    $post = $this->postBodyFrom($get);
    $post['model'][$new_uuid] = [
      'resolved' => ['heading' => 'Filled by the editor'],
      'source' => [
        'heading' => [
          'sourceType' => 'static:field_item:string',
          'expression' => 'ℹ︎string␟value',
        ],
      ],
    ];
    self::addComponentToSlot($post['layout'][0]['components'][0], 'the_body', [
      'nodeType' => 'component',
      'uuid' => $new_uuid,
      'type' => 'sdc.canvas_test_sdc.props-no-slots@' . $this->contentVersion,
      'slots' => [],
    ]);
    $response = $this->request(Request::create($url, method: 'POST', content: \json_encode($post, JSON_THROW_ON_ERROR)));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());

    // Round-trip: the auto-saved slot field contains only the entity content,
    // as an ordinary root (empty parent_uuid, empty slot); the host is absent.
    $field = $this->getAutoSaveDraft($node)->get(self::SLOT_FIELD);
    self::assertInstanceOf(ComponentTreeItemList::class, $field);
    self::assertCount(1, $field);
    $item = $field->get(0);
    self::assertInstanceOf(ComponentTreeItem::class, $item);
    self::assertSame($new_uuid, $item->getUuid());
    self::assertNull($item->getParentUuid());
    self::assertNull($item->getSlot());
    self::assertSame('sdc.canvas_test_sdc.props-no-slots', $item->getComponentId());
    // No template-owned row leaked into the entity field.
    self::assertNull($field->getComponentTreeItemByUuid(self::HOST_UUID));

    // A follow-up GET now reports the slot as overridden.
    $get2 = self::decodeResponse($this->request(Request::create($url)));
    self::assertTrue($get2['slotOverrides'][self::SLOT_FIELD]['overridden']);
    self::assertFalse($get2['slotOverrides'][self::SLOT_FIELD]['empty']);
  }

  /**
   * POSTing only the empty-slot marker records an empty override.
   */
  public function testEmptyOverrideWritesMarkerRow(): void {
    $node = self::createTemplatedNode();
    $url = $this->getLayoutUrl($node)->toString();

    $marker = Component::load(ComponentInterface::EMPTY_SLOT_MARKER_ID);
    self::assertInstanceOf(ComponentInterface::class, $marker);
    $marker_uuid = '44444444-4444-4444-8444-444444444444';

    $post = $this->postBodyFrom(self::decodeResponse($this->request(Request::create($url))));
    self::addComponentToSlot($post['layout'][0]['components'][0], 'the_body', [
      'nodeType' => 'component',
      'uuid' => $marker_uuid,
      'type' => ComponentInterface::EMPTY_SLOT_MARKER_ID . '@' . $marker->getActiveVersion(),
      'slots' => [],
    ]);
    $this->request(Request::create($url, method: 'POST', content: \json_encode($post, JSON_THROW_ON_ERROR)));

    // The slot field stores exactly the marker as its sole root.
    $field = $this->getAutoSaveDraft($node)->get(self::SLOT_FIELD);
    self::assertCount(1, $field);
    $item = $field->get(0);
    self::assertInstanceOf(ComponentTreeItem::class, $item);
    self::assertSame(ComponentInterface::EMPTY_SLOT_MARKER_ID, $item->getComponentId());
    self::assertNull($item->getParentUuid());
    self::assertNull($item->getSlot());

    // GET reports the slot as an empty override.
    $get = self::decodeResponse($this->request(Request::create($url)));
    self::assertSame(
      ['overridden' => TRUE, 'empty' => TRUE],
      $get['slotOverrides'][self::SLOT_FIELD],
    );
  }

  /**
   * A per-content POST carrying entity form fields applies them without 500.
   *
   * Regression: `writePartitionedEntityContent()` must apply `entity_form_fields`
   * without `ClientDataToEntityConverter::convert()`, which loads a single Canvas
   * field via `ComponentTreeLoader::getCanvasFieldName()` and throws for a
   * templated node (whose tree lives in per-slot fields).
   */
  public function testPostAppliesEntityFormFields(): void {
    $node = self::createTemplatedNode();
    $url = $this->getLayoutUrl($node)->toString();

    $post = $this->postBodyFrom(
      self::decodeResponse($this->request(Request::create($url))),
    );
    $post['entity_form_fields'] = ['title[0][value]' => 'Renamed per-content'];
    $response = $this->request(Request::create($url, method: 'POST', content: \json_encode($post, JSON_THROW_ON_ERROR)));

    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    // The title change is applied to the auto-saved draft.
    self::assertSame('Renamed per-content', $this->getAutoSaveDraft($node)->label());
  }

  /**
   * Removing a template-owned component from the merged tree is rejected.
   *
   * Deleting template chrome is as much a template mutation as moving it: a
   * buggy or hostile client must not be able to make a template's component
   * disappear through the per-entity layout endpoints.
   */
  public function testPostRejectsTemplateChromeRemoval(): void {
    $node = self::createTemplatedNode();
    $url = $this->getLayoutUrl($node)->toString();

    $post = $this->postBodyFrom(self::decodeResponse($this->request(Request::create($url))));
    // Drop the template-owned host component from the submitted merged tree.
    $post['layout'][0]['components'] = \array_values(\array_filter(
      $post['layout'][0]['components'],
      static fn (array $component): bool => $component['uuid'] !== self::HOST_UUID,
    ));
    unset($post['model'][self::HOST_UUID]);

    try {
      $this->request(Request::create($url, method: 'POST', content: \json_encode($post, JSON_THROW_ON_ERROR)));
      self::fail('Expected AccessDeniedHttpException for a removed template-owned component.');
    }
    catch (AccessDeniedHttpException $e) {
      self::assertStringContainsString('cannot be removed', $e->getMessage());
    }
  }

  /**
   * Per-content editing must never write to global page regions.
   *
   * In per-content mode the global regions are locked template chrome: an
   * unchanged round-trip passes without creating a region auto-save, and a
   * structural change to a region is rejected.
   */
  public function testPostRejectsRegionChanges(): void {
    $region_component_uuid = '55555555-5555-4555-8555-555555555555';
    $region = PageRegion::create([
      'theme' => 'stark',
      'region' => 'sidebar_first',
      'component_tree' => [
        $this->overrideRow($region_component_uuid, 'Region content'),
      ],
    ]);
    $region->enable()->save();
    // Global regions only enter the per-content payload for users who may
    // edit PageRegions — the scenario under test.
    $this->setUpCurrentUser([], ['edit any ' . self::BUNDLE . ' content', 'access content', PageRegion::ADMIN_PERMISSION]);

    $node = self::createTemplatedNode();
    $url = $this->getLayoutUrl($node)->toString();
    $get = self::decodeResponse($this->request(Request::create($url)));

    // An unchanged round-trip passes and creates no auto-save for the region.
    $response = $this->request(Request::create($url, method: 'POST', content: \json_encode($this->postBodyFrom($get), JSON_THROW_ON_ERROR)));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $autoSave = $this->container->get(AutoSaveManager::class);
    \assert($autoSave instanceof AutoSaveManager);
    self::assertTrue($autoSave->getAutoSaveEntity($region)->isEmpty());

    // Removing the region's component is rejected: modifying an entity must
    // never modify the region template.
    $post = $this->postBodyFrom($get);
    foreach ($post['layout'] as &$region_node) {
      $region_node['components'] = \array_values(\array_filter(
        $region_node['components'],
        static fn (array $component): bool => $component['uuid'] !== $region_component_uuid,
      ));
    }
    unset($region_node);
    unset($post['model'][$region_component_uuid]);

    try {
      $this->request(Request::create($url, method: 'POST', content: \json_encode($post, JSON_THROW_ON_ERROR)));
      self::fail('Expected AccessDeniedHttpException for a changed region.');
    }
    catch (AccessDeniedHttpException $e) {
      self::assertStringContainsString('cannot be changed while editing content', $e->getMessage());
    }
    self::assertTrue($autoSave->getAutoSaveEntity($region)->isEmpty());
  }

  /**
   * A component hosted by a global region cannot be patched per-entity.
   */
  public function testPatchRejectsRegionComponent(): void {
    $region_component_uuid = '66666666-6666-4666-8666-666666666666';
    $region = PageRegion::create([
      'theme' => 'stark',
      'region' => 'sidebar_first',
      'component_tree' => [
        $this->overrideRow($region_component_uuid, 'Region content'),
      ],
    ]);
    $region->enable()->save();
    // Global regions only enter the per-content payload for users who may
    // edit PageRegions — the scenario under test.
    $this->setUpCurrentUser([], ['edit any ' . self::BUNDLE . ' content', 'access content', PageRegion::ADMIN_PERMISSION]);

    $node = self::createTemplatedNode();
    $url = $this->getLayoutUrl($node)->toString();
    $patch_body = [
      'componentInstanceUuid' => $region_component_uuid,
      'componentType' => 'sdc.canvas_test_sdc.props-no-slots@' . $this->contentVersion,
      'model' => [
        'source' => [
          'heading' => [
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
          ],
        ],
        'resolved' => ['heading' => 'Sneaky per-entity region edit'],
      ],
    ] + $this->getPatchContentsDefaults([$node]);

    try {
      $this->request(Request::create($url, method: 'PATCH', content: \json_encode($patch_body, JSON_THROW_ON_ERROR)));
      self::fail('Expected AccessDeniedHttpException for a region-hosted target.');
    }
    catch (AccessDeniedHttpException $e) {
      self::assertStringContainsString('cannot be edited per-entity', $e->getMessage());
    }
    $autoSave = $this->container->get(AutoSaveManager::class);
    \assert($autoSave instanceof AutoSaveManager);
    self::assertTrue($autoSave->getAutoSaveEntity($region)->isEmpty());
  }

  /**
   * The per-content edit access check mirrors the exposed-slots predicate.
   *
   * @legacy-covers \Drupal\canvas\Access\ComponentTreeEditAccessCheck::access
   */
  public function testPerContentEditAccessPredicate(): void {
    $access = $this->container->get(ComponentTreeEditAccessCheck::class);
    self::assertInstanceOf(ComponentTreeEditAccessCheck::class, $access);
    $account = $this->container->get('current_user')->getAccount();

    // A templated node with active exposed slots that the user can update: the
    // per-content editor is offered.
    $templated = self::createTemplatedNode();
    self::assertTrue($access->access($templated, $account)->isAllowed());

    // A node of a bundle without a content template: editing is denied cleanly
    // (the loader's LogicException is translated to forbidden, not a 500), so no
    // per-entity editing is offered.
    NodeType::create(['type' => 'plain', 'name' => 'Plain'])->save();
    $plain = Node::create(['type' => 'plain', 'title' => 'Plain node']);
    $plain->save();
    $plain_access = $access->access($plain, $account);
    self::assertFalse($plain_access->isAllowed());
    self::assertTrue($plain_access->isForbidden());
  }

  /**
   * The "Layout" entry point is gated by the editor route's access predicate.
   *
   * @legacy-covers \Drupal\canvas\Plugin\Menu\ContentTemplateLayoutTask::getRouteParameters
   */
  public function testLayoutEntryPointVisibility(): void {
    $accessManager = $this->container->get('access_manager');
    self::assertInstanceOf(AccessManagerInterface::class, $accessManager);
    $account = $this->container->get('current_user')->getAccount();

    $templated = self::createTemplatedNode();
    NodeType::create(['type' => 'plain', 'name' => 'Plain'])->save();
    $plain = Node::create(['type' => 'plain', 'title' => 'Plain node']);
    $plain->save();

    // The "Layout" tab links to canvas.boot.entity; its access decides tab
    // visibility. Shown for a templated node the user can update...
    self::assertTrue($accessManager->checkNamedRoute('canvas.boot.entity', [
      'entity_type' => 'node',
      'entity' => $templated->id(),
    ], $account, TRUE)->isAllowed());
    // ...hidden (denied, not a 500) for a bundle without active exposed slots.
    self::assertFalse($accessManager->checkNamedRoute('canvas.boot.entity', [
      'entity_type' => 'node',
      'entity' => $plain->id(),
    ], $account, TRUE)->isAllowed());

    // Hidden even for a templated node when the user cannot update it.
    $this->setUpCurrentUser([], ['access content']);
    $unprivileged = $this->container->get('current_user')->getAccount();
    self::assertInstanceOf(AccountInterface::class, $unprivileged);
    self::assertFalse($accessManager->checkNamedRoute('canvas.boot.entity', [
      'entity_type' => 'node',
      'entity' => $templated->id(),
    ], $unprivileged, TRUE)->isAllowed());

    // The local task maps the canonical route's entity parameter onto the
    // editor route's (entity_type, entity) parameters, so the tab opens
    // /canvas/editor/node/{id}.
    $task = new ContentTemplateLayoutTask([], 'canvas.content.layout', ['route_name' => 'canvas.boot.entity']);
    $route_match = new RouteMatch('entity.node.canonical', new Route('/node/{node}'), ['node' => $templated]);
    self::assertSame([
      'entity_type' => 'node',
      'entity' => $templated->id(),
    ], $task->getRouteParameters($route_match));
  }

  /**
   * The content list includes templated bundles with active exposed slots.
   *
   * @legacy-covers \Drupal\canvas\Controller\ApiContentControllers::list
   */
  public function testContentListIncludesTemplatedBundles(): void {
    $controller = $this->container->get(ApiContentControllers::class);
    self::assertInstanceOf(ApiContentControllers::class, $controller);

    // A node whose bundle exposes an active slot is listable.
    $templated = self::createTemplatedNode();

    // A node whose bundle has no content template is excluded.
    NodeType::create(['type' => 'plain', 'name' => 'Plain'])->save();
    $plain = Node::create(['type' => 'plain', 'title' => 'Plain node', 'status' => TRUE]);
    $plain->save();

    // Inclusion is filtered by standard entity access: the list query uses
    // accessCheck(TRUE), so the response carries the node-access cache context
    // rather than relying on a permission-string heuristic.
    $response = $controller->list('node', Request::create('/canvas/api/v0/content/node'));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    self::assertContains('user.node_grants:view', $response->getCacheableMetadata()->getCacheContexts());
    $data = \json_decode((string) $response->getContent(), TRUE, flags: JSON_THROW_ON_ERROR);
    $ids = \array_column($data['data'], 'id');

    self::assertContains((int) $templated->id(), $ids, 'Templated bundle entity is listed.');
    self::assertNotContains((int) $plain->id(), $ids, 'A bundle without active exposed slots is excluded.');

    // The listed templated entity advertises the "open in Canvas editor"
    // operation (via canvas.boot.entity); page-only operations are not offered.
    $entry = \array_values(\array_filter(
      $data['data'],
      static fn (array $row): bool => $row['id'] === (int) $templated->id(),
    ))[0];
    self::assertArrayHasKey(CanvasUriDefinitions::LINK_REL_EDIT, $entry['links']);
    self::assertStringContainsString('/canvas/editor/node/' . $templated->id(), $entry['links'][CanvasUriDefinitions::LINK_REL_EDIT]);
    self::assertArrayNotHasKey(CanvasUriDefinitions::LINK_REL_DELETE, $entry['links']);

    // The listable set invalidates when a template starts or stops exposing
    // slots.
    self::assertContains(ContentTemplate::ENTITY_TYPE_ID . '_list', $response->getCacheableMetadata()->getCacheTags());

    // An entity type with no bundle exposing active slots is still rejected.
    try {
      $controller->list('user', Request::create('/canvas/api/v0/content/user'));
      self::fail('Expected a BadRequestHttpException for an unsupported entity type.');
    }
    catch (BadRequestHttpException $e) {
      self::assertStringContainsString('active exposed slots', $e->getMessage());
    }
  }

  /**
   * Builds a POST body from a GET response, skipping entity-field processing.
   *
   * @param array<string, mixed> $get
   *   The decoded GET response.
   *
   * @return array<string, mixed>
   *   The POST body.
   */
  private function postBodyFrom(array $get): array {
    return [
      'layout' => $get['layout'],
      'model' => $get['model'] === [] ? new \stdClass() : $get['model'],
      // Skip entity form field processing: this test asserts the component tree.
      'entity_form_fields' => [],
      'autoSaves' => $get['autoSaves'],
      'clientInstanceId' => $this->randomMachineName(),
    ];
  }

  private function getAutoSaveDraft(NodeInterface $node): NodeInterface {
    $autoSave = $this->container->get(AutoSaveManager::class);
    \assert($autoSave instanceof AutoSaveManager);
    $data = $autoSave->getAutoSaveEntity($node);
    self::assertFalse($data->isEmpty(), 'Expected an auto-save entry for the node.');
    self::assertInstanceOf(NodeInterface::class, $data->entity);
    return $data->entity;
  }

  /**
   * Finds a named slot node within a component layout node.
   *
   * @param array<string, mixed> $component_node
   *   The component layout node.
   * @param string $slot_name
   *   The slot name.
   *
   * @return array<string, mixed>
   *   The slot node.
   */
  private static function findSlot(array $component_node, string $slot_name): array {
    foreach ($component_node['slots'] as $slot) {
      if ($slot['name'] === $slot_name) {
        return $slot;
      }
    }
    self::fail(\sprintf('Slot "%s" not found.', $slot_name));
  }

  /**
   * Appends a component into a named slot of a component layout node.
   *
   * @param array<string, mixed> $component_node
   *   The component layout node, modified by reference.
   * @param string $slot_name
   *   The slot name.
   * @param array<string, mixed> $component
   *   The component layout node to append.
   */
  private static function addComponentToSlot(array &$component_node, string $slot_name, array $component): void {
    foreach ($component_node['slots'] as &$slot) {
      if ($slot['name'] === $slot_name) {
        $slot['components'][] = $component;
        return;
      }
    }
    self::fail(\sprintf('Slot "%s" not found.', $slot_name));
  }

}
