<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Config;

use Drupal\Core\Config\Schema\SchemaIncompleteException;
use Drupal\field\Entity\FieldConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\experience_builder\Traits\ComponentTreeTestTrait;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;

class DefaultFieldValueTest extends KernelTestBase {

  use ComponentTreeTestTrait;
  use ContribStrictConfigSchemaTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'experience_builder',
    'system',
    'xb_test_sdc',
    'xb_test_config_node_article',
    // All of `xb_test_config_node_article`'s dependencies.
    'node',
    'field',
    'link',
    'text',
    // XB's dependencies.
    'datetime',
    'file',
    'image',
    'options',
    'path',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['xb_test_config_node_article']);
  }

  public static function providerDefaultFieldValue(): array {
    $test_cases = static::getValidTreeTestCases();
    array_walk($test_cases, fn (array &$test_case) => array_push($test_case, NULL, NULL));
    $test_cases = array_merge($test_cases, static::getInvalidTreeTestCases());
    // Ensure the root validation is enforced.
    array_push($test_cases['invalid tree structure, uuid at top of data structure is not in the tree, also has empty slots'], SchemaIncompleteException::class, 'Schema errors for field.field.node.article.field_xb_test with the following errors: 0 [default_value.0.tree[other-uuid]] Empty component subtree. A component subtree must contain &gt;=1 populated slot (with &gt;=1 component instance). Empty component subtrees must be omitted., 1 [default_value.0.tree[other-uuid]] Dangling component subtree. This component subtree claims to be for a component instance with UUID &lt;em class=&quot;placeholder&quot;&gt;other-uuid&lt;/em&gt;, but no such component instance can be found.');
    // Ensure the props validation is enforced even if the root is invalid.
    array_push($test_cases['props invalid, using only static props'], SchemaIncompleteException::class, 'Schema errors for field.field.node.article.field_xb_test with the following errors: 0 [default_value.0] The component instance with UUID &lt;em class=&quot;placeholder&quot;&gt;static-card2df&lt;/em&gt; uses component &lt;em class=&quot;placeholder&quot;&gt;xb_test_sdc:props-no-slots&lt;/em&gt; and receives some invalid props! Put a breakpoint here and figure out why.');
    array_push($test_cases['missing props key'], SchemaIncompleteException::class, 'Schema errors for field.field.node.article.field_xb_test with the following errors: 0 [default_value.0] The array must contain a &quot;props&quot; key.');
    array_push($test_cases['missing tree key'], SchemaIncompleteException::class, 'Schema errors for field.field.node.article.field_xb_test with the following errors: 0 [default_value.0] The array must contain a &quot;tree&quot; key.');
    // If dynamic prop sources are used the validation cannot be preformed for the default value.
    array_push($test_cases['missing components, using dynamic props'], SchemaIncompleteException::class, 'Schema errors for field.field.node.article.field_xb_test with the following errors: 0 [default_value.0.tree[a548b48d-58a8-4077-aa04-da9405a6f418][0]] The component &lt;em class=&quot;placeholder&quot;&gt;sdc_test+missing&lt;/em&gt; does not exist., 1 [default_value.0.tree[a548b48d-58a8-4077-aa04-da9405a6f418][1]] The component &lt;em class=&quot;placeholder&quot;&gt;sdc_test+missing-also&lt;/em&gt; does not exist.');
    array_push($test_cases['props invalid, using dynamic props'], NULL, NULL);
    array_push($test_cases['missing components, using only static props'], SchemaIncompleteException::class, 'Schema errors for field.field.node.article.field_xb_test with the following errors: 0 [default_value.0.tree[a548b48d-58a8-4077-aa04-da9405a6f418][0]] The component &lt;em class=&quot;placeholder&quot;&gt;sdc_test+missing&lt;/em&gt; does not exist.');
    array_push($test_cases['props invalid, using only static props'], SchemaIncompleteException::class, 'Schema errors for field.field.node.article.field_xb_test with the following errors: 0 [default_value.0] The component instance with UUID &lt;em class=&quot;placeholder&quot;&gt;static-card2df&lt;/em&gt; uses component &lt;em class=&quot;placeholder&quot;&gt;xb_test_sdc+props-no-slots&lt;/em&gt; and receives some invalid props! Put a breakpoint here and figure out why.');
    return $test_cases;
  }

  /**
   * @coversClass \Drupal\experience_builder\Plugin\Validation\Constraint\ValidComponentTreeConstraintValidator
   * @dataProvider providerDefaultFieldValue
   */
  public function testDefaultFieldValue(array $field_values, ?string $expected_exception, ?string $expected_message): void {
    $field_config = FieldConfig::loadByName('node', 'article', 'field_xb_test');
    $this->assertInstanceOf(FieldConfig::class, $field_config);

    $field_config->setDefaultValue($field_values);
    if ($expected_exception && $expected_message) {
      // @phpstan-ignore-next-line
      $this->expectException($expected_exception);
      $this->expectExceptionMessage($expected_message);
    }

    $field_config->save();
  }

}
