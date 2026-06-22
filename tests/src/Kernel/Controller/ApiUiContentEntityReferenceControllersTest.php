<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Controller;

use Drupal\canvas\CanvasUriDefinitions;
use Drupal\canvas\Controller\ApiUiContentEntityReferenceControllers;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Entity\Page;
use Drupal\comment\Entity\CommentType;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests ApiUiContentEntityReferenceControllers endpoints.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[CoversClass(ApiUiContentEntityReferenceControllers::class)]
class ApiUiContentEntityReferenceControllersTest extends CanvasKernelTestBase {

  use UserCreationTrait;
  use RequestTrait;
  use MediaTypeCreationTrait;

  private const string URL_TYPES = '/canvas/api/v0/ui/content-entity-reference';
  private const string URL_FIELDS = '/canvas/api/v0/ui/content-entity-reference/%s/%s';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'field',
    // `comment` has an access handler that dereferences a field
    // (`commented_entity`) the stub can't populate; including it here keeps
    // the defensive catch in getBundleViewAccess() honest.
    'comment',
    // Provides `internal_string_field` (a base field marked internal), to assert
    // the picker omits internal fields.
    'entity_test',
  ];

  protected function setUp(): void {
    parent::setUp();
    // Install schemas for every content entity type the controller iterates;
    // their access handlers query the schema (e.g., FileAccessControlHandler
    // calls file_get_file_references()).
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installEntitySchema('comment');
    $this->installEntitySchema('entity_test');
    // Opt in to entity_test's `internal_string_field` base field.
    // @see \Drupal\entity_test\Hook\EntityTestHooks::entityBaseFieldInfo()
    \Drupal::state()->set('entity_test.internal_field', TRUE);
    // Canvas page — exercises a bundle-less entity type alongside user.
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    // FileAccessControlHandler queries file_usage during 'view' access.
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['system', 'field', 'filter', 'user', 'node']);

    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Basic page'])->save();

    // Image and link fields on article — exercise the reference and
    // multi-property leaf branches of buildFieldEntry().
    FieldStorageConfig::create([
      'field_name' => 'field_image',
      'entity_type' => 'node',
      'type' => 'image',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_image',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Image',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_link',
      'entity_type' => 'node',
      'type' => 'link',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_link',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Link',
    ])->save();

    // Plain file field — non-image reference whose non-`entity` properties
    // include `target_id`, `display`, `description`.
    FieldStorageConfig::create([
      'field_name' => 'field_file',
      'entity_type' => 'node',
      'type' => 'file',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_file',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'File',
    ])->save();

    // Video media bundle + a reference field to it — exercises the multi-
    // bundle-keyed-target reference branch.
    $this->createMediaType('video_file', [
      'id' => 'video',
      'label' => 'Video',
    ]);
    FieldStorageConfig::create([
      'field_name' => 'field_video',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'media'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_video',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Video',
      'settings' => ['handler_settings' => ['target_bundles' => ['video' => 'video']]],
    ])->save();

    // Unlimited-cardinality reference field targeting a single bundle — the
    // only reason it cannot be browsed is that it is multi-valued.
    FieldStorageConfig::create([
      'field_name' => 'field_related',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'node'],
      'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_related',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Related content',
      'settings' => ['handler_settings' => ['target_bundles' => ['page' => 'page']]],
    ])->save();

    // Entity reference field targeting multiple bundles — exercises the
    // multi-target-bundle branch of resolveReferenceTarget().
    $this->createMediaType('image', [
      'id' => 'image',
      'label' => 'Image',
    ]);
    FieldStorageConfig::create([
      'field_name' => 'field_media',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'media'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_media',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Media',
      'settings' => ['handler_settings' => ['target_bundles' => ['video' => 'video', 'image' => 'image']]],
    ])->save();
  }

  public function testContentEntityTypesEndpoint(): void {
    $this->setUpCurrentUser([], [JavaScriptComponent::ADMIN_PERMISSION, 'access content', 'access user profiles']);

    $response = $this->request(Request::create(self::URL_TYPES));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $data = self::decodeResponse($response)['data'];

    self::assertArrayHasKey('node', $data);
    self::assertSame('Content', $data['node']['label']);
    self::assertArrayHasKey('article', $data['node']['bundles']);
    self::assertSame('Article', $data['node']['bundles']['article']['label']);
    self::assertSame('/canvas/api/v0/ui/content-entity-reference/node/article', $data['node']['bundles']['article']['links'][CanvasUriDefinitions::LINK_REL_TYPED_DATA_BROWSER]['href']);
    self::assertArrayHasKey('page', $data['node']['bundles']);
    self::assertSame('Basic page', $data['node']['bundles']['page']['label']);
    self::assertSame('/canvas/api/v0/ui/content-entity-reference/node/page', $data['node']['bundles']['page']['links'][CanvasUriDefinitions::LINK_REL_TYPED_DATA_BROWSER]['href']);

    // Bundle-less entity types are surfaced with a single self-named bundle
    // (matching how core's entity_type_bundle_info reports them).
    self::assertArrayHasKey('user', $data);
    self::assertSame('User', $data['user']['label']);
    self::assertArrayHasKey('user', $data['user']['bundles']);
    self::assertSame('/canvas/api/v0/ui/content-entity-reference/user/user', $data['user']['bundles']['user']['links'][CanvasUriDefinitions::LINK_REL_TYPED_DATA_BROWSER]['href']);
    self::assertArrayHasKey(Page::ENTITY_TYPE_ID, $data);
    self::assertSame('Page', $data[Page::ENTITY_TYPE_ID]['label']);
    self::assertArrayHasKey(Page::ENTITY_TYPE_ID, $data[Page::ENTITY_TYPE_ID]['bundles']);

    self::assertInstanceOf(CacheableJsonResponse::class, $response);
    self::assertSame(['user.permissions'], $response->getCacheableMetadata()->getCacheContexts());
  }

  public function testContentEntityTypesAccessFiltering(): void {
    // User with Canvas UI access (via administer code components) but no
    // access content permission.
    $this->setUpCurrentUser([], [JavaScriptComponent::ADMIN_PERMISSION]);

    $response = $this->request(Request::create(self::URL_TYPES));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $data = self::decodeResponse($response)['data'];
    // Without 'access content', nodes should be filtered out.
    self::assertArrayNotHasKey('node', $data);
  }

  /**
   * Pins the defensive catch in getBundleViewAccess().
   *
   * CommentAccessControlHandler dereferences `entity_id` (commented entity)
   * which an unsaved stub cannot populate, so the stub-based view-access check
   * throws. The controller catches that and treats the bundle as forbidden so
   * one misbehaving entity type does not 500 the entire response. The endpoint
   * must therefore (a) still return 200 and (b) omit the comment bundle even
   * for a user who holds 'access comments'.
   */
  public function testContentEntityTypesCatchesStubAccessThrow(): void {
    CommentType::create([
      'id' => 'comment',
      'label' => 'Default comments',
      'target_entity_type_id' => 'node',
    ])->save();

    $this->setUpCurrentUser([], [
      JavaScriptComponent::ADMIN_PERMISSION,
      'access content',
      'access comments',
    ]);

    $response = $this->request(Request::create(self::URL_TYPES));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $data = self::decodeResponse($response)['data'];
    self::assertArrayNotHasKey('comment', $data);
  }

  /**
   * Top-level shape for scalar/reference rows plus the `entity` exclusion.
   */
  public function testFieldsEndpointBasicShape(): void {
    $this->setUpCurrentUser([], [JavaScriptComponent::ADMIN_PERMISSION, 'access content', 'access user profiles']);

    $response = $this->request(Request::create(\sprintf(self::URL_FIELDS, 'node', 'article')));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $data = self::decodeResponse($response)['data'];
    self::assertIsArray($data);
    $by_name = \array_column($data, NULL, 'name');

    // Across every row, the `entity` typed-data property is the descend path
    // — it must never appear as a pickable leaf.
    foreach ($data as $row) {
      self::assertNotContains('entity', \array_column($row['properties'], 'name'));
    }

    // `title` (scalar string field): non-reference, one `value` property.
    self::assertSame(
      [
        'name' => 'title',
        'label' => 'Title',
        'hasChildren' => FALSE,
        'properties' => [
          [
            'name' => 'value',
            'label' => 'Text value',
            'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
          ],
        ],
      ],
      $by_name['title'],
    );

    // `uid` (entity_reference to user, bundle-less target): hasChildren=true,
    // single entry in targetBundles keyed by the entity type ID.
    // `target_id` surfaces as a pickable leaf — the developer can read the
    // raw user ID without descending into the user entity.
    self::assertTrue($by_name['uid']['hasChildren']);
    self::assertSame('user', $by_name['uid']['targetEntityType']);
    self::assertArrayHasKey('targetBundles', $by_name['uid']);
    self::assertCount(1, $by_name['uid']['targetBundles']);
    self::assertArrayHasKey('user', $by_name['uid']['targetBundles']);
    // node:article.uid → user.name.value (user/user normalizes to entity:user).
    $expected_ref_expression = 'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝name␞␟value';
    self::assertSame($expected_ref_expression, $by_name['uid']['targetBundles']['user']['labelExpression']);
    self::assertSame(
      '/canvas/api/v0/ui/content-entity-reference/user/user?parent=' . \urlencode($expected_ref_expression),
      $by_name['uid']['targetBundles']['user']['links'][CanvasUriDefinitions::LINK_REL_TYPED_DATA_BROWSER]['href'],
    );
    $uid_props_by_name = \array_column($by_name['uid']['properties'], NULL, 'name');
    self::assertArrayNotHasKey('entity', $uid_props_by_name);
    self::assertSame(
      'ℹ︎␜entity:node:article␝uid␞␟target_id',
      $uid_props_by_name['target_id']['expression'],
    );
  }

  /**
   * Image field exposes all non-internal, non-`entity` typed-data properties.
   *
   * Image fields are references (target=file). The response must include
   * Canvas's computed `src_with_alternate_widths` and
   * `srcset_candidate_uri_template` (computed-but-not-explicitly-internal).
   */
  public function testFieldsEndpointImageFieldExposesAllProperties(): void {
    $this->setUpCurrentUser([], [JavaScriptComponent::ADMIN_PERMISSION, 'access content']);
    $response = $this->request(Request::create(\sprintf(self::URL_FIELDS, 'node', 'article')));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $by_name = \array_column(self::decodeResponse($response)['data'], NULL, 'name');

    self::assertArrayHasKey('field_image', $by_name);
    $row = $by_name['field_image'];
    // ImageItem extends FileItem extends EntityReferenceItem with target_type=file.
    self::assertTrue($row['hasChildren']);
    self::assertSame('file', $row['targetEntityType']);
    self::assertArrayHasKey('targetBundles', $row);
    self::assertCount(1, $row['targetBundles']);
    self::assertArrayHasKey('file', $row['targetBundles']);
    self::assertArrayHasKey(CanvasUriDefinitions::LINK_REL_TYPED_DATA_BROWSER, $row['targetBundles']['file']['links']);
    self::assertStringStartsWith('/canvas/api/v0/ui/content-entity-reference/file/file', $row['targetBundles']['file']['links'][CanvasUriDefinitions::LINK_REL_TYPED_DATA_BROWSER]['href']);

    $props_by_name = \array_column($row['properties'], NULL, 'name');
    // Exact non-`entity` property set: target_id (from EntityReferenceItem),
    // alt/title/width/height (from ImageItem) plus Canvas's computed
    // src + src_with_alternate_widths + srcset_candidate_uri_template
    // (from ImageItemOverride). `display`/`description` are unset by
    // ImageItem itself.
    $expected_names = ['target_id', 'alt', 'title', 'width', 'height', 'srcset_candidate_uri_template', 'src_with_alternate_widths', 'src'];
    \sort($expected_names);
    $actual_names = \array_keys($props_by_name);
    \sort($actual_names);
    self::assertSame($expected_names, $actual_names);

    foreach ($expected_names as $property_name) {
      $expected_expression = 'ℹ︎␜entity:node:article␝field_image␞␟' . $property_name;
      self::assertSame($expected_expression, $props_by_name[$property_name]['expression']);
    }
  }

  /**
   * Following an image field's descend link into file/file returns its fields.
   *
   * @see \Drupal\canvas\Controller\ApiUiContentEntityReferenceControllers::createBundleStub()
   */
  public function testFieldsEndpointFollowsImageFieldDescendLinkIntoFile(): void {
    $this->setUpCurrentUser([], [JavaScriptComponent::ADMIN_PERMISSION, 'access content']);

    // Read the descend link the picker generates for the image field.
    $response = $this->request(Request::create(\sprintf(self::URL_FIELDS, 'node', 'article')));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $by_name = \array_column(self::decodeResponse($response)['data'], NULL, 'name');
    $href = $by_name['field_image']['targetBundles']['file']['links'][CanvasUriDefinitions::LINK_REL_TYPED_DATA_BROWSER]['href'];

    // Follow it. Because the stub has a file uri with public:// for the access checks, it will be allowed.
    $descend = $this->request(Request::create($href));
    self::assertSame(Response::HTTP_OK, $descend->getStatusCode());
    $file_fields = \array_column(self::decodeResponse($descend)['data'], NULL, 'name');
    foreach (['filename', 'uri', 'filemime', 'filesize'] as $expected_file_field) {
      self::assertArrayHasKey($expected_file_field, $file_fields);
    }

    // The file fields' expressions descend through the image reference: each is
    // a reference expression rooted at the article, following field_image into
    // the file, with the file property as the leaf.
    $uri_prop = \array_column($file_fields['uri']['properties'], NULL, 'name')['value'];
    self::assertSame(
      'ℹ︎␜entity:node:article␝field_image␞␟entity␜␜entity:file␝uri␞␟value',
      $uri_prop['expression'],
    );
  }

  /**
   * File field exposes its non-`entity` properties.
   *
   * File fields are references (target=file). `display`/`description` survive
   * on FileItem because they are not flagged internal in core.
   */
  public function testFieldsEndpointFileFieldExposesAllProperties(): void {
    $this->setUpCurrentUser([], [JavaScriptComponent::ADMIN_PERMISSION, 'access content']);
    $response = $this->request(Request::create(\sprintf(self::URL_FIELDS, 'node', 'article')));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $by_name = \array_column(self::decodeResponse($response)['data'], NULL, 'name');

    self::assertArrayHasKey('field_file', $by_name);
    $row = $by_name['field_file'];
    self::assertTrue($row['hasChildren']);
    self::assertSame('file', $row['targetEntityType']);
    self::assertArrayHasKey('targetBundles', $row);
    self::assertCount(1, $row['targetBundles']);
    self::assertArrayHasKey('file', $row['targetBundles']);
    self::assertArrayHasKey(CanvasUriDefinitions::LINK_REL_TYPED_DATA_BROWSER, $row['targetBundles']['file']['links']);
    self::assertStringStartsWith('/canvas/api/v0/ui/content-entity-reference/file/file', $row['targetBundles']['file']['links'][CanvasUriDefinitions::LINK_REL_TYPED_DATA_BROWSER]['href']);

    $props_by_name = \array_column($row['properties'], NULL, 'name');
    self::assertArrayHasKey('target_id', $props_by_name);
    self::assertArrayNotHasKey('entity', $props_by_name);
    self::assertSame(
      'ℹ︎␜entity:node:article␝field_file␞␟target_id',
      $props_by_name['target_id']['expression'],
    );
  }

  /**
   * Reference field to a media bundle surfaces target keys + `target_id`.
   *
   * `hasChildren=true`, `targetEntityType`/`targetBundles` populated,
   * `target_id` pickable as a leaf, `entity` excluded from `properties[]`.
   */
  public function testFieldsEndpointMediaReferenceField(): void {
    $this->setUpCurrentUser([], [JavaScriptComponent::ADMIN_PERMISSION, 'access content', 'view media']);
    $response = $this->request(Request::create(\sprintf(self::URL_FIELDS, 'node', 'article')));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $by_name = \array_column(self::decodeResponse($response)['data'], NULL, 'name');

    self::assertArrayHasKey('field_video', $by_name);
    $row = $by_name['field_video'];
    self::assertTrue($row['hasChildren']);
    self::assertSame('media', $row['targetEntityType']);
    self::assertArrayHasKey('targetBundles', $row);
    self::assertCount(1, $row['targetBundles']);
    self::assertArrayHasKey('video', $row['targetBundles']);
    self::assertArrayHasKey(CanvasUriDefinitions::LINK_REL_TYPED_DATA_BROWSER, $row['targetBundles']['video']['links']);
    self::assertStringStartsWith('/canvas/api/v0/ui/content-entity-reference/media/video', $row['targetBundles']['video']['links'][CanvasUriDefinitions::LINK_REL_TYPED_DATA_BROWSER]['href']);

    $props_by_name = \array_column($row['properties'], NULL, 'name');
    self::assertArrayHasKey('target_id', $props_by_name);
    self::assertArrayNotHasKey('entity', $props_by_name);
    self::assertSame(
      'ℹ︎␜entity:node:article␝field_video␞␟target_id',
      $props_by_name['target_id']['expression'],
    );
  }

  /**
   * Multi-target-bundle reference field is not offered for browsing.
   *
   * A reference field targeting more than one bundle would let the picker
   * compose a multi-target-bundle expression, which is not yet supported at
   * render time. So it is not walkable (`hasChildren` is FALSE and there is no
   * `targetBundles`); only its own leaf properties surface.
   *
   * @todo Update in https://git.drupalcode.org/project/canvas/-/work_items/3591656
   */
  public function testFieldsEndpointMultiTargetBundleReferenceField(): void {
    $this->setUpCurrentUser([], [JavaScriptComponent::ADMIN_PERMISSION, 'access content', 'view media']);
    $response = $this->request(Request::create(\sprintf(self::URL_FIELDS, 'node', 'article')));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $by_name = \array_column(self::decodeResponse($response)['data'], NULL, 'name');

    self::assertArrayHasKey('field_media', $by_name);
    $row = $by_name['field_media'];
    self::assertFalse($row['hasChildren']);
    self::assertArrayNotHasKey('targetEntityType', $row);
    self::assertArrayNotHasKey('targetBundles', $row);

    // Leaf properties are still present; the `entity` descend path is not.
    $props_by_name = \array_column($row['properties'], NULL, 'name');
    self::assertArrayHasKey('target_id', $props_by_name);
    self::assertArrayNotHasKey('entity', $props_by_name);
    self::assertSame(
      'ℹ︎␜entity:node:article␝field_media␞␟target_id',
      $props_by_name['target_id']['expression'],
    );
  }

  /**
   * The picker omits multi-valued fields, matching the data-integrity rule.
   *
   * The picker composes delta-less expressions; on a multi-valued field the
   * Evaluator resolves those to a delta-keyed array of values (or entities),
   * which is not supported at render time — so neither descending nor leaf
   * picks are offered.
   *
   * @see \Drupal\canvas\Plugin\Validation\Constraint\MultiValuedFieldNotSupportedConstraint
   * @todo Update in https://git.drupalcode.org/project/canvas/-/work_items/3589536
   */
  public function testFieldsEndpointOmitsMultiValuedFields(): void {
    $this->setUpCurrentUser([], [JavaScriptComponent::ADMIN_PERMISSION, 'access content']);
    $response = $this->request(Request::create(\sprintf(self::URL_FIELDS, 'node', 'article')));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $by_name = \array_column(self::decodeResponse($response)['data'], NULL, 'name');

    self::assertArrayNotHasKey('field_related', $by_name);
    // Single-valued fields are unaffected.
    self::assertArrayHasKey('field_image', $by_name);
  }

  /**
   * The fields response depends on field definitions changing.
   *
   * Whether a field is offered — and whether it can be browsed into — is
   * decided from its cardinality (field storage config) and target bundles
   * (field config). Both invalidate the `entity_field_info` cache tag when they
   * change, so the response must carry it: otherwise editing a field's
   * cardinality (or making a reference multi-target) would not invalidate a
   * cached response.
   *
   * A reference that can be browsed into also lists its target bundles'
   * labels, read from bundle info, so the response depends on `entity_bundles`
   * too: a renamed bundle's label must not be served stale.
   *
   * @see \Drupal\canvas\Controller\ApiUiContentEntityReferenceControllers::listFields()
   * @see \Drupal\Core\Field\FieldStorageDefinitionListener::onFieldStorageDefinitionUpdate()
   * @see \Drupal\Core\Field\FieldConfigBase::postSave()
   * @see \Drupal\Core\Entity\EntityTypeBundleInfo::getAllBundleInfo()
   */
  public function testFieldsEndpointCacheTags(): void {
    $this->setUpCurrentUser([], [JavaScriptComponent::ADMIN_PERMISSION, 'access content']);
    $response = $this->request(Request::create(\sprintf(self::URL_FIELDS, 'node', 'article')));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    self::assertInstanceOf(CacheableJsonResponse::class, $response);

    $cache_tags = $response->getCacheableMetadata()->getCacheTags();
    self::assertContains('entity_field_info', $cache_tags);
    self::assertContains('entity_bundles', $cache_tags);
    self::assertContains('node_list', $cache_tags);
  }

  /**
   * The picker omits internal fields, matching the data-integrity constraint.
   *
   * `internal_string_field` is a base field marked internal; the picker must not
   * offer it, so the UI never surfaces a field that
   * EntityFieldExpressionMustNotTargetInternalProperty would reject at save.
   *
   * @see \Drupal\canvas\Plugin\Validation\Constraint\EntityFieldExpressionMustNotTargetInternalPropertyConstraint
   */
  public function testFieldsEndpointOmitsInternalFields(): void {
    $this->setUpCurrentUser([], [JavaScriptComponent::ADMIN_PERMISSION, 'view test entity']);
    $response = $this->request(Request::create(\sprintf(self::URL_FIELDS, 'entity_test', 'entity_test')));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $by_name = \array_column(self::decodeResponse($response)['data'], NULL, 'name');

    // A normal base field is offered; the internal one is not.
    self::assertArrayHasKey('name', $by_name);
    self::assertArrayNotHasKey('internal_string_field', $by_name);
  }

  /**
   * Link field exposes uri/title/options as separate leaves.
   *
   * No object wrapping in the response — combining into a
   * FieldObjectPropsExpression happens server-side during save.
   */
  public function testFieldsEndpointLinkFieldExposesAllProperties(): void {
    $this->setUpCurrentUser([], [JavaScriptComponent::ADMIN_PERMISSION, 'access content']);
    $response = $this->request(Request::create(\sprintf(self::URL_FIELDS, 'node', 'article')));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $by_name = \array_column(self::decodeResponse($response)['data'], NULL, 'name');

    self::assertArrayHasKey('field_link', $by_name);
    $row = $by_name['field_link'];
    self::assertFalse($row['hasChildren']);
    self::assertArrayNotHasKey('targetEntityType', $row);
    self::assertArrayNotHasKey('targetBundle', $row);
    self::assertArrayNotHasKey('links', $row);

    $props_by_name = \array_column($row['properties'], NULL, 'name');
    foreach (['uri', 'title', 'options'] as $property_name) {
      self::assertArrayHasKey($property_name, $props_by_name);
      self::assertSame(
        'ℹ︎␜entity:node:article␝field_link␞␟' . $property_name,
        $props_by_name[$property_name]['expression'],
      );
    }
  }

  /**
   * The `?parent=` query chains each property's expression under that parent.
   */
  public function testFieldsEndpointWithParent(): void {
    $this->setUpCurrentUser([], [JavaScriptComponent::ADMIN_PERMISSION, 'access content', 'access user profiles']);

    // Parent: node:article.uid → entity:user.name.value (a complete reference
    // expression terminating at a scalar leaf, as required).
    $parent = 'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝name␞␟value';

    $url = \sprintf(self::URL_FIELDS, 'user', 'user') . '?parent=' . \urlencode($parent);
    $response = $this->request(Request::create($url));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $by_name = \array_column(self::decodeResponse($response)['data'], NULL, 'name');

    // The `name` field's `value` property — composed with the parent — chains
    // node:article.uid → user:user.name.value, i.e. the parent expression.
    self::assertArrayHasKey('name', $by_name);
    $name_props_by_name = \array_column($by_name['name']['properties'], NULL, 'name');
    self::assertArrayHasKey('value', $name_props_by_name);
    self::assertSame($parent, $name_props_by_name['value']['expression']);

    // The response must vary by `?parent=` so Dynamic Page Cache does not
    // reuse a cached response for a different parent expression.
    self::assertInstanceOf(CacheableJsonResponse::class, $response);
    self::assertSame(['user.permissions', 'user', 'url.query_args:parent'], $response->getCacheableMetadata()->getCacheContexts());
  }

  /**
   * A non-reference `?parent=` is rejected, not silently ignored.
   *
   * Only a reference expression carries a reference chain to compose the
   * picked fields onto.
   */
  public function testFieldsEndpointParentNotAReferenceExpression(): void {
    $this->setUpCurrentUser([], [JavaScriptComponent::ADMIN_PERMISSION, 'access content', 'access user profiles']);
    $parent = 'ℹ︎␜entity:node:article␝title␞␟value';
    $this->expectException(NotFoundHttpException::class);
    $this->expectExceptionMessage('Parent expression is not a reference expression.');
    $this->request(Request::create(\sprintf(self::URL_FIELDS, 'user', 'user') . '?parent=' . \urlencode($parent)));
  }

  /**
   * A crafted multi-target-bundle `?parent=` is a 404, not a 500.
   *
   * The picker never composes such parents (multi-target-bundle references
   * are not walkable), so one can only arrive hand-crafted — and it must be
   * rejected before reaching
   * ReferenceFieldPropExpression::withFinalTargetReplaced(), which throws.
   *
   * @see ::testFieldsEndpointMultiTargetBundleReferenceField()
   */
  public function testFieldsEndpointParentWithMultiTargetBundleReference(): void {
    $this->setUpCurrentUser([], [JavaScriptComponent::ADMIN_PERMISSION, 'access content', 'view media']);
    // `field_media` targets both the `image` and `video` media bundles.
    $parent = 'ℹ︎␜entity:node:article␝field_media␞␟entity␜[␜entity:media:image␝name␞␟value][␜entity:media:video␝name␞␟value]';
    $this->expectException(NotFoundHttpException::class);
    $this->expectExceptionMessage('Multi-target-bundle parent expressions are not supported.');
    $this->request(Request::create(\sprintf(self::URL_FIELDS, 'media', 'image') . '?parent=' . \urlencode($parent)));
  }

  /**
   * A `?parent=` chain must terminate at the requested entity type + bundle.
   *
   * Otherwise the endpoint would compose semantically broken expressions:
   * leaves claiming to live on the requested bundle while the chain points
   * elsewhere.
   */
  public function testFieldsEndpointParentChainTerminusMismatch(): void {
    $this->setUpCurrentUser([], [JavaScriptComponent::ADMIN_PERMISSION, 'access content', 'access user profiles']);
    // The chain terminates at user/user, but node/page fields are requested.
    $parent = 'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝name␞␟value';
    $this->expectException(NotFoundHttpException::class);
    // Bundle-less hosts normalize to `entity:user` (no bundle repeat).
    // @see \Drupal\canvas\TypedData\BetterEntityDataDefinition::getDataType()
    $this->expectExceptionMessage("terminates at 'entity:user', not at the requested 'entity:node:page'");
    $this->request(Request::create(\sprintf(self::URL_FIELDS, 'node', 'page') . '?parent=' . \urlencode($parent)));
  }

  public function testFieldsEndpointInvalidEntityType(): void {
    $this->setUpCurrentUser([], [JavaScriptComponent::ADMIN_PERMISSION, 'access content']);
    $this->expectException(NotFoundHttpException::class);
    $this->request(Request::create(\sprintf(self::URL_FIELDS, 'nonsense', 'whatever')));
  }

  public function testFieldsEndpointInvalidBundle(): void {
    $this->setUpCurrentUser([], [JavaScriptComponent::ADMIN_PERMISSION, 'access content']);
    $this->expectException(NotFoundHttpException::class);
    $this->request(Request::create(\sprintf(self::URL_FIELDS, 'node', 'nonsense')));
  }

  public function testFieldsEndpointAccessDenied(): void {
    // User has Canvas UI access but cannot view nodes.
    $this->setUpCurrentUser([], [JavaScriptComponent::ADMIN_PERMISSION]);
    try {
      $this->request(Request::create(\sprintf(self::URL_FIELDS, 'node', 'article')));
      self::fail('Expected CacheableAccessDeniedHttpException.');
    }
    catch (CacheableAccessDeniedHttpException $exception) {
      // The accumulated cacheability is preserved on the exception so the 403
      // response varies per permission set.
      self::assertSame([], $exception->getCacheTags());
      self::assertSame(['user.permissions'], $exception->getCacheContexts());
    }
  }

  public function testFieldsEndpointParentChainAccessDenied(): void {
    // User can view users but not nodes, so the per-entity access check on the
    // parent expression's reference chain (node:article.uid → user) must deny —
    // and the resulting exception must carry the cacheability accumulated up to
    // the failing entity in the chain.
    $this->setUpCurrentUser([], [JavaScriptComponent::ADMIN_PERMISSION, 'access user profiles']);
    $parent = 'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝name␞␟value';
    $url = \sprintf(self::URL_FIELDS, 'user', 'user') . '?parent=' . \urlencode($parent);
    try {
      $this->request(Request::create($url));
      self::fail('Expected CacheableAccessDeniedHttpException.');
    }
    catch (CacheableAccessDeniedHttpException $exception) {
      self::assertSame([], $exception->getCacheTags());
      self::assertSame(['user.permissions'], $exception->getCacheContexts());
    }
  }

  public function testFieldsEndpointFiltersInaccessibleFields(): void {
    // Non-admin user with access to user profiles but not `administer users`.
    // The user entity's `pass` field denies view access in that scenario, so
    // it must be filtered out of the response.
    $this->setUpCurrentUser([], [JavaScriptComponent::ADMIN_PERMISSION, 'access user profiles']);

    $response = $this->request(Request::create(\sprintf(self::URL_FIELDS, 'user', 'user')));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $data = self::decodeResponse($response)['data'];
    $field_names = \array_column($data, 'name');

    self::assertContains('name', $field_names);
    self::assertNotContains('pass', $field_names);
  }

}
