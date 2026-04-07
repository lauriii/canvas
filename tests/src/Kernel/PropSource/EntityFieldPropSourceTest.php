<?php

declare(strict_types=1);

// cspell:ignore Qqzr

namespace Drupal\Tests\canvas\Kernel\PropSource;

use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\canvas\Plugin\Adapter\UnixTimestampToDateAdapter;
use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\canvas\PropExpressions\StructuredData\FieldObjectPropsExpression;
use Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\canvas\PropSource\EntityFieldPropSource;
use Drupal\canvas\PropSource\PropSource;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[CoversClass(EntityFieldPropSource::class)]
#[CoversMethod(PropSource::class, 'parse')]
#[Group('canvas')]
#[Group('canvas_data_model')]
#[Group('canvas_data_model__prop_expressions')]
#[RunTestsInSeparateProcesses]
class EntityFieldPropSourceTest extends PropSourceTestBase {

  #[DataProvider('providerTest')]
  public function test(
    array $permissions,
    string $expression,
    ?string $adapter_plugin_id,
    bool $is_required,
    array $expected_array_representation,
    string $expected_expression_class,
    ?EvaluationResult $expected_evaluation_with_user_host_entity,
    ?array $expected_user_access_denied_message,
    ?EvaluationResult $expected_evaluation_with_node_host_entity,
    ?array $expected_node_access_denied_message,
    array $expected_dependencies_expression_only,
    array $expected_dependencies_with_host_entity,
  ): void {
    // Evaluating entity field props requires entity and field access of the
    // data being accessed.

    // For testing expressions relying on users.
    $this->installEntitySchema('user');
    $user = User::create([
      'uuid' => '881261cd-c9e2-4dcd-b0a8-1efa2e319a13',
      'name' => 'John Doe',
      'status' => 1,
      'created' => 694695600,
      'access' => 1720602713,
    ]);
    $user->save();

    // For testing expressions relying on nodes.
    $this->installEntitySchema('node');
    NodeType::create(['type' => 'page', 'name' => 'page'])->save();
    $this->createImageField('field_image', 'node', 'page');
    FieldStorageConfig::create([
      'field_name' => 'a_timestamp_maybe',
      'entity_type' => 'node',
      'type' => 'timestamp',
      'settings' => [],
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'a_timestamp_maybe',
      'label' => 'A timestamp, maybe',
      'entity_type' => 'node',
      'bundle' => 'page',
      // Optional, to be able to test how EntityFieldPropSource' adapter support
      // handles missing optional values (i.e. NULL).
      'required' => FALSE,
      'settings' => [],
    ])->save();
    $this->createEntityReferenceField('node', 'page', 'field_photos', 'Photos', 'media',
      selection_handler_settings: [
        'target_bundles' => [
          'anything_is_possible',
          'image',
          'image_but_not_image_media_source',
        ],
      ],
      cardinality: FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
    );
    $node = $this->createNode([
      'type' => 'page',
      'uid' => $user->id(),
      'field_image' => ['target_id' => 1],
      'field_photos' => [['target_id' => 2], ['target_id' => 1], ['target_id' => 3]],
    ]);

    $original = EntityFieldPropSource::parse(match ($adapter_plugin_id) {
      NULL => ['sourceType' => PropSource::EntityField->value, 'expression' => $expression],
      default => ['sourceType' => PropSource::EntityField->value, 'expression' => $expression, 'adapter' => $adapter_plugin_id],
    });
    // First, get the string representation and parse it back, to prove
    // serialization and deserialization works.
    $json_representation = (string) $original;
    $decoded_representation = json_decode($json_representation, TRUE);
    $this->assertSame($expected_array_representation, $decoded_representation);
    // @phpstan-ignore argument.type
    $parsed = PropSource::parse($decoded_representation);
    $this->assertInstanceOf(EntityFieldPropSource::class, $parsed);
    // The contained information read back out.
    $this->assertSame(PropSource::EntityField->value, $parsed->getSourceType());
    // @phpstan-ignore-next-line argument.type
    $this->assertInstanceOf($expected_expression_class, StructuredDataPropExpression::fromString($parsed->asChoice()));

    // Test the functionality of a EntityFieldPropSource:
    $parsed_expression = StructuredDataPropExpression::fromString($expression);
    $correct_host_entity_type = match (get_class($parsed_expression)) {
      FieldPropExpression::class, FieldObjectPropsExpression::class => $parsed_expression->entityType->getEntityTypeId(),
      ReferenceFieldPropExpression::class => $parsed_expression->referencer->entityType->getEntityTypeId(),
      default => throw new \LogicException(),
    };
    // - evaluate it to populate an SDC prop using a `user` host entity
    // First try without the correct permissions.
    if ($expected_evaluation_with_user_host_entity instanceof EvaluationResult) {
      self::assertNotNull($expected_user_access_denied_message);
      \assert(count($permissions) === count($expected_user_access_denied_message));
      for ($i = 0; $i < count($expected_user_access_denied_message); $i++) {
        // First try without the correct permissions; then grant each permission
        // one-by-one, to observe what the effect is on the evaluation result.
        if ($i >= 1) {
          $this->setUpCurrentUser(permissions: array_slice($permissions, 0, $i));
        }
        try {
          $parsed->evaluate(clone $user, $is_required);
          $this->fail('Should throw an access exception.');
        }
        catch (CacheableAccessDeniedHttpException $e) {
          self::assertSame($expected_user_access_denied_message[$i], $e->getMessage());
        }
      }
    }
    // Grant all permissions, now it should succeed.
    $this->setUpCurrentUser(permissions: $permissions);
    try {
      $result = $parsed->evaluate(clone $user, $is_required);
      if (!$expected_evaluation_with_user_host_entity instanceof EvaluationResult) {
        self::fail('Should throw an exception.');
      }
      else {
        self::assertSame($expected_evaluation_with_user_host_entity->value, $result->value);
        self::assertEqualsCanonicalizing($expected_evaluation_with_user_host_entity->getCacheTags(), $result->getCacheTags());
        self::assertEqualsCanonicalizing($expected_evaluation_with_user_host_entity->getCacheContexts(), $result->getCacheContexts());
        self::assertSame($expected_evaluation_with_user_host_entity->getCacheMaxAge(), $result->getCacheMaxAge());
      }
    }
    catch (\DomainException $e) {
      self::assertSame(\sprintf("`%s` is an expression for entity type `%s`, but the provided entity is of type `user`.", (string) $parsed_expression, $correct_host_entity_type), $e->getMessage());
    }

    // - evaluate it to populate an SDC prop using a `node` host entity
    // First try without the correct permissions.
    $this->setUpCurrentUser();
    if ($expected_evaluation_with_node_host_entity instanceof EvaluationResult) {
      self::assertNotNull($expected_node_access_denied_message);
      \assert(count($permissions) === count($expected_node_access_denied_message));
      for ($i = 0; $i < count($expected_node_access_denied_message); $i++) {
        // First try without the correct permissions; then grant each permission
        // one-by-one, to observe what the effect is on the evaluation result.
        if ($i >= 1) {
          $this->setUpCurrentUser(permissions: array_slice($permissions, 0, $i));
        }
        try {
          $parsed->evaluate(clone $node, $is_required);
          $this->fail('Should throw an access exception.');
        }
        catch (CacheableAccessDeniedHttpException $e) {
          self::assertSame($expected_node_access_denied_message[$i], $e->getMessage());
        }
      }
    }
    // Grant all permissions, now it should succeed.
    $this->setUpCurrentUser(permissions: $permissions);
    try {
      $result = $parsed->evaluate(clone $node, $is_required);
      if (!$expected_evaluation_with_node_host_entity instanceof EvaluationResult) {
        self::fail('Should throw an exception.');
      }
      else {
        self::assertEqualsCanonicalizing($expected_evaluation_with_node_host_entity->getCacheTags(), $result->getCacheTags());
        self::assertEqualsCanonicalizing($expected_evaluation_with_node_host_entity->getCacheContexts(), $result->getCacheContexts());
        self::assertSame($expected_evaluation_with_node_host_entity->getCacheMaxAge(), $result->getCacheMaxAge());
        self::assertSame($expected_evaluation_with_node_host_entity->value, $this->allowSimplifiedExpectations($result)->value);
      }
    }
    catch (\DomainException $e) {
      self::assertSame(\sprintf("`%s` is an expression for entity type `%s`, but the provided entity is of type `node`.", (string) $parsed_expression, $correct_host_entity_type), $e->getMessage());
    }

    // - calculate its dependencies
    $this->assertSame($expected_dependencies_expression_only, $parsed->calculateDependencies());
    $correct_host_entity = match ($correct_host_entity_type) {
      'user' => $user,
      'node' => $node,
      default => throw new \LogicException(),
    };
    $this->assertSame($expected_dependencies_with_host_entity, $parsed->calculateDependencies($correct_host_entity));
  }

  public static function providerTest(): \Generator {
    yield "simple: FieldPropExpression" => [
      'permissions' => ['access user profiles'],
      'expression' => 'ℹ︎␜entity:user␝name␞␟value',
      'adapter_plugin_id' => NULL,
      'is_required' => TRUE,
      'expected_array_representation' => [
        'sourceType' => PropSource::EntityField->value,
        'expression' => 'ℹ︎␜entity:user␝name␞␟value',
      ],
      'expected_expression_class' => FieldPropExpression::class,
      'expected_evaluation_with_user_host_entity' => new EvaluationResult(
        'John Doe',
        (new CacheableMetadata())
          ->setCacheTags([
            // The host entity.
            'user:1',
          ])
          // Cache contexts added by host entity access checking.
          // @see \Drupal\canvas\PropExpressions\StructuredData\Evaluator::validateAccess()
          ->setCacheContexts(['user.permissions']),
      ),
      'expected_user_access_denied_message' => ["Access denied to entity while evaluating expression, ℹ︎␜entity:user␝name␞␟value, reason: The 'access user profiles' permission is required."],
      'expected_evaluation_with_node_host_entity' => NULL,
      'expected_node_access_denied_message' => NULL,
      'expected_dependencies_expression_only' => ['module' => ['user']],
      'expected_dependencies_with_host_entity' => ['module' => ['user']],
    ];

    yield "simple, with adapter: FieldPropExpression" => [
      'permissions' => ['access user profiles'],
      'expression' => 'ℹ︎␜entity:user␝created␞␟value',
      'adapter_plugin_id' => 'unix_to_date',
      'is_required' => TRUE,
      'expected_array_representation' => [
        'sourceType' => PropSource::EntityField->value,
        'expression' => 'ℹ︎␜entity:user␝created␞␟value',
        'adapter' => UnixTimestampToDateAdapter::PLUGIN_ID,
      ],
      'expected_expression_class' => FieldPropExpression::class,
      'expected_evaluation_with_user_host_entity' => new EvaluationResult(
        '1992-01-06',
        (new CacheableMetadata())
          ->setCacheTags([
            // The host entity.
            'user:1',
          ])
          // Cache contexts added by host entity access checking.
          // @see \Drupal\canvas\PropExpressions\StructuredData\Evaluator::validateAccess()
          ->setCacheContexts(['user.permissions']),
      ),
      'expected_user_access_denied_message' => ["Access denied to entity while evaluating expression, ℹ︎␜entity:user␝created␞␟value, reason: The 'access user profiles' permission is required."],
      'expected_evaluation_with_node_host_entity' => NULL,
      'expected_node_access_denied_message' => NULL,
      'expected_dependencies_expression_only' => [
        'module' => [
          'user',
          'canvas',
        ],
      ],
      'expected_dependencies_with_host_entity' => [
        'module' => [
          'user',
          'canvas',
        ],
      ],
    ];

    yield "simple, with adapter for optional (NULL) value: FieldPropExpression" => [
      'permissions' => ['access content'],
      'expression' => 'ℹ︎␜entity:node:page␝a_timestamp_maybe␞␟value',
      'adapter_plugin_id' => 'unix_to_date',
      'is_required' => FALSE,
      'expected_array_representation' => [
        'sourceType' => PropSource::EntityField->value,
        'expression' => 'ℹ︎␜entity:node:page␝a_timestamp_maybe␞␟value',
        'adapter' => UnixTimestampToDateAdapter::PLUGIN_ID,
      ],
      'expected_expression_class' => FieldPropExpression::class,
      'expected_evaluation_with_user_host_entity' => NULL,
      'expected_user_access_denied_message' => NULL,
      'expected_evaluation_with_node_host_entity' => new EvaluationResult(
        NULL,
        (new CacheableMetadata())
          ->setCacheTags([
            // The host entity.
            'node:1',
          ])
          // Cache contexts added by host entity access checking.
          // @see \Drupal\canvas\PropExpressions\StructuredData\Evaluator::validateAccess()
          ->setCacheContexts(['user.permissions']),
      ),
      'expected_node_access_denied_message' => ["Access denied to entity while evaluating expression, ℹ︎␜entity:node:page␝a_timestamp_maybe␞␟value, reason: The 'access content' permission is required."],
      'expected_dependencies_expression_only' => [
        'module' => [
          'node',
          'canvas',
        ],
        'config' => [
          'node.type.page',
          'field.field.node.page.a_timestamp_maybe',
        ],
      ],
      'expected_dependencies_with_host_entity' => [
        'module' => [
          'node',
          'canvas',
        ],
        'config' => [
          'node.type.page',
          'field.field.node.page.a_timestamp_maybe',
        ],
      ],
    ];

    yield "entity reference: FieldPropExpression using the `url` property, for a REQUIRED component prop" => [
      'permissions' => [
        // Grant access to the host entity.
        'access content',
        // Grant access to the referenced entity.
        'access user profiles',
      ],
      'expression' => 'ℹ︎␜entity:node:page␝uid␞␟url',
      'adapter_plugin_id' => NULL,
      'is_required' => TRUE,
      'expected_array_representation' => [
        'sourceType' => PropSource::EntityField->value,
        'expression' => 'ℹ︎␜entity:node:page␝uid␞␟url',
      ],
      'expected_expression_class' => FieldPropExpression::class,
      'expected_evaluation_with_user_host_entity' => NULL,
      'expected_user_access_denied_message' => NULL,
      'expected_evaluation_with_node_host_entity' => new EvaluationResult(
        '/user/1',
        (new CacheableMetadata())
          ->setCacheTags([
            // The host entity.
            'node:1',
            // The referenced entity.
            'user:1',
          ])
          // Cache contexts added by host entity access checking AND access
          // checks in the computed field property.
          // @see \Drupal\canvas\PropExpressions\StructuredData\Evaluator::validateAccess()
          // @see \Drupal\canvas\Plugin\DataType\ComputedEntityCanonicalRelativeUrl
          ->setCacheContexts(['user.permissions']),
      ),
      'expected_node_access_denied_message' => [
        // Exception due to host entity being inaccessible.
        "Access denied to entity while evaluating expression, ℹ︎␜entity:node:page␝uid␞␟url, reason: The 'access content' permission is required.",
        // Exception due to referenced entity being inaccessible.
        "Required field property empty due to entity or field access while evaluating expression ℹ︎␜entity:node:page␝uid␞␟url, reason: The 'access user profiles' permission is required.",
      ],
      'expected_dependencies_expression_only' => [
        'module' => ['node'],
        'config' => ['node.type.page'],
      ],
      'expected_dependencies_with_host_entity' => [
        'module' => ['node'],
        'config' => ['node.type.page'],
        'content' => [
          'user:user:881261cd-c9e2-4dcd-b0a8-1efa2e319a13',
        ],
      ],
    ];

    // In contrast with the above test case:
    // - the `access user profiles` permission is NOT granted, to simulate the
    //   referenced entity not being accessible to the current user
    // - the expected evaluation result is `NULL`, which is acceptable for an
    //   optional component prop
    yield "entity reference: FieldPropExpression using the `url` property, for an OPTIONAL component prop" => [
      'permissions' => [
        // Grant access to the host entity.
        'access content',
      ],
      'expression' => 'ℹ︎␜entity:node:page␝uid␞␟url',
      'adapter_plugin_id' => NULL,
      'is_required' => FALSE,
      'expected_array_representation' => [
        'sourceType' => PropSource::EntityField->value,
        'expression' => 'ℹ︎␜entity:node:page␝uid␞␟url',
      ],
      'expected_expression_class' => FieldPropExpression::class,
      'expected_evaluation_with_user_host_entity' => NULL,
      'expected_user_access_denied_message' => NULL,
      'expected_evaluation_with_node_host_entity' => new EvaluationResult(
        NULL,
        (new CacheableMetadata())
          ->setCacheTags([
            // The host entity.
            'node:1',
            // TRICKY: the tag for the referenced entity (`user:1`) is ABSENT
            // because it played no role in denying access.
            // @see \Drupal\user\UserAccessControlHandler::checkAccess()
          ])
          // Cache contexts added by host entity access checking AND access
          // checks in the computed field property.
          // @see \Drupal\canvas\PropExpressions\StructuredData\Evaluator::validateAccess()
          // @see \Drupal\canvas\Plugin\DataType\ComputedEntityCanonicalRelativeUrl
          // Cache contexts added by access checking.
          // @see \Drupal\canvas\Plugin\DataType\ComputedEntityCanonicalRelativeUrl
          ->setCacheContexts([
            'user',
            'user.permissions',
          ]),
      ),
      'expected_node_access_denied_message' => [
        // Exception due to host entity being inaccessible.
        "Access denied to entity while evaluating expression, ℹ︎␜entity:node:page␝uid␞␟url, reason: The 'access content' permission is required.",
      ],
      'expected_dependencies_expression_only' => [
        'module' => ['node'],
        'config' => ['node.type.page'],
      ],
      'expected_dependencies_with_host_entity' => [
        'module' => ['node'],
        'config' => ['node.type.page'],
        'content' => [
          'user:user:881261cd-c9e2-4dcd-b0a8-1efa2e319a13',
        ],
      ],
    ];

    yield "entity reference: ReferenceFieldPropExpression following the `entity` property" => [
      'permissions' => ['access content', 'access user profiles'],
      'expression' => 'ℹ︎␜entity:node:page␝uid␞␟entity␜␜entity:user␝name␞␟value',
      'adapter_plugin_id' => NULL,
      'is_required' => TRUE,
      'expected_array_representation' => [
        'sourceType' => PropSource::EntityField->value,
        'expression' => 'ℹ︎␜entity:node:page␝uid␞␟entity␜␜entity:user␝name␞␟value',
      ],
      'expected_expression_class' => ReferenceFieldPropExpression::class,
      'expected_evaluation_with_user_host_entity' => NULL,
      'expected_user_access_denied_message' => NULL,
      'expected_evaluation_with_node_host_entity' => new EvaluationResult(
        'John Doe',
        (new CacheableMetadata())
          ->setCacheTags([
            // The host entity.
            'node:1',
            // The referenced entity.
            'user:1',
          ])
          // Cache contexts added by host entity and referenced entity access
          // checking.
          // @see \Drupal\canvas\PropExpressions\StructuredData\Evaluator::validateAccess()
          ->setCacheContexts(['user.permissions']),
      ),
      'expected_node_access_denied_message' => [
        "Access denied to entity while evaluating expression, ℹ︎␜entity:node:page␝uid␞␟entity␜␜entity:user␝name␞␟value, reason: The 'access content' permission is required.",
        "Access denied to entity while evaluating expression, ℹ︎␜entity:user␝name␞␟value, reason: The 'access user profiles' permission is required.",
      ],
      'expected_dependencies_expression_only' => [
        'module' => ['node', 'user'],
        'config' => ['node.type.page'],
      ],
      'expected_dependencies_with_host_entity' => [
        'module' => ['node', 'user'],
        'config' => ['node.type.page'],
        'content' => [
          'user:user:881261cd-c9e2-4dcd-b0a8-1efa2e319a13',
        ],
      ],
    ];

    yield "complex object: FieldObjectPropsExpression containing a ReferenceFieldPropExpression" => [
      'permissions' => ['access content', 'access user profiles'],
      'expression' => 'ℹ︎␜entity:node:page␝uid␞␟{human_id↝entity␜␜entity:user␝name␞␟value,machine_id↠target_id}',
      'adapter_plugin_id' => NULL,
      'is_required' => TRUE,
      'expected_array_representation' => [
        'sourceType' => PropSource::EntityField->value,
        'expression' => 'ℹ︎␜entity:node:page␝uid␞␟{human_id↝entity␜␜entity:user␝name␞␟value,machine_id↠target_id}',
      ],
      'expected_expression_class' => FieldObjectPropsExpression::class,
      'expected_evaluation_with_user_host_entity' => NULL,
      'expected_user_access_denied_message' => NULL,
      'expected_evaluation_with_node_host_entity' => new EvaluationResult(
        [
          'human_id' => 'John Doe',
          'machine_id' => 1,
        ],
        (new CacheableMetadata())
          ->setCacheTags([
            // The host entity.
            'node:1',
            // The referenced entity.
            'user:1',
          ])
          // Cache contexts added by host entity and referenced entity access
          // checking.
          // @see \Drupal\canvas\PropExpressions\StructuredData\Evaluator::validateAccess()
          ->setCacheContexts(['user.permissions']),
      ),
      'expected_node_access_denied_message' => [
        "Access denied to entity while evaluating expression, ℹ︎␜entity:node:page␝uid␞␟{human_id↝entity␜␜entity:user␝name␞␟value,machine_id↠target_id}, reason: The 'access content' permission is required.",
        "Access denied to entity while evaluating expression, ℹ︎␜entity:user␝name␞␟value, reason: The 'access user profiles' permission is required.",
      ],
      'expected_dependencies_expression_only' => [
        'module' => ['node', 'user', 'node'],
        'config' => ['node.type.page', 'node.type.page'],
      ],
      'expected_dependencies_with_host_entity' => [
        'module' => ['node', 'user', 'node'],
        'config' => ['node.type.page', 'node.type.page'],
        'content' => [
          'user:user:881261cd-c9e2-4dcd-b0a8-1efa2e319a13',
        ],
      ],
    ];

    $expected_dependencies_expression = [
      'module' => [
        'node',
        'media',
        'media',
        'file',
        'file',
        'media',
        'file',
        'media',
        'file',
        'media',
        'file',
        'media',
        'file',
        'file',
        'media',
        'file',
        'media',
        'file',
        'media',
        'file',
        'media',
      ],
      'config' => [
        'node.type.page',
        'field.field.node.page.field_photos',
        'media.type.anything_is_possible',
        'media.type.image',
        'media.type.image_but_not_image_media_source',
        'media.type.anything_is_possible',
        'field.field.media.anything_is_possible.field_media_image_1',
        'image.style.canvas_parametrized_width',
        'media.type.anything_is_possible',
        'field.field.media.anything_is_possible.field_media_image_1',
        'media.type.anything_is_possible',
        'field.field.media.anything_is_possible.field_media_image_1',
        'media.type.anything_is_possible',
        'field.field.media.anything_is_possible.field_media_image_1',
        'media.type.image',
        'field.field.media.image.field_media_image',
        'image.style.canvas_parametrized_width',
        'media.type.image',
        'field.field.media.image.field_media_image',
        'media.type.image',
        'field.field.media.image.field_media_image',
        'media.type.image',
        'field.field.media.image.field_media_image',
        'media.type.image_but_not_image_media_source',
        'field.field.media.image_but_not_image_media_source.field_media_test',
      ],
    ];
    // The expression in the context of the `page` node, which surfaces content
    // dependencies because the `src_with_alternate_widths` property DOES
    // provide such dependencies.
    // Module dependencies are different from those for the expression, because
    // this includes those surfaced during evaluation of node 1.
    // @see \Drupal\canvas\Plugin\DataType\ComputedUrlWithQueryString
    $expected_node_1_expression_dependencies = [
      'module' => [
        'node',
        'media',
        'media',
        'file',
        'file',
        'media',
        'file',
        'media',
        'file',
        'media',
        'file',
        'media',
        'file',
        'file',
        'media',
        'file',
        'media',
        'file',
        'media',
        'file',
        'media',
      ],
      'config' => $expected_dependencies_expression['config'],
      'content' => [
        'media:anything_is_possible:' . self::IMAGE_MEDIA_UUID2,
        'file:file:' . self::FILE_UUID2,
      ],
    ];

    $per_media_type_specific_expression_branches = '[␜entity:media:anything_is_possible␝field_media_image_1␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}][␜entity:media:image␝field_media_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}][␜entity:media:image_but_not_image_media_source␝field_media_test␞␟{src↠value}]';
    yield "complex object: ReferenceFieldPropExpression with per-target bundle branches, for single delta (similar for single-cardinality field)" => [
      'permissions' => ['access content', 'view media'],
      'expression' => "ℹ︎␜entity:node:page␝field_photos␞0␟entity␜$per_media_type_specific_expression_branches",
      'adapter_plugin_id' => NULL,
      'is_required' => TRUE,
      'expected_array_representation' => [
        'sourceType' => PropSource::EntityField->value,
        'expression' => "ℹ︎␜entity:node:page␝field_photos␞0␟entity␜$per_media_type_specific_expression_branches",
      ],
      'expected_expression_class' => ReferenceFieldPropExpression::class,
      'expected_evaluation_with_user_host_entity' => NULL,
      'expected_user_access_denied_message' => NULL,
      'expected_evaluation_with_node_host_entity' => new EvaluationResult(
        [
          'src' => '::SITE_DIR_BASE_URL::/files/image-3.jpg?alternateWidths=::SITE_DIR_BASE_URL::' . UrlHelper::encodePath('/files/styles/canvas_parametrized_width--{width}/public/image-3.jpg.avif?itok=X5Qqzr53'),
          'alt' => 'amazing',
          'width' => 80,
          'height' => 60,
        ],
        (new CacheableMetadata())
          ->setCacheTags([
            // The host entity.
            'node:1',
            // The media entity being referenced by delta 0: of the media type
            // `anything_is_possible`.
            'media:2',
            // The entity used by the computed `src_with_alternate_widths` field
            // property.
            // @see \Drupal\canvas\Plugin\Field\FieldTypeOverride\ImageItemOverride::propertyDefinitions()
            // @see \Drupal\canvas\Plugin\DataType\ComputedUrlWithQueryString
            'file:2',
            // The parametrized image style used by the computed
            // `srcset_candidate_uri_template` field property, which is in turn
            // used by the above `src_with_alternate_widths` field property.
            // @see \Drupal\canvas\Plugin\Field\FieldTypeOverride\ImageItemOverride::propertyDefinitions()
            // @see \Drupal\canvas\TypedData\ImageDerivativeWithParametrizedWidth
            'config:image.style.canvas_parametrized_width',
          ])
          // Cache contexts added by host entity and referenced entity access
          // checking.
          // @see \Drupal\canvas\PropExpressions\StructuredData\Evaluator::validateAccess()
          ->setCacheContexts(['user.permissions']),
      ),
      'expected_node_access_denied_message' => [
        "Access denied to entity while evaluating expression, ℹ︎␜entity:node:page␝field_photos␞0␟entity␜$per_media_type_specific_expression_branches, reason: The 'access content' permission is required.",
        // 💡 This illustrates which one of the three branches is evaluated.
        "Access denied to entity while evaluating expression, ℹ︎␜entity:media:anything_is_possible␝field_media_image_1␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}, reason: The 'view media' permission is required when the media item is published.",
      ],
      'expected_dependencies_expression_only' => $expected_dependencies_expression,
      'expected_dependencies_with_host_entity' => $expected_node_1_expression_dependencies,
    ];
    yield "complex object: ReferenceFieldPropExpression with per-target bundle branches, for all deltas" => [
      'permissions' => ['access content', 'view media'],
      'expression' => "ℹ︎␜entity:node:page␝field_photos␞␟entity␜$per_media_type_specific_expression_branches",
      'adapter_plugin_id' => NULL,
      'is_required' => TRUE,
      'expected_array_representation' => [
        'sourceType' => PropSource::EntityField->value,
        'expression' => "ℹ︎␜entity:node:page␝field_photos␞␟entity␜$per_media_type_specific_expression_branches",
      ],
      'expected_expression_class' => ReferenceFieldPropExpression::class,
      'expected_evaluation_with_user_host_entity' => NULL,
      'expected_user_access_denied_message' => NULL,
      'expected_evaluation_with_node_host_entity' => new EvaluationResult(
        [
          [
            'src' => '::SITE_DIR_BASE_URL::/files/image-3.jpg?alternateWidths=::SITE_DIR_BASE_URL::' . UrlHelper::encodePath('/files/styles/canvas_parametrized_width--{width}/public/image-3.jpg.avif?itok=X5Qqzr53'),
            'alt' => 'amazing',
            'width' => 80,
            'height' => 60,
          ],
          [
            'src' => '::SITE_DIR_BASE_URL::/files/image-2.jpg?alternateWidths=::SITE_DIR_BASE_URL::' . UrlHelper::encodePath('/files/styles/canvas_parametrized_width--{width}/public/image-2.jpg.avif?itok=IeQvQSDi'),
            'alt' => 'An image so amazing that to gaze upon it would melt your face',
            'width' => 80,
            'height' => 60,
          ],
          [
            'src' => 'Jack is awesome!',
          ],
        ],
        (new CacheableMetadata())
          ->setCacheTags([
            // The host entity.
            'node:1',
            // All referenced media entities.
            'media:2',
            'media:1',
            'media:3',
            // The entities used by the 2 computed `src_with_alternate_widths`
            // field properties: those for the `image` Media and the
            // `anything_is_possible` Media.
            // The `image_but_not_image_media_source` Media type does not use
            // File entities.
            // @see \Drupal\canvas\Plugin\Field\FieldTypeOverride\ImageItemOverride::propertyDefinitions()
            // @see \Drupal\canvas\Plugin\DataType\ComputedUrlWithQueryString
            'file:2',
            'file:1',
            // The parametrized image style used by the computed
            // `srcset_candidate_uri_template` field property, which is in turn
            // used by the above `src_with_alternate_widths` field property.
            // @see \Drupal\canvas\Plugin\Field\FieldTypeOverride\ImageItemOverride::propertyDefinitions()
            // @see \Drupal\canvas\TypedData\ImageDerivativeWithParametrizedWidth
            'config:image.style.canvas_parametrized_width',
          ])
          // Cache contexts added by host entity and referenced entity access
          // checking.
          // @see \Drupal\canvas\PropExpressions\StructuredData\Evaluator::validateAccess()
          ->setCacheContexts(['user.permissions']),
      ),
      'expected_node_access_denied_message' => [
        "Access denied to entity while evaluating expression, ℹ︎␜entity:node:page␝field_photos␞␟entity␜$per_media_type_specific_expression_branches, reason: The 'access content' permission is required.",
        // 💡 This illustrates which one of the three branches is evaluated
        // FIRST: the first referenced entity. Once the `view media` permission
        // is granted, the subsequent 2 references can be resolved, too.
        "Access denied to entity while evaluating expression, ℹ︎␜entity:media:anything_is_possible␝field_media_image_1␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}, reason: The 'view media' permission is required when the media item is published.",
      ],
      'expected_dependencies_expression_only' => $expected_dependencies_expression,
      // Unlike the above test case, the one below will evaluate ALL deltas in the
      // given entity field, so these additional dependencies arise.
      'expected_dependencies_with_host_entity' => [
        'module' => [
          ...$expected_node_1_expression_dependencies['module'],
          'media',
          'file',
          'file',
          'media',
          'file',
          'media',
          'file',
          'media',
          'file',
          'media',
          'file',
          'file',
          'media',
          'file',
          'media',
          'file',
          'media',
          'file',
          'media',
          'media',
          'file',
          'file',
          'media',
          'file',
          'media',
          'file',
          'media',
          'file',
          'media',
          'file',
          'file',
          'media',
          'file',
          'media',
          'file',
          'media',
          'file',
          'media',
        ],
        'config' => [
          ...$expected_node_1_expression_dependencies['config'],
          'media.type.anything_is_possible',
          'field.field.media.anything_is_possible.field_media_image_1',
          'image.style.canvas_parametrized_width',
          'media.type.anything_is_possible',
          'field.field.media.anything_is_possible.field_media_image_1',
          'media.type.anything_is_possible',
          'field.field.media.anything_is_possible.field_media_image_1',
          'media.type.anything_is_possible',
          'field.field.media.anything_is_possible.field_media_image_1',
          'media.type.image',
          'field.field.media.image.field_media_image',
          'image.style.canvas_parametrized_width',
          'media.type.image',
          'field.field.media.image.field_media_image',
          'media.type.image',
          'field.field.media.image.field_media_image',
          'media.type.image',
          'field.field.media.image.field_media_image',
          'media.type.image_but_not_image_media_source',
          'field.field.media.image_but_not_image_media_source.field_media_test',
          'media.type.anything_is_possible',
          'field.field.media.anything_is_possible.field_media_image_1',
          'image.style.canvas_parametrized_width',
          'media.type.anything_is_possible',
          'field.field.media.anything_is_possible.field_media_image_1',
          'media.type.anything_is_possible',
          'field.field.media.anything_is_possible.field_media_image_1',
          'media.type.anything_is_possible',
          'field.field.media.anything_is_possible.field_media_image_1',
          'media.type.image',
          'field.field.media.image.field_media_image',
          'image.style.canvas_parametrized_width',
          'media.type.image',
          'field.field.media.image.field_media_image',
          'media.type.image',
          'field.field.media.image.field_media_image',
          'media.type.image',
          'field.field.media.image.field_media_image',
          'media.type.image_but_not_image_media_source',
          'field.field.media.image_but_not_image_media_source.field_media_test',
        ],
        'content' => [
          'media:anything_is_possible:' . self::IMAGE_MEDIA_UUID2,
          'media:image:' . self::IMAGE_MEDIA_UUID1,
          'media:image_but_not_image_media_source:' . self::TEST_MEDIA,
          'file:file:' . self::FILE_UUID2,
          'file:file:' . self::FILE_UUID1,
        ],
      ],
    ];
  }

  public static function providerInvalidDueToDelta(): iterable {
    yield [
      "ℹ︎␜entity:user␝name␞␟value",
      NULL,
      "John Doe",
      (new CacheableMetadata())->setCacheContexts(['user.permissions']),
    ];
    yield [
      "ℹ︎␜entity:user␝name␞0␟value",
      NULL,
      "John Doe",
      (new CacheableMetadata())->setCacheContexts(['user.permissions']),
    ];
    yield [
      "ℹ︎␜entity:user␝name␞-1␟value",
      "Requested delta -1, but deltas must be positive integers.",
      "💩",
      (new CacheableMetadata()),
    ];
    yield [
      "ℹ︎␜entity:user␝name␞5␟value",
      "Requested delta 5 for single-cardinality field, must be either zero or omitted.",
      "💩",
      (new CacheableMetadata()),
    ];
    yield [
      "ℹ︎␜entity:user␝roles␞␟target_id",
      NULL,
      ["test_role_a", "test_role_b"],
      (new CacheableMetadata())->setCacheContexts(['user.permissions']),
    ];
    yield [
      "ℹ︎␜entity:user␝roles␞0␟target_id",
      NULL,
      "test_role_a",
      (new CacheableMetadata())->setCacheContexts(['user.permissions']),
    ];
    yield [
      "ℹ︎␜entity:user␝roles␞1␟target_id",
      NULL,
      "test_role_b",
      (new CacheableMetadata())->setCacheContexts(['user.permissions']),
    ];
    yield [
      "ℹ︎␜entity:user␝roles␞5␟target_id",
      "Requested delta 5 for unlimited cardinality field, but only deltas [0, 1] exist.",
      "💩",
      (new CacheableMetadata()),
    ];
    yield [
      "ℹ︎␜entity:user␝roles␞-1␟target_id",
      "Requested delta -1, but deltas must be positive integers.",
      "💩",
      (new CacheableMetadata()),
    ];
  }

  /**
   * Tests invalid entity field prop source field prop expression due to delta.
   *
   * @legacy-covers \Drupal\canvas\PropExpressions\StructuredData\Evaluator
   */
  #[DataProvider('providerInvalidDueToDelta')]
  public function testInvalidDueToDelta(string $expression, ?string $expected_message, mixed $expected_value, CacheableMetadata $expected_cacheability): void {
    $this->setUpCurrentUser(permissions: ['administer permissions', 'access user profiles', 'administer users']);
    Role::create(['id' => 'test_role_a', 'label' => 'Test role A'])->save();
    Role::create(['id' => 'test_role_b', 'label' => 'Test role B'])->save();
    $user = User::create([
      'name' => 'John Doe',
      'roles' => [
        'test_role_a',
        'test_role_b',
      ],
    ])->activate();

    // @phpstan-ignore-next-line argument.type
    $entity_field_prop_source_delta_test = new EntityFieldPropSource(StructuredDataPropExpression::fromString($expression));

    if ($expected_message !== NULL) {
      $this->expectException(\LogicException::class);
      $this->expectExceptionMessage($expected_message);
    }

    $evaluation_result = $entity_field_prop_source_delta_test->evaluate($user, is_required: TRUE);
    self::assertSame($expected_value, $evaluation_result->value);
    self::assertSame($expected_cacheability->getCacheTags(), $evaluation_result->getCacheTags());
    self::assertSame($expected_cacheability->getCacheContexts(), $evaluation_result->getCacheContexts());
    self::assertSame($expected_cacheability->getCacheMaxAge(), $evaluation_result->getCacheMaxAge());
  }

  /**
   * Tests invalid entity field prop source due to missing adapter.
   *
   * @legacy-covers \Drupal\canvas\PropSource\EntityFieldPropSource::withAdapter
   * @legacy-covers \Drupal\canvas\PropSource\EntityFieldPropSource::parse
   */
  public function testInvalidDueToMissingAdapter(): void {
    $this->expectException(PluginNotFoundException::class);
    $this->expectExceptionMessage('The "unix_to_date_oops_I_have_been_renamed" plugin does not exist.');

    EntityFieldPropSource::parse([
      'sourceType' => PropSource::EntityField->value,
      'expression' => 'ℹ︎␜entity:user␝created␞␟value',
      'adapter' => 'unix_to_date_oops_I_have_been_renamed',
    ]);
  }

  /**
   * Tests dynamic prefix is transformed on load.
   *
   * @see \Drupal\canvas\PropSource\PropSource::Dynamic
   * @legacy-covers \Drupal\canvas\PropSource\PropSource::parse
   */
  #[IgnoreDeprecations]
  public function testDynamicPrefixIsTransformedOnLoad(): void {
    $this->expectDeprecation('The "dynamic" prop source was renamed to "entity field" is deprecated in canvas:1.2.0 and will be removed from canvas:2.0.0. Re-save (and re-export) all Canvas content templates. See https://www.drupal.org/node/3566701');
    $prop_source = PropSource::parse([
      'sourceType' => PropSource::Dynamic->value,
      'expression' => "ℹ︎␜entity:user␝name␞␟value",
    ]);
    self::assertInstanceOf(EntityFieldPropSource::class, $prop_source);
  }

}
