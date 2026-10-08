<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Tmgmt;

// cspell:ignore Traduit Gitane

use Drupal\canvas\Entity\Page;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\canvas\Tmgmt\ComponentInputsTranslatablesExtractor;
use Drupal\canvas\Tmgmt\ComponentTreeFieldProcessor;
use Drupal\canvas_test_block\Plugin\Block\CanvasTestBlockInputTranslatability;
use Drupal\content_translation\BundleTranslationSettingsInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Tests\canvas\Kernel\Translation\ContentComponentTreeSymmetricalTranslationTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests how ComponentTreeFieldProcessor writes discarded ∅ "translations".
 *
 * A translator plugin may echo the ∅ sentinel (the placeholder for an input
 * that is empty in the source language) back as the "translation". Such an
 * echo is not a translation: it is discarded and the input is refilled from
 * the default translation. This covers the two refill paths that the review
 * form cannot reach:
 * - a composite input whose every translatable leaf is an echoed sentinel
 *   (e.g. `text_format`: only `value` is translatable) is refilled as one
 *   value, so `value` and `format` stay consistent;
 * - a discarded value at a position the default translation does not have is
 *   dropped instead of leaving a NULL behind.
 *
 * @see \Drupal\Tests\canvas\Functional\ContentWithComponentTreeTmgmtUiTest::testEmptySetPlaceholderBehavior()
 */
#[CoversClass(ComponentTreeFieldProcessor::class)]
#[Group('canvas')]
#[Group('canvas_translation')]
#[RunTestsInSeparateProcesses]
final class ComponentTreeFieldProcessorTest extends ContentComponentTreeSymmetricalTranslationTestBase {

  private const string UUID_BANNER = 'b1b1b1b1-0000-4000-8000-000000000001';
  private const string UUID_DEEP = 'd2d2d2d2-0000-4000-8000-000000000002';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_test_block',
    'tmgmt',
    'tmgmt_content',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // tmgmt_content's entity hooks look for continuous jobs on entity save.
    // @see tmgmt_content_entity_insert()
    $this->installEntitySchema('tmgmt_job');
    $this->installEntitySchema('tmgmt_job_item');
    $this->installEntitySchema('tmgmt_message');
  }

  public function test(): void {
    $field_name = 'components';
    $this->setUpSymmetricalContentTranslation(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID, $field_name);
    $storage = $this->container->get(EntityTypeManagerInterface::class)->getStorage(Page::ENTITY_TYPE_ID);

    $banner_text = [
      'value' => '<p>In a curious work, published in <em>Paris</em> in 1863.</p>',
      'format' => 'canvas_html_block',
    ];
    $entity = $this->createEntityWithDefaultTranslation(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID, $field_name, $storage, [
      [
        'uuid' => self::UUID_BANNER,
        'component_id' => 'sdc.canvas_test_sdc.banner',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => [
          'heading' => 'A heading element! :)',
          'text' => $banner_text,
        ],
      ],
      [
        'uuid' => self::UUID_DEEP,
        'component_id' => 'block.' . CanvasTestBlockInputTranslatability::PLUGIN_ID,
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => [
          'label' => '',
          'label_display' => '0',
          ...CanvasTestBlockInputTranslatability::DEFAULT_CONFIGURATION,
        ],
      ],
    ]);
    self::assertEntityIsValid($entity);
    $entity->save();

    // Mirror tmgmt_content: a new translation starts as a copy of the source.
    // @see \Drupal\tmgmt_content\Plugin\tmgmt\Source\ContentEntitySource::doSaveTranslations()
    $translation = $entity->addTranslation('fr', $entity->toArray());
    \assert($translation instanceof ContentEntityInterface);
    $this->container->get(BundleTranslationSettingsInterface::class)
      ->getTranslationMetadata($translation)
      ->setSource($entity->language()->getId());
    $fr_field = $translation->get($field_name);
    \assert($fr_field instanceof ComponentTreeItemList);

    $processor = ComponentTreeFieldProcessor::create($this->container);
    $extracted = $processor->extractTranslatableData($fr_field);

    // A `text_format` input is a composite: only `value` is translatable.
    self::assertTrue($extracted[0]['text']['value']['#translate']);
    self::assertSame('canvas_html_block', $extracted[0]['text']['value']['#format']);
    self::assertArrayNotHasKey('format', $extracted[0]['text']);

    // Case A: every translatable leaf of a composite input is an echoed
    // sentinel → the whole composite is refilled from the default translation,
    // so `format` cannot drift from `value`. The sibling scalar prop's real
    // translation is stored.
    $processor->setTranslations([
      0 => [
        'heading' => self::translated($extracted[0]['heading'], 'fr: A heading element! :)'),
        'text' => [
          'value' => self::translated($extracted[0]['text']['value'], ComponentInputsTranslatablesExtractor::EMPTY_SENTINEL),
        ],
      ],
    ], $fr_field);
    $banner_inputs = self::inputs($fr_field, self::UUID_BANNER);
    self::assertSame('fr: A heading element! :)', $banner_inputs['heading']);
    self::assertSame($banner_text, $banner_inputs['text']);

    // Contrast: a real translation of the composite's `value` is written in
    // and `format` is preserved from the existing value.
    $processor->setTranslations([
      0 => [
        'text' => [
          'value' => self::translated($extracted[0]['text']['value'], '<p>fr</p>'),
        ],
      ],
    ], $fr_field);
    self::assertSame(['value' => '<p>fr</p>', 'format' => 'canvas_html_block'], self::inputs($fr_field, self::UUID_BANNER)['text']);

    // Case B: a discarded value at a position the default translation does
    // not have is dropped, not stored as NULL. The translation (in memory) has
    // a second sequence item that the default translation lacks; that item's
    // only translatable leaf is an echoed sentinel.
    $deep_item = $fr_field->getComponentTreeItemByUuid(self::UUID_DEEP);
    \assert($deep_item instanceof ComponentTreeItem);
    $deep_inputs = $deep_item->getInputs() ?? [];
    self::assertCount(1, $deep_inputs['deeply_nested_translatable']);
    $deep_inputs['deeply_nested_translatable'][] = ['foo' => 'Meh', 'bar' => 'Kro'];
    $deep_item->setInput($deep_inputs);
    $extracted = $processor->extractTranslatableData($fr_field);
    self::assertTrue($extracted[1]['deeply_nested_translatable'][1]['bar']['#translate']);

    $processor->setTranslations([
      1 => [
        'deeply_nested_translatable' => [
          0 => ['bar' => self::translated($extracted[1]['deeply_nested_translatable'][0]['bar'], 'Traduit')],
          1 => ['bar' => self::translated($extracted[1]['deeply_nested_translatable'][1]['bar'], ComponentInputsTranslatablesExtractor::EMPTY_SENTINEL)],
        ],
      ],
    ], $fr_field);
    self::assertSame(
      [['foo' => 'Huh?', 'bar' => 'Traduit']],
      self::inputs($fr_field, self::UUID_DEEP)['deeply_nested_translatable'],
    );
    // The refilled tree is symmetrical with the default translation again.
    self::assertEntityIsValid($translation);
  }

  /**
   * Marks an extracted translatable as translated, as a translator plugin does.
   *
   * @param array<string, mixed> $translatable
   *   An extracted TMGMT translatable (has #text and #translate).
   * @param string $text
   *   The "translation" returned by the translator plugin.
   *
   * @return array<string, mixed>
   *   The translatable with #translation set.
   */
  private static function translated(array $translatable, string $text): array {
    self::assertTrue($translatable['#translate'] ?? FALSE);
    $translatable['#translation'] = ['#text' => $text];
    return $translatable;
  }

  /**
   * Gets the current inputs of a component instance in a component tree.
   *
   * @return array<string, mixed>
   *   The inputs.
   */
  private static function inputs(ComponentTreeItemList $list, string $uuid): array {
    $item = $list->getComponentTreeItemByUuid($uuid);
    self::assertInstanceOf(ComponentTreeItem::class, $item);
    return $item->getInputs() ?? [];
  }

}
