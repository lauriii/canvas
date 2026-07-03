<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Plugin\Field\FieldType;

use Drupal\canvas\Plugin\Field\FieldTypeOverride\StringLongItemOverride;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @see \Drupal\canvas\Plugin\Field\FieldTypeOverride\StringLongItemOverride
 */
#[CoversClass(StringLongItemOverride::class)]
#[Group('canvas')]
#[Group('canvas_shape_matching')]
final class StringLongItemOverrideTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'field',
    'entity_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('entity_test');

    FieldStorageConfig::create([
      'entity_type' => 'entity_test',
      'type' => 'string_long',
      'field_name' => 'test_long',
    ])->save();
    FieldConfig::create([
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'type' => 'string_long',
      'field_name' => 'test_long',
    ])->save();
  }

  /**
   * A long multiline value must validate without a Regex violation.
   *
   * Canvas overrides `string_long` with a Regex constraint that accepts any
   * string, including newlines. The pattern must not exhaust the PCRE JIT stack
   * on long values: otherwise `preg_match()` returns FALSE with
   * `PREG_JIT_STACKLIMIT_ERROR`, which Symfony's Regex validator reports as a
   * failed match, so saving a `string_long` field with a long value fails with
   * "This value is not valid."
   *
   * @see \Drupal\canvas\Plugin\Field\FieldTypeOverride\StringLongItemOverride
   * @see \Drupal\canvas\Validation\JitSafeRegexValidator
   */
  public function testLongMultilineValueIsValid(): void {
    // Confirm the Canvas override is in effect for `string_long` fields.
    $this->assertSame(
      StringLongItemOverride::class,
      $this->container->get('plugin.manager.field.field_type')->getDefinition('string_long')['class'],
    );

    $value = \str_repeat("lorem ipsum dolor\n", 5000);
    self::assertGreaterThan(80000, \strlen($value));

    $entity = EntityTest::create(['test_long' => $value]);
    $violations = $entity->get('test_long')->validate();

    $this->assertCount(0, $violations, (string) $violations);
  }

}
