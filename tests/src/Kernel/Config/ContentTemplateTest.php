<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Config;

// cspell:ignore Aangedreven Holle daar staan voor Hallo wereld Ceci

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\EntityHandlers\ContentTemplateAwareViewBuilder;
use Drupal\canvas\PropSource\PropSource;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\ConfigException;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * Tests Drupal\canvas\Entity\ContentTemplate.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(ContentTemplate::class)]
#[Group('canvas')]
#[Group('canvas_translation')]
final class ContentTemplateTest extends CanvasKernelTestBase {

  use ContentTypeCreationTrait;
  use GenerateComponentConfigTrait;
  use ConstraintViolationsTestTrait;

  private const UUID_SDC_UNSTRUCTURED_DATA = '435d1d20-a697-4d36-9892-9d61c825c99c';
  private const UUID_SDC_STRUCTURED_DATA = '57afe4ed-c593-4457-a741-2ac5053be928';
  private const UUID_BLOCK = '2e2f19ee-7074-4570-91a9-169e81fb0d19';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'field',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('path_alias');
    $this->installConfig(['node', 'user']);
    NodeType::create(['type' => 'helpful', 'name' => 'Helpful'])->save();
  }

  /**
   * Tests label.
   *
   * @legacy-covers ::label
   */
  #[TestWith(["node.helpful.full", "Helpful content items — Full content view"])]
  #[TestWith(["user.user.compact", "Users — Compact view"])]
  public function testLabel(string $id, string $expected_label): void {
    [$entity_type_id, $bundle, $view_mode] = explode('.', $id, 3);

    $template = ContentTemplate::create([
      'id' => $id,
      'content_entity_type_id' => $entity_type_id,
      'content_entity_type_bundle' => $bundle,
      'content_entity_type_view_mode' => $view_mode,
    ]);
    $this->assertSame($expected_label, (string) $template->label());
  }

  /**
   * Tests only content entities can use templates.
   *
   * @legacy-covers \Drupal\canvas\Hook\ContentTemplateHooks::entityTypeAlter
   */
  public function testOnlyContentEntitiesCanUseTemplates(): void {
    $manager = \Drupal::entityTypeManager();
    $definition = $manager->getDefinition('node');
    \assert($definition instanceof EntityTypeInterface);
    $this->assertTrue($definition->hasHandlerClass(ContentTemplateAwareViewBuilder::DECORATED_HANDLER_KEY));
    $this->assertSame(ContentTemplateAwareViewBuilder::class, $definition->getViewBuilderClass());

    // Config entities have no view builder and Canvas doesn't touch them.
    $definition = $manager->getDefinition('user_role');
    \assert($definition instanceof EntityTypeInterface);
    $this->assertFalse($definition->hasViewBuilderClass());
    $this->assertFalse($definition->hasHandlerClass(ContentTemplateAwareViewBuilder::DECORATED_HANDLER_KEY));

    // Canvas pages are left alone despite being content entities.
    $definition = $manager->getDefinition(Page::ENTITY_TYPE_ID);
    \assert($definition instanceof EntityTypeInterface);
    $this->assertFalse($definition->hasHandlerClass(ContentTemplateAwareViewBuilder::DECORATED_HANDLER_KEY));
  }

  /**
   * Tests config-defined component tree translation life cycle in-depth.
   *
   * Equally important to test, handled by ConfigWithComponentTreeTestBase:
   * - testing a spectrum of config-defined component trees
   * - for every config entity type that stores a component tree
   *
   * @see \Drupal\Tests\canvas\Kernel\Config\ConfigWithComponentTreeTestBase
   * @see \Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource\ComponentSourceTestBase::testGetTranslatableInputKeys()
   * @see \Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource\ComponentSourceTestBase::providerSymmetricallyTranslatableComponentInstanceScenarios()
   * @see \Drupal\Tests\canvas\Functional\TranslationTest::testContentTemplateConfigTranslationUi()
   */
  public function testTranslationLifeCycleInDepth(): void {
    $this->enableModules([
      // Provides LanguageConfigOverride + LanguageConfigFactoryOverride.
      'language',
      // Necessary for saving LanguageConfigOverrides that contain only
      // translatable subsets.
      // @see ::saveConfigEntityTranslation()
      'config_translation',
    ]);
    $this->generateComponentConfig();

    $template = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'helpful',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [
        // For comprehensive coverage of SDC-specific edge cases: unit test.
        // @see \Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource\SingleDirectoryComponentTest::providerSymmetricallyTranslatableComponentInstanceScenarios()
        [
          'uuid' => self::UUID_SDC_UNSTRUCTURED_DATA,
          'component_id' => 'sdc.canvas_test_sdc.my-cta',
          'component_version' => '89881c04a0fde367',
          'inputs' => [
            // StaticPropSource that IS translatable based on prop shape.
            'text' => 'Powered by Drupal Canvas',
            // StaticPropSource that IS translatable based on prop shape.
            'href' => [
              'uri' => 'https://drupal.org/project/canvas',
              'options' => [],
            ],
          ],
        ],
        // For comprehensive coverage of block-specific edge cases: unit test.
        // @see \Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource\SingleDirectoryComponentTest::providerSymmetricallyTranslatableComponentInstanceScenarios()
        [
          'uuid' => self::UUID_BLOCK,
          'component_id' => 'block.system_branding_block',
          'component_version' => Component::load('block.system_branding_block')?->getActiveVersion(),
          'inputs' => [
            // Only `label` should be translatable.
            'label' => 'Branding is important, right?',
            'label_display' => 'visible',
            'use_site_logo' => FALSE,
            'use_site_name' => TRUE,
            'use_site_slogan' => TRUE,
          ],
        ],
        // For comprehensive coverage of SDC-specific edge cases: unit test.
        // @see \Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource\SingleDirectoryComponentTest::providerSymmetricallyTranslatableComponentInstanceScenarios()
        [
          'uuid' => self::UUID_SDC_STRUCTURED_DATA,
          'component_id' => 'sdc.canvas_test_sdc.my-cta',
          'component_version' => '89881c04a0fde367',
          'inputs' => [
            // EntityFieldPropSource: never translatable.
            'text' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:helpful␝title␞␟value',
            ],
            // HostEntityUrlPropSource: never translatable.
            'href' => [
              'sourceType' => PropSource::HostEntityUrl->value,
              'absolute' => TRUE,
            ],
          ],
        ],
      ],
    ]);
    self::assertEntityIsValid($template);
    $template->save();

    $language_manager = $this->container->get(LanguageManagerInterface::class);
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);

    // Test initial monolingual situation.
    self::assertSame('en', $language_manager->getDefaultLanguage()->getId());
    self::assertSame(['en'], \array_keys($language_manager->getLanguages()));
    self::assertSame('en', $template->language()->getId(), 'Config entities are created in the default language of the site.');
    self::assertSame('en', $language_manager->getConfigOverrideLanguage()->getId());

    // Convert this site from monolingual to multilingual.
    ConfigurableLanguage::createFromLangcode('nl')->save();
    self::assertSame(['nl', 'en'], \array_keys($language_manager->getLanguages()));

    // No translation exists yet.
    $override = $language_manager->getLanguageConfigOverride('nl', $template->getConfigDependencyName());
    self::assertTrue($override->isNew());
    self::assertSame([], $override->getRawData());

    // Translate the component tree's first component instance's `text` prop.
    // TRICKY: the entire Config Translation infrastructure is form-centric; no
    // actual API is offered. For Canvas component trees' instances, Field
    // Widgets are used, which means values must be provided in the same way as
    // the widget's form structure.
    // @see \Drupal\config_translation\FormElement\ElementInterface::setConfig()
    // @see \Drupal\canvas\ConfigTranslation\CanvasStaticPropSourceFieldWidget::setConfig()
    // @see \Drupal\Tests\canvas\Functional\TranslationTest::testContentTemplateConfigTranslationUi()
    $en_stored_values = $template->toArray();
    self::assertSame('Powered by Drupal Canvas', NestedArray::getValue($en_stored_values, ['component_tree', self::UUID_SDC_UNSTRUCTURED_DATA, 'inputs', 'text']));
    $nl_form_values = $en_stored_values = $template->toArray();
    // The sole value that CAN be translated: a `type: string` prop populated by
    // a StaticPropSource in the default translation.
    NestedArray::setValue($nl_form_values, ['component_tree', self::UUID_SDC_UNSTRUCTURED_DATA, 'inputs', 'text'], [0 => ['value' => 'Aangedreven door Drupal Canvas']]);
    // The user chose to not translate the `href`, but it gets submitted via a
    // field widget.
    // @see \Drupal\link\Plugin\Field\FieldWidget\LinkWidget::formElement()
    NestedArray::setValue($nl_form_values, ['component_tree', self::UUID_SDC_UNSTRUCTURED_DATA, 'inputs', 'href'], [0 => ['uri' => NestedArray::getValue($nl_form_values, ['component_tree', self::UUID_SDC_UNSTRUCTURED_DATA, 'inputs', 'href', 'uri'])]]);
    NestedArray::setValue($nl_form_values, ['component_tree', self::UUID_BLOCK, 'inputs', 'label'], 'Holle slogans, daar staan we voor.');
    NestedArray::setValue($nl_form_values, ['component_tree', self::UUID_BLOCK, 'inputs', 'label_display'], 'This should be filtered away because config schema `type: string` with a Choice constraint is not translatable.');
    NestedArray::setValue($nl_form_values, ['component_tree', self::UUID_BLOCK, 'inputs', 'use_site_logo'], 'This should be filtered away because config schema  `type: boolean` is not translatable.');
    NestedArray::setValue($nl_form_values, ['component_tree', self::UUID_BLOCK, 'inputs', 'use_site_name'], 'This should be filtered away because config schema  `type: boolean` is not translatable.');
    NestedArray::setValue($nl_form_values, ['component_tree', self::UUID_BLOCK, 'inputs', 'use_site_logo'], 'This should be filtered away because config schema  `type: boolean` is not translatable.');
    NestedArray::setValue($nl_form_values, ['component_tree', self::UUID_SDC_STRUCTURED_DATA, 'inputs', 'text'], [0 => ['value' => 'This should be filtered away because it is populated by an EntityFieldPropSource in the default translation.']]);
    NestedArray::setValue($nl_form_values, ['component_tree', self::UUID_SDC_STRUCTURED_DATA, 'inputs', 'href'], [0 => ['uri' => 'This should be filtered away because A) `type: string, format: uri` is not translatable, B) it is populated by a HostEntityUrlPropSource in the default translation.']]);
    // Note: $nl_values is the complete raw data of the config entity, with only
    // a single (deeply nested!) key-value pair changed (translated). The config
    // translation system ensures only the actually translated values are saved.
    $this->saveConfigEntityTranslation($template, 'nl', $nl_form_values);

    // Verify the translation was stored, in the expected efficient manner: only
    // the translatable subset, instead of duplicating everything.
    // @see \Drupal\language\Config\LanguageConfigFactoryOverride::onConfigSave()
    // @see \Drupal\Core\Config\ConfigFactoryOverrideBase::filterOverride()
    $override = $language_manager->getLanguageConfigOverride('nl', $template->getConfigDependencyName());
    self::assertFalse($override->isNew());
    self::assertSame([
      'component_tree' => [
        self::UUID_SDC_UNSTRUCTURED_DATA => [
          'inputs' => [
            'text' => 'Aangedreven door Drupal Canvas',
          ],
        ],
        self::UUID_BLOCK => [
          'inputs' => [
            'label' => 'Holle slogans, daar staan we voor.',
          ],
        ],
      ],
    ], $override->getRawData());
    $override_hash_original = \hash('xxh64', \json_encode($override->getRawData(), JSON_THROW_ON_ERROR));

    // Export config: keys should now contain position information to improve DX
    // and be tamper-resistant.
    $export_storage = $this->container->get('config.storage.export');
    self::assertInstanceOf(StorageInterface::class, $export_storage);
    self::assertSame(StorageInterface::DEFAULT_COLLECTION, $export_storage->getCollectionName());
    self::assertSame(['language.nl'], $export_storage->getAllCollectionNames());
    $exported_template = $export_storage->read($template->getConfigDependencyName());
    \assert(\is_array($exported_template));
    // Default collection: export transform applied.
    self::assertSame(
      [
        // Note the position information that was encoded in the sequence keys.
        // @see \Drupal\canvas\EventSubscriber\ComponentTreeConfigEntityTransformer::export()
        '0:' . self::UUID_SDC_UNSTRUCTURED_DATA,
        '1:' . self::UUID_BLOCK,
        '2:' . self::UUID_SDC_STRUCTURED_DATA,
      ],
      \array_keys($exported_template['component_tree']),
    );
    // Translation collection: NO export transform applied.
    self::assertSame(
      // Sequence keys untransformed (still UUIDs).
      [
        self::UUID_SDC_UNSTRUCTURED_DATA,
        self::UUID_BLOCK,
      ],
      // @phpstan-ignore-next-line offsetAccess.nonOffsetAccessible
      \array_keys($export_storage->createCollection('language.nl')->read($template->getConfigDependencyName())['component_tree'])
    );

    // Verify the original (English) template is unchanged.
    $template = ContentTemplate::load($template->id());
    self::assertNotNull($template);
    $original_tree = $template->get('component_tree');
    self::assertSame('Powered by Drupal Canvas', $original_tree[self::UUID_SDC_UNSTRUCTURED_DATA]['inputs']['text']);
    self::assertSame('Branding is important, right?', $original_tree[self::UUID_BLOCK]['inputs']['label']);

    // Reorder the component instances to test the effect on the loading of
    // translations.
    $tree = $template->getComponentTree();
    self::assertSame([
      self::UUID_SDC_UNSTRUCTURED_DATA,
      self::UUID_BLOCK,
      self::UUID_SDC_STRUCTURED_DATA,
    ], \array_column($template->get('component_tree'), 'uuid'));
    $component_instances = $tree->getValue();
    self::assertTrue(\array_is_list($component_instances));
    $template->setComponentTree(\array_reverse($component_instances))->save();
    self::assertEntityIsValid($template);
    self::assertSame([
      self::UUID_SDC_STRUCTURED_DATA,
      self::UUID_BLOCK,
      self::UUID_SDC_UNSTRUCTURED_DATA,
    ], \array_column($template->get('component_tree'), 'uuid'));

    // LanguageConfigOverride is unchanged. The translation is still correctly
    // applied based on the component instance UUID, not its position in the
    // tree.
    $override = $language_manager->getLanguageConfigOverride('nl', $template->getConfigDependencyName());
    $override_hash_after_reposition = \hash('xxh64', \json_encode($override->getRawData(), JSON_THROW_ON_ERROR));
    self::assertSame($override_hash_original, $override_hash_after_reposition);

    // Delete the two component instances that have translations.
    $template->setComponentTree([$component_instances[2]])->save();
    self::assertEntityIsValid($template);
    self::assertSame([
      self::UUID_SDC_STRUCTURED_DATA,
    ], \array_column($template->get('component_tree'), 'uuid'));
    $override = $language_manager->getLanguageConfigOverride('nl', $template->getConfigDependencyName());
    $override_hash_after_deletion = \hash('xxh64', \json_encode($override->getRawData(), JSON_THROW_ON_ERROR));
    self::assertNotSame($override_hash_original, $override_hash_after_deletion);
    self::assertTrue($override->isNew());
    self::assertSame([], $override->getRawData());

    // Verify that saving a LanguageConfigOverride with an invalid value throws
    // a ConfigException (in tests), after it's been saved. This ensures tests
    // cannot possibly unwittingly introduce such regressions.
    // @see \Drupal\canvas\EventSubscriber\LanguageConfigOverrideSchemaChecker
    //
    // Restore the component instance with a translatable `href` prop (a
    // `type: string, format: uri` SDC prop backed by a link field) so that an
    // invalid URI override can be attempted.
    $template->setComponentTree(\array_reverse($component_instances))->save();
    self::assertEntityIsValid($template);
    $nl_invalid_href_values = $template->toArray();
    NestedArray::setValue(
      $nl_invalid_href_values,
      parents: ['component_tree', self::UUID_SDC_UNSTRUCTURED_DATA, 'inputs', 'href'],
      // Override the `href` prop of UUID_SDC_UNSTRUCTURED_DATA with a value
      // that is not a valid URI — this must be rejected at save time.
      value: [0 => ['uri' => "Ceci n'est pas une URL"]],
    );
    try {
      $this->saveConfigEntityTranslation($template, 'nl', $nl_invalid_href_values);
      $this->fail('LanguageConfigOverrideSchemaChecker should have detected an invalid save.');
    }
    catch (ConfigException $e) {
      // @see \Drupal\canvas\EventSubscriber\LanguageConfigOverrideSchemaChecker
      self::assertSame(
        'Validation of the "canvas.content_template.node.helpful.full" LanguageConfigOverride failed: [component_tree.435d1d20-a697-4d36-9892-9d61c825c99c.inputs.435d1d20-a697-4d36-9892-9d61c825c99c.href] Invalid URL format. The provided value is: "Ceci n\'est pas une URL".',
        $e->getMessage(),
      );
    }

    // On production sites, LanguageConfigOverrideSchemaChecker won't be
    // installed to throw a ConfigException (that'd trigger a 500 response). But
    // validation would still catch it after the fact. Prove that once all UIs
    // perform validation, the relevant validation error would be surfaced.
    // @todo Ensure that the Config Translation and TMGMT UIs perform validation before saving: https://git.drupalcode.org/project/canvas/-/work_items/3591690
    self::assertSame([
      '' => '[<em class="placeholder">nl</em>] [<em class="placeholder">component_tree.435d1d20-a697-4d36-9892-9d61c825c99c.inputs.435d1d20-a697-4d36-9892-9d61c825c99c.href</em>] <em class="placeholder">Invalid URL format. The provided value is: &quot;Ceci n&#039;est pas une URL&quot;.</em>',
    ], self::violationsToArray($template->getTypedData()->validate()));

    // See also the integration tests that prove the translation can be created
    // via the UI and appears for end users.
    // @see \Drupal\Tests\canvas\Functional\TranslationTest::testContentTemplateConfigTranslationUi()
    // @see \Drupal\Tests\canvas\Functional\TranslationTest::testContentTemplateTranslationRendered()
  }

  /**
   * Tests that a translated content template stays valid after a Fallback.
   *
   * When a component's source becomes unavailable its instances switch to the
   * Fallback source, whose `inputs` config schema mapping is empty; without
   * accepting the instance's actual input keys, validation rejects them as
   * unsupported. Beyond the per-entity coverage in
   * FallbackComponentTreeConfigValidationTestTrait, this exercises the
   * translation path: a content template translated (config override) while its
   * source was available must still validate once the component falls back —
   * including when loaded in the translation's language, where the validator
   * sees the translated input values.
   *
   * @see \Drupal\canvas\ComponentSource\FallbackComponentInstanceInputsConfigSchemaGenerator::refineForInstance()
   */
  public function testFallbackComponentTranslationRemainsValid(): void {
    $this->enableModules(['language', 'config_translation']);
    $this->generateComponentConfig();
    $language_manager = $this->container->get(LanguageManagerInterface::class);
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);
    ConfigurableLanguage::createFromLangcode('nl')->save();

    // Create a code component whose instance will be translated and then forced
    // to the Fallback source by deleting the config it depends on.
    $js_component = JavaScriptComponent::create([
      'machineName' => 'fallback_test_cta',
      'name' => 'Fallback Test CTA',
      'status' => TRUE,
      'props' => [
        'text' => [
          'type' => 'string',
          'title' => 'Text',
          'examples' => ['Powered by Drupal Canvas'],
        ],
        'href' => [
          'type' => 'string',
          'format' => 'uri',
          'title' => 'URL',
          'examples' => ['https://drupal.org'],
        ],
      ],
      'required' => [],
      'js' => ['original' => '', 'compiled' => ''],
      'css' => ['original' => '', 'compiled' => ''],
      'dataDependencies' => [],
    ]);
    $js_component->save();
    $component = Component::load('js.fallback_test_cta');
    \assert($component instanceof ComponentInterface);

    $uuid = '435d1d20-a697-4d36-9892-9d61c825c99c';
    $template = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'helpful',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [
        [
          'uuid' => $uuid,
          'component_id' => 'js.fallback_test_cta',
          'component_version' => $component->getActiveVersion(),
          'inputs' => [
            'text' => 'Powered by Drupal Canvas',
            'href' => ['uri' => 'https://drupal.org/project/canvas', 'options' => []],
          ],
        ],
      ],
    ]);
    self::assertEntityIsValid($template);
    $template->save();

    // Translate the `text` input to Dutch (a config translation override).
    $nl_values = $template->toArray();
    NestedArray::setValue($nl_values, ['component_tree', $uuid, 'inputs', 'text'], [0 => ['value' => 'Aangedreven door Drupal Canvas']]);
    NestedArray::setValue($nl_values, ['component_tree', $uuid, 'inputs', 'href'], [0 => ['uri' => 'https://drupal.org/project/canvas']]);
    $this->saveConfigEntityTranslation($template, 'nl', $nl_values);
    $override = $language_manager->getLanguageConfigOverride('nl', $template->getConfigDependencyName());
    self::assertFalse($override->isNew());
    self::assertSame(
      ['component_tree' => [$uuid => ['inputs' => ['text' => 'Aangedreven door Drupal Canvas']]]],
      $override->get(),
    );

    // Delete the code component the instance depends on. Because the template is
    // saved (a usage exists), Component::onDependencyRemoval() converts the
    // Component to the Fallback source through its real code path instead of
    // deleting it — the same strategy as
    // \Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource\FallbackInputTest::testFallbackInputCanBeRecovered,
    // which triggers a fallback by deleting the config the component depends on.
    $js_component->delete();
    self::assertSame(
      ComponentInterface::FALLBACK_VERSION,
      Component::load('js.fallback_test_cta')?->getComponentSource()->getPluginId(),
    );

    // Loading the template in the Dutch context applies the override, so the
    // tree carries the translated `text`.
    $nl = ConfigurableLanguage::load('nl');
    \assert($nl instanceof ConfigurableLanguage);
    $language_manager->setConfigOverrideLanguage($nl);
    $nl_reloaded = ContentTemplate::load($template->id());
    \assert($nl_reloaded instanceof ContentTemplate);
    self::assertSame('Aangedreven door Drupal Canvas', $nl_reloaded->get('component_tree')[$uuid]['inputs']['text']);

    // The translated tree must validate against the now-empty fallback config
    // schema mapping. The validator sees the instance's actual (translated)
    // inputs — `text` and `href` — so without accepting them they are rejected
    // as "'text'/'href' is not a supported key".
    self::assertEntityIsValid($nl_reloaded);
    $language_manager->setConfigOverrideLanguage($language_manager->getDefaultLanguage());

    // The base config entity validates too.
    $reloaded = ContentTemplate::load($template->id());
    \assert($reloaded instanceof ContentTemplate);
    self::assertEntityIsValid($reloaded);
  }

  public function testAbsentContentTemplateKeepsCacheMetadata(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');

    $node = Node::create([
      'title' => 'Some node',
      'type' => 'helpful',
    ]);
    $node->save();
    $view_builder = \Drupal::entityTypeManager()->getViewBuilder('node');
    // Canvas' view builder is not used because there is no ContentTemplate yet.
    $this->assertSame(ContentTemplateAwareViewBuilder::class, $view_builder::class);
    $build = $view_builder->view($node);

    // Assert the right cacheability.
    $this->assertContains('without-canvas', $build['#cache']['keys']);
    // Note: AutoSaveManager::CACHE_TAG is NOT present because we're not on a
    // preview route. It's only added on preview routes to avoid invalidating
    // all rendered nodes on the live site when auto-saves change.
    // @see \Drupal\canvas\EntityHandlers\ContentTemplateAwareViewBuilder::getBuildDefaults()
    $this->assertEqualsCanonicalizing([
      'node_view',
      'node:1',
      'config:content_template_list',
    ], $build['#cache']['tags']);
    // Verify the specialized cache context is present.
    $this->assertContains('route.name.is_canvas_editor_ui', $build['#cache']['contexts']);
  }

}
