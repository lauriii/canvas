<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\Component\Datetime\Time;
use Drupal\content_moderation\Permissions;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityConstraintViolationList;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldWidget\StringTextfieldWidget;
use Drupal\Core\Field\WidgetPluginManager;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\experience_builder\ClientDataToEntityConverter;
use Drupal\experience_builder\Exception\ConstraintViolationException;
use Drupal\experience_builder\Controller\EntityFormTrait;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;
use Drupal\Tests\experience_builder\TestSite\XBTestSetup;
use Drupal\Tests\experience_builder\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\experience_builder\Traits\XBFieldTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use GuzzleHttp\Psr7\Query;
use Drupal\user\RoleInterface;

class ClientDataToEntityConverterTest extends KernelTestBase {

  use XBFieldTrait {
    getValidClientJson as traitGetValidClientJson;
  }
  use ConstraintViolationsTestTrait;
  use UserCreationTrait;
  use EntityFormTrait;
  use ContentModerationTestTrait;

  private User $otherUser;

  public function setUp(): void {
    parent::setUp();
    $this->container->get('module_installer')->install(['system']);
    (new XBTestSetup())->setup();
    $this->setUpImages();
    $other_user = $this->createUser();
    assert($other_user instanceof User);
    $this->otherUser = $other_user;
  }

  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $definition = $container->getDefinition('plugin.manager.field.widget');
    $definition->setClass(TestWidgetManager::class);
    $container->setDefinition('plugin.manager.field.widget', $definition);
    $container->getDefinition('datetime.time')
      ->setClass(TestTime::class);
  }

  /**
   * {@inheritdoc}
   */
  private function getValidClientJson(bool $dynamic_image = TRUE): array {
    $json = $this->traitGetValidClientJson($dynamic_image);
    $content_region = \array_values(\array_filter($json['layout'], static fn(array $region) => $region['id'] === 'content'));
    return [
      'layout' => reset($content_region),
      'model' => $json['model'],
      'entity_form_fields' => $json['entity_form_fields'],
    ];
  }

  /**
   * @testWith [false]
   *           [true]
   */
  public function testConvert(bool $with_content_moderation = FALSE): void {
    if ($with_content_moderation) {
      $this->container->get(ModuleInstallerInterface::class)->install(['content_moderation']);
      $workflow = $this->createEditorialWorkflow();
      $this->addEntityTypeAndBundleToWorkflow($workflow, 'node', 'article');
      $permissions = \array_keys(\Drupal::classResolver(Permissions::class)->transitionPermissions());
      $xb_role = Role::load('xb');
      \assert($xb_role instanceof RoleInterface);
      foreach ($permissions as $permission) {
        $xb_role->grantPermission($permission)->save();
      }
    }
    $account = $this->createUser(values: [
      'roles' => [
        'xb',
      ],
    ]);
    \assert($account instanceof AccountInterface);
    $this->setCurrentUser($account);
    $valid_client_json = $this->getValidClientJson(FALSE);
    // The client may not filter out form inputs that are not entity fields.
    $valid_client_json['entity_form_fields']['field_image_0_upload_button'] = 'Not an entity field input';
    $this->assertConvert(
      $valid_client_json,
      [],
      'The updated title.'
    );

    $single_propless_component_client_json = $valid_client_json;
    $single_propless_component_client_json['layout']['components'] = [
      [
        'nodeType' => 'component',
        'uuid' => '4ad36179-a9bd-4bc8-8a4a-241e73dbed25',
        'type' => 'sdc.experience_builder.druplicon',
        'slots' => [],
      ],
    ];
    $single_propless_component_client_json['model'] = [
      '4ad36179-a9bd-4bc8-8a4a-241e73dbed25' => [],
    ];
    $this->assertConvert(
      $single_propless_component_client_json,
      [],
      'The updated title.'
    );

    $unreferenced_file_client_json = $valid_client_json;
    $unreferenced_src = $this->getSrcPropertyFromFile($this->unreferencedImage);
    $unreferenced_file_client_json['model'][self::TEST_IMAGE_UUID]['resolved']['image']['src'] = $unreferenced_src;
    $suffix = '';
    if (\version_compare(\Drupal::VERSION, '11.1.2', '>=')) {
      // The format of component violation messages changed in Drupal 11.1.2.
      // @see https://drupal.org/i/3462700
      $suffix = '.';
    }
    $this->assertConvert(
      $unreferenced_file_client_json,
      [
        // Transformation from client model to input failed.
        // @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\SingleDirectoryComponent::findTargetForProps()
        'model.' . self::TEST_IMAGE_UUID . '.image.src' => "No media entity found that uses file '$unreferenced_src'.",
        // The failed transformation above results in an empty value for the
        // entire SDC prop. Which then fails SDC validation.
        // @see \Drupal\Core\Theme\Component\ComponentValidator::validateProps()
        'model.' . self::TEST_IMAGE_UUID . '.image' => 'The property image is required' . $suffix,
      ],
      // The error above happens in `\Drupal\experience_builder\Controller\ClientServerConversionTrait::convertClientToServer()`
      // therefore the title, as well as other entity fields will not be updated.
      'The original title.'
    );

    $invalid_heading_client_json = $valid_client_json;
    $invalid_heading_client_json['model'][self::TEST_HEADING_UUID]['resolved']['style'] = 'not-a-style';
    $suffix = '';
    if (\version_compare(\Drupal::VERSION, '11.1.2', '>=')) {
      // The format of component violation messages changed in Drupal 11.1.2.
      // @see https://drupal.org/i/3462700
      $suffix = '. The provided value is: "not-a-style".';
    }
    $this->assertConvert(
      $invalid_heading_client_json,
      ['model.' . self::TEST_HEADING_UUID . '.style' => 'Does not have a value in the enumeration ["primary","secondary"]' . $suffix],
      // The error above happens in `\Drupal\experience_builder\Controller\ClientServerConversionTrait::convertClientToServer()`
      // therefore the title, as well as other entity fields will not be updated.
      'The original title.',
    );

    $invalid_missing_heading_props_client_json = $valid_client_json;
    unset($invalid_missing_heading_props_client_json['model'][self::TEST_HEADING_UUID]);
    $this->assertConvert(
      $invalid_missing_heading_props_client_json,
      ['model.' . self::TEST_HEADING_UUID => 'The required properties are missing.'],
      'The updated title.',
    );

    // If the client tries to update a field the user does not have access to edit, the field should remain unchanged.
    $permissions = ['access administration pages'];
    if ($with_content_moderation) {
      $permissions[] = 'use editorial transition create_new_draft';
    }
    $this->setupCurrentUser([], $permissions);
    $limited_user = \Drupal::currentUser();
    $limited_user_id = $limited_user->id();
    $new_author = \sprintf('%s (%d)', $limited_user->getDisplayName(), $limited_user_id);
    $test_node = $this->createTestNode();
    $this->assertFalse($test_node->get('sticky')->access('edit'));
    $this->assertTrue($test_node->get('sticky')->access('view'));
    $this->assertFalse($test_node->isSticky());
    $invalid_field_access_client_json = $valid_client_json;
    $invalid_field_access_client_json['entity_form_fields']['sticky[value]'] = TRUE;
    $this->assertConvert(
      $invalid_field_access_client_json,
      [],
      'The updated title.',
      $test_node
    );
    // The field value should remain unchanged.
    $this->assertFalse($test_node->isSticky());

    // If the client sends a field the user does not have access to edit, but the field value is the same as the current value no violation should be returned.
    $no_field_access_field_unchanged_client_json = $valid_client_json;
    $no_field_access_field_unchanged_client_json['entity_form_fields']['sticky[value]'] = FALSE;
    $test_node = $this->createTestNode();
    $this->assertFalse($test_node->get('sticky')->access('edit'));
    $this->assertTrue($test_node->get('sticky')->access('view'));
    $this->assertFalse($test_node->isSticky());
    $this->assertConvert(
      $no_field_access_field_unchanged_client_json,
      [],
      'The updated title.',
      $test_node
    );
    // The field value should remain unchanged.
    $this->assertFalse($test_node->isSticky());

    // If the client has elevated permissions, they can update protected fields.
    $permissions = ['administer nodes'];
    if ($with_content_moderation) {
      $permissions[] = 'use editorial transition create_new_draft';
    }
    $this->setupCurrentUser([], $permissions);
    $test_node = $this->createTestNode();
    self::assertTrue($test_node->get('sticky')->access('edit'));
    self::assertTrue($test_node->get('sticky')->access('view'));
    self::assertFalse($test_node->isSticky());
    self::assertEquals(3, (int) $test_node->getOwnerId());
    self::assertNotEquals(3, $limited_user->id());
    $protected_field_updated_json = $valid_client_json;
    $protected_field_updated_json['entity_form_fields']['sticky[value]'] = TRUE;
    // Test a form element that is more complex and features a validate callback
    // that changes the form value - e.g. EntityAutocomplete element.
    // @see \Drupal\Core\Entity\Element\EntityAutocomplete::validateEntityAutocomplete
    $protected_field_updated_json['entity_form_fields']['uid[0][target_id]'] = $new_author;
    $this->assertConvert(
      $protected_field_updated_json,
      [],
      'The updated title.',
      $test_node
    );
    self::assertTrue($test_node->isSticky());
    self::assertGreaterThan(0, $limited_user->id());
    self::assertEquals($limited_user_id, (int) $test_node->getOwnerId());

    // Ensure that the entity values are passed through the widget.
    $modify_title_client_json = $valid_client_json;
    $modify_title_client_json['entity_form_fields']['title[0][value]'] = 'Hey widget, modify me!';
    $this->assertConvert(
      $modify_title_client_json,
      [],
      'Modified!',
    );

    // @todo Test case where the user does not have access to view the field.
    //   Right now this is tricky because field access does not take into account
    //   entity access.
    $test_node = $this->createTestNode();
    // 🔥 Field access does not take into account parent entity access, i.e. you
    // edit the field but not the entity🤔.
    // Fix in https://drupal.org/i/3494915
    $this->assertTrue((!$test_node->access('edit')) && $test_node->get('title')->access('edit'));
  }

  protected function assertConvert(array $client_json, array $expected_errors, string $expected_title, ?Node $node = NULL): void {
    $node = $node ?? $this->createTestNode();
    // \Drupal\experience_builder\ClientDataToEntityConverter::convert() will
    // automatically update the `changed` field because it creates a form object
    // submits the form. We ensure the request time will be greater than the original
    // node 'changed' time.
    // @see \Drupal\Core\Entity\ContentEntityForm::updateChangedTime().
    // @see \Drupal\experience_builder\ClientDataToEntityConverter::checkPatchFieldAccess().
    TestTime::$offset += 1;
    $original_node_changed = $node->getChangedTime();
    // Set entity fields to ensure the client will be able to send unchanged
    // fields.
    $form = \Drupal::entityTypeManager()->getFormObject($node->getEntityTypeId(), 'default');
    $form_state = $this->buildFormState($form, $node, 'default');
    \Drupal::formBuilder()->buildForm($form, $form_state);
    $node_values = \array_reduce(\array_filter($node->getFields(), static fn(FieldItemListInterface $field) => !$field->isEmpty()), static fn(array $carry, FieldItemListInterface $field) => [
      ...$carry,
      $field->getName() => $field->getValue(),
    ], []);
    unset($node_values['field_xb_demo']);
    if (!\Drupal::currentUser()->hasPermission('create url aliases')) {
      unset($node_values['path']);
    }
    try {
      if (!\Drupal::currentUser()->hasPermission('administer nodes')) {
        unset($node_values['created']);
      }
      $values = Query::parse(\http_build_query(\array_intersect_key($form_state->getValues(), $node_values)));
      $client_json['entity_form_fields'] += $values;
      \parse_str(\http_build_query(\array_diff_key($values, $client_json['entity_form_fields'])), $unchanged_fields);
      $this->container->get(ClientDataToEntityConverter::class)->convert($client_json, $node);
      self::assertCount(0, $expected_errors);
      // If no violations occurred, the node should be valid.
      $this->assertCount(0, $node->validate());
      $this->assertSame(SAVED_UPDATED, $node->save());
      $this->assertGreaterThan($original_node_changed, $node->getChangedTime());
    }
    catch (ConstraintViolationException $e) {
      $violations = $e->getConstraintViolationList();
      $this->assertInstanceOf(EntityConstraintViolationList::class, $violations);
      $this->assertSame($node->id(), $violations->getEntity()->id());
      $this->assertSame($expected_errors, self::violationsToArray($violations));
    }
    $this->assertSame($expected_title, (string) $node->getTitle());

    // Ensure the unchanged fields are not updated.
    // TRICKY: We can't directly compare `$client_json['entity_form_fields'][$field_name]`
    // to `$node->get($field_name)->getValue()` because after fields have been
    // set the type of values seem to change. For example, 'status' changes
    // from 0 to false and timestamps change from int to string. Therefore, we
    // need to duplicate the node which allows us to compare the values using
    // \Drupal\Core\Field\FieldItemListInterface::equals() which will handle
    // these differences.
    $cloned = $node->createDuplicate();
    foreach ($unchanged_fields as $field_name) {
      \assert(\is_string($field_name));
      $cloned->get($field_name)->setValue($client_json['entity_form_fields'][$field_name]);
      if ($field_name === 'vid' && \Drupal::moduleHandler()->moduleExists('content_moderation')) {
        // Content moderation forces a new revision and hence the revision ID
        // will be incremented.
        self::assertGreaterThan((int) $client_json['entity_form_fields'][$field_name], (int) $node->getRevisionId());
        continue;
      }
      $this->assertTrue($cloned->get($field_name)->equals($node->get($field_name)), "The field '$field_name' was not updated.");
    }
  }

  protected function createTestNode(): Node {
    $node = Node::create([
      'status' => FALSE,
      'uid' => $this->otherUser->id(),
      'type' => 'article',
      'title' => 'The original title.',
      'field_xb_demo' => [
        'tree' => json_encode([
          ComponentTreeStructure::ROOT_UUID => [],
        ]),
        'inputs' => '{}',
      ],
      'revision_log' => [
        [
          'value' => 'Initial revision.',
        ],
      ],
    ]);
    assert($node instanceof Node);
    $this->assertSame(SAVED_NEW, $node->save());
    return $node;
  }

}

class TestWidgetManager extends WidgetPluginManager {

  protected function findDefinitions() {
    $definitions = parent::findDefinitions();
    if (isset($definitions['string_textfield'])) {
      $definitions['string_textfield']['class'] = TestStringTextfieldWidget::class;
    }
    return $definitions;
  }

}

class TestStringTextfieldWidget extends StringTextfieldWidget {

  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    $values = parent::massageFormValues($values, $form, $form_state);
    if ($values[0]['value'] === 'Hey widget, modify me!') {
      $values[0]['value'] = 'Modified!';
    }
    return $values;
  }

}

/**
 * A test-only implementation of the time service.
 */
class TestTime extends Time {

  /**
   * An offset to add to the request time.
   *
   * @var int
   */
  public static int $offset = 0;

  /**
   * {@inheritdoc}
   */
  public function getRequestTime() {
    return parent::getRequestTime() + static::$offset;
  }

}
