<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Functional\Update;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\experience_builder\Entity\ContentTemplate;
use Drupal\experience_builder\Entity\PageRegion;
use Drupal\experience_builder\Entity\Pattern;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemListInstantiatorTrait;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\FieldConfigInterface;
use Drupal\FunctionalTests\Update\UpdatePathTestBase;
use Symfony\Component\Validator\ConstraintViolation;

/**
 * @covers experience_builder_post_update_collapse_pattern_component_inputs()
 * @covers experience_builder_post_update_collapse_page_region_component_inputs()
 * @covers experience_builder_post_update_collapse_content_template_component_inputs()
 * @covers experience_builder_post_update_collapse_field_config_component_inputs()
 * @group experience_builder
 */
final class CollapseComponentInputsUpdateTest extends UpdatePathTestBase {

  use ComponentTreeItemListInstantiatorTrait;

  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles(): void {
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/drupal-11.2.2-with-xb-0.7.2-alpha1.filled.php.gz';
  }

  /**
   * Tests collapsing inputs.
   */
  public function testCollapseInputs(): void {
    $expected_before_input = [
      'text' => [
        'sourceType' => 'static:field_item:string',
        'value' => 'Step outside myself',
        'expression' => 'ℹ︎string␟value',
      ],
      'href' => [
        'sourceType' => 'static:field_item:link',
        'value' => ['uri' => 'https://drupal.org/', 'options' => []],
        'expression' => 'ℹ︎link␟url',
        'sourceTypeSettings' => [
          'instance' => [
            'title' => 0,
          ],
        ],
      ],
    ];
    $expected_after_input = [
      'text' => 'Step outside myself',
      'href' => ['uri' => 'https://drupal.org/', 'options' => []],
    ];

    $pattern_before = Pattern::load('test_pattern');
    \assert($pattern_before instanceof Pattern);
    self::assertSame($expected_before_input, $pattern_before->getComponentTree()->getComponentTreeItemByUuid('c28c3443-174c-4a83-a07a-8a071b133371')?->getInputs());

    $template_before = ContentTemplate::load(\implode('.', ['node', 'article', 'reverse']));
    \assert($template_before instanceof ContentTemplate);
    self::assertSame($expected_before_input, $template_before->getComponentTree()->getComponentTreeItemByUuid('c28c3443-174c-4a83-a07a-8a071b133371')?->getInputs());

    $field_before = FieldConfig::load('node.article.field_xb_demo');
    \assert($field_before instanceof FieldConfigInterface);
    $field_default_value_tree = self::staticallyCreateDanglingComponentTreeItemList(\Drupal::typedDataManager());
    $field_default_value_tree->setValue($field_before->get('default_value'));
    self::assertSame($expected_before_input, $field_default_value_tree->getComponentTreeItemByUuid('c28c3443-174c-4a83-a07a-8a071b133371')?->getInputs());

    $region_before = PageRegion::load('stark.sidebar_first');
    \assert($region_before instanceof PageRegion);
    self::assertSame($expected_before_input, $region_before->getComponentTree()->getComponentTreeItemByUuid('c28c3443-174c-4a83-a07a-8a071b133371')?->getInputs());

    $this->runUpdates();

    $pattern_after = Pattern::load('test_pattern');
    \assert($pattern_after instanceof Pattern);
    self::assertSame($expected_after_input, $pattern_after->getComponentTree()->getComponentTreeItemByUuid('c28c3443-174c-4a83-a07a-8a071b133371')?->getInputs());
    self::assertEntityIsValid($pattern_after);

    $template_after = ContentTemplate::load(\implode('.', ['node', 'article', 'reverse']));
    \assert($template_after instanceof ContentTemplate);
    self::assertSame($expected_after_input, $template_after->getComponentTree()->getComponentTreeItemByUuid('c28c3443-174c-4a83-a07a-8a071b133371')?->getInputs());
    self::assertEntityIsValid($template_after);

    $field_after = FieldConfig::load('node.article.field_xb_demo');
    \assert($field_after instanceof FieldConfigInterface);
    $field_default_value_tree = self::staticallyCreateDanglingComponentTreeItemList(\Drupal::typedDataManager());
    $field_default_value_tree->setValue($field_after->get('default_value'));
    self::assertSame($expected_after_input, $field_default_value_tree->getComponentTreeItemByUuid('c28c3443-174c-4a83-a07a-8a071b133371')?->getInputs());
    self::assertEntityIsValid($field_after);

    $region_after = PageRegion::load('stark.sidebar_first');
    \assert($region_after instanceof PageRegion);
    self::assertSame($expected_after_input, $region_after->getComponentTree()->getComponentTreeItemByUuid('c28c3443-174c-4a83-a07a-8a071b133371')?->getInputs());
    self::assertEntityIsValid($region_after);
  }

  protected static function assertEntityIsValid(ConfigEntityInterface $entity): void {
    $violations = $entity->getTypedData()->validate();
    self::assertCount(0,
      $violations,
      \sprintf(
        'Violations exist for %s %s: %s',
        $entity->getEntityType()->getLabel(),
        $entity->id(),
        \implode(\PHP_EOL, \array_map(
          // @phpstan-ignore-next-line
          static fn(ConstraintViolation $violation): string => \sprintf('%s: %s', $violation->getPropertyPath(), $violation->getMessage()),
          \iterator_to_array($violations))
        )
      )
    );
  }

}
