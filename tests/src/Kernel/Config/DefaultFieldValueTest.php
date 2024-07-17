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
    'sdc_test',
  ];

  public function providerDefaultFieldValue(): array {
    $test_cases = $this->getComponentTreeTestCases();
    array_push($test_cases['valid values using dynamic props'], NULL, NULL);
    array_push($test_cases['missing props key'], SchemaIncompleteException::class, 'Schema errors for field.field.node.article.field_xb_test with the following errors: 0 [default_value.0] The array must contain a &quot;props&quot; key.');
    array_push($test_cases['missing tree key'], SchemaIncompleteException::class, 'Schema errors for field.field.node.article.field_xb_test with the following errors: 0 [default_value.0] The array must contain a &quot;tree&quot; key.');
    // If dynamic prop sources are used the validation cannot be preformed for the default value.
    array_push($test_cases['missing components, using dynamic props'], NULL, NULL);
    array_push($test_cases['props invalid, using dynamic props'], NULL, NULL);
    array_push($test_cases['missing components, using only static props'], SchemaIncompleteException::class, "Schema errors for field.field.node.article.field_xb_test with the following errors: 0 [default_value.0] The component instance with UUID &lt;em class=&quot;placeholder&quot;&gt;static-card2df&lt;/em&gt; uses component &lt;em class=&quot;placeholder&quot;&gt;sdc_test:missing&lt;/em&gt; but does not exist! Put a breakpoint here and figure out why.");
    array_push($test_cases['props invalid, using only static props'], SchemaIncompleteException::class, 'Schema errors for field.field.node.article.field_xb_test with the following errors: 0 [default_value.0] The component instance with UUID &lt;em class=&quot;placeholder&quot;&gt;static-card2df&lt;/em&gt; uses component &lt;em class=&quot;placeholder&quot;&gt;sdc_test:my-cta&lt;/em&gt; and receives some invalid props! Put a breakpoint here and figure out why.');
    return $test_cases;
  }

  /**
   * @coversClass \Drupal\experience_builder\Plugin\Validation\Constraint\ValidComponentTreeConstraintValidator
   * @dataProvider providerDefaultFieldValue
   */
  public function testDefaultFieldValue(array $field_values, ?string $expected_exception, ?string $expected_message): void {
    $this->container->get('module_installer')->install(['link', 'node', 'text', 'xb_test_config_node_article']);
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
