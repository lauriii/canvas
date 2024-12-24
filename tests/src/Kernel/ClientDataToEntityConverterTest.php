<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldWidget\StringTextfieldWidget;
use Drupal\Core\Field\WidgetPluginManager;
use Drupal\Core\Form\FormStateInterface;
use Drupal\experience_builder\ClientDataToEntityConverter;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\Tests\experience_builder\TestSite\XBTestSetup;
use Drupal\Tests\experience_builder\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\experience_builder\Traits\XBFieldTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;

class ClientDataToEntityConverterTest extends KernelTestBase {

  use XBFieldTrait {
    getValidClientJson as traitGetValidClientJson;
  }
  use ConstraintViolationsTestTrait;
  use UserCreationTrait;

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
  }

  /**
   * {@inheritdoc}
   */
  private function getValidClientJson(): array {
    $json = $this->traitGetValidClientJson();
    $content_region = \array_values(\array_filter($json['layout'], static fn(array $region) => $region['id'] === 'content'));
    return [
      'layout' => reset($content_region),
      'model' => $json['model'],
      'entity_form_fields' => $json['entity_form_fields'],
    ];
  }

  public function testConvert(): void {
    $valid_client_json = $this->getValidClientJson();
    $this->assertConvert(
      $valid_client_json,
      [],
      'The updated title.'
    );

    $unreferenced_file_client_json = $valid_client_json;
    $unreferenced_src = $this->getSrcPropertyFromFile($this->unreferencedImage);
    $unreferenced_file_client_json['model'][self::TEST_IMAGE_UUID]['image']['src'] = $unreferenced_src;
    $this->assertConvert(
      $unreferenced_file_client_json,
      ['model.' . self::TEST_IMAGE_UUID . '.image.src' => "No media entity found that uses file '$unreferenced_src'."],
      // The error above happens in `\Drupal\experience_builder\Controller\ClientServerConversionTrait::convertClientToServer()`
      // therefore the title, as well as other entity fields will not be updated.
      'The original title.'
    );

    $invalid_heading_client_json = $valid_client_json;
    $invalid_heading_client_json['model'][self::TEST_HEADING_UUID]['style'] = 'not-a-style';
    $this->assertConvert(
      $invalid_heading_client_json,
      ['model.' . self::TEST_HEADING_UUID . '.style' => 'Does not have a value in the enumeration ["primary","secondary"]'],
      'The updated title.',
    );

    $invalid_missing_heading_props_client_json = $valid_client_json;
    unset($invalid_missing_heading_props_client_json['model'][self::TEST_HEADING_UUID]);
    $this->assertConvert(
      $invalid_missing_heading_props_client_json,
      ['model.' . self::TEST_HEADING_UUID => 'The required properties are missing.'],
      'The updated title.',
    );

    // If the client tries to update a field the user does not have access to edit, the violation should be returned.
    $this->setupCurrentUser([], ['access administration pages']);
    $test_node = $this->createTestNode();
    $this->assertFalse($test_node->get('sticky')->access('edit'));
    $this->assertTrue($test_node->get('sticky')->access('view'));
    $this->assertFalse($test_node->isSticky());
    $invalid_field_access_client_json = $valid_client_json;
    $invalid_field_access_client_json['entity_form_fields']['sticky'] = [
      [
        'value' => TRUE,
      ],
    ];
    $this->assertConvert(
      $invalid_field_access_client_json,
      ['entity_form_fields.sticky' => "The current user is not allowed to update the field 'sticky'."],
      'The updated title.',
      $test_node
    );

    // If the client sends a field the user does not have access to edit, but the field value is the same as the current value no violation should be returned.
    $no_field_access_field_unchanged_client_json = $valid_client_json;
    $no_field_access_field_unchanged_client_json['entity_form_fields']['sticky'] = [
      [
        'value' => FALSE,
      ],
    ];
    $this->assertConvert(
      $no_field_access_field_unchanged_client_json,
      [],
      'The updated title.',
      $this->createTestNode()
    );

    // Ensure that the entity values are passed through the widget.
    $modify_title_client_json = $valid_client_json;
    $modify_title_client_json['entity_form_fields']['title'] = [
      [
        'value' => 'Hey widget, modify me!',
      ],
    ];
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
    // Set entity fields to ensure the client will be able to send unchanged
    // fields.
    $unchanged_fields = [];
    foreach ($node->getFields() as $field) {
      assert($field instanceof FieldItemListInterface);
      $field_name = $field->getName();
      if ($field_name === 'field_xb_demo' ||
        ($field_name === 'created' && !\Drupal::currentUser()->hasPermission('administer nodes'))) {
        continue;
      }
      if (!isset($client_json['entity_form_fields'][$field_name])) {
        $client_json['entity_form_fields'][$field_name] = $node->get($field_name)->getValue();
        if ($field_name !== 'revision_timestamp') {
          $unchanged_fields[] = $field_name;
        }
      }
    }
    $violations = $this->container->get(ClientDataToEntityConverter::class)->convert($client_json, $node);
    $this->assertSame($node->id(), $violations->getEntity()->id());
    $this->assertSame($expected_errors, self::violationsToArray($violations));
    $this->assertSame($expected_title, (string) $node->getTitle());
    if ($violations->count() === 0) {
      // If no violations occurred, the node should be valid.
      $this->assertCount(0, $node->validate());
      $this->assertSame(SAVED_UPDATED, $node->save());
    }

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
      $cloned->get($field_name)->setValue($client_json['entity_form_fields'][$field_name]);
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
        'props' => '{}',
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
