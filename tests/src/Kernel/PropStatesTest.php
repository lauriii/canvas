<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemListInstantiatorTrait;
use Drupal\Tests\canvas\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that the `x-canvas-states` prop vocabulary is structurally safe.
 *
 * The vocabulary is evaluated exclusively client-side, so the server must:
 * - accept it when creating a Component config entity from an SDC,
 * - deliver it unchanged in the client-side info, and
 * - ignore it when validating component input values.
 *
 * @see docs/client-side-widgets.md
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class PropStatesTest extends CanvasKernelTestBase {

  use ComponentTreeItemListInstantiatorTrait;
  use ConstraintViolationsTestTrait;
  use GenerateComponentConfigTrait;

  private const COMPONENT_ID = 'sdc.canvas_test_prop_states.prop-states';

  private const UUID = 'a5f97062-9b8b-4d09-9273-478dfa4b77c1';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    // Provides the `prop-states` SDC, whose `text` prop declares
    // `x-canvas-states` rules.
    'canvas_test_prop_states',
  ];

  /**
   * Tests that `x-canvas-states` passes discovery, delivery, and validation.
   */
  public function testPropStatesVocabularyIsStructurallySafe(): void {
    $this->generateComponentConfig();

    // The Component config entity is created from the SDC: shape matching and
    // config schema validation (strict in kernel tests) accept the
    // `x-canvas-states` key.
    $component = Component::load(self::COMPONENT_ID);
    self::assertInstanceOf(Component::class, $component);
    self::assertSame([], self::violationsToArray($component->getTypedData()->validate()));
    self::assertSame('string_textfield', $component->getSettings()['prop_field_definitions']['text']['field_widget']);

    // The delivered client-side JSON schema still carries the key: the schema
    // simplification pipeline only strips `meta:enum`, `x-translation-context`,
    // `id`, and `$id`.
    // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::simplifySchemaForAjvClient()
    $client_side_info = $component->getComponentSource()->getClientSideInfo($component);
    $text_schema = $client_side_info['propSources']['text']['jsonSchema'];
    self::assertSame([
      [
        'effect' => 'visible',
        'when' => [
          'type' => 'object',
          'properties' => [
            'show_text' => ['const' => TRUE],
          ],
          'required' => ['show_text'],
        ],
      ],
    ], $text_schema['x-canvas-states']);
    // Aside from the vocabulary and the authored title annotation, the
    // delivered schema is a plain string schema.
    self::assertSame(['type' => 'string', 'title' => 'Text'], \array_diff_key($text_schema, \array_flip(['x-canvas-states'])));

    // Server-side input validation ignores the vocabulary: a component
    // instance with valid prop values validates cleanly, exactly like a
    // component without the key.
    $item_list = $this->createDanglingComponentTreeItemList();
    $item_list->setValue([
      [
        'uuid' => self::UUID,
        'component_id' => self::COMPONENT_ID,
        'inputs' => [
          'show_text' => TRUE,
          'text' => 'Hello, world!',
        ],
      ],
    ]);
    self::assertSame([], self::violationsToArray($item_list->validate()));

    // The `text` prop being conditionally hidden client-side has no bearing
    // on server-side validation: invalid input is still rejected as usual.
    $item_list->setValue([
      [
        'uuid' => self::UUID,
        'component_id' => self::COMPONENT_ID,
        'inputs' => [
          'show_text' => FALSE,
          'no_such_prop' => 'ignored',
        ],
      ],
    ]);
    self::assertSame([
      \sprintf('0.inputs.%s.no_such_prop', self::UUID) => \sprintf('Component `%s`: the `no_such_prop` prop is not defined.', self::UUID),
    ], self::violationsToArray($item_list->validate()));
  }

}
