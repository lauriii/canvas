<?php

declare(strict_types=1);

// cspell:ignore magnifique Propulsé Bienvenue savoir Découvrez Identité visuelle

namespace Drupal\Tests\canvas\Functional;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\PropSource\PropSource;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\language\ConfigurableLanguageManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Url;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\ApiRequestTrait;
use Drupal\Tests\canvas\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\canvas\Traits\DataProviderWithComponentTreeTrait;
use Drupal\Tests\content_translation\Traits\ContentTranslationTestTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Translation.
 *
 * @todo Add test coverage for entity field prop sources used in the content
 *   templates in https://drupal.org/i/3455629. This will most likely require
 *   adding back `canvas_entity_prepare_view()` which was removed in
 *   https://www.drupal.org/i/3481720.
 * @see https://www.drupal.org/project/canvas/issues/3455629#comment-15831060
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('canvas_translation')]
class TranslationTest extends FunctionalTestBase {

  use ApiRequestTrait;
  use ConstraintViolationsTestTrait;
  use ContentTranslationTestTrait;
  use DataProviderWithComponentTreeTrait;

  private const UUID_STATIC_CTA =
    '435d1d20-a697-4d36-9892-9d61c825c99c';
  private const UUID_DYNAMIC_CTA =
    '57afe4ed-c593-4457-a741-2ac5053be928';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas',
    'canvas_test_sdc',
    'content_translation',
    'language',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected $profile = 'minimal';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // In 11.2 and above we install modules in groups, which means this module
    // cannot be installed in the same group as canvas
    \Drupal::service(ModuleInstallerInterface::class)->install(['canvas_test_config_node_article']);

    $article_template = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [
        [
          'uuid' => self::UUID_STATIC_CTA,
          'component_id' => 'sdc.canvas_test_sdc.my-cta',
          'component_version' => '89881c04a0fde367',
          'inputs' => [
            'text' => 'Powered by Drupal Canvas',
            'href' => 'https://drupal.org/project/canvas',
          ],
        ],
        // A component populated by an entity base field.
        [
          'uuid' => self::UUID_DYNAMIC_CTA,
          'component_id' => 'sdc.canvas_test_sdc.my-cta',
          'component_version' => '89881c04a0fde367',
          'inputs' => [
            'text' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
            ],
            'href' => [
              'sourceType' => PropSource::HostEntityUrl->value,
              'absolute' => TRUE,
            ],
          ],
        ],
      ],
    ]);
    $violations = $article_template->getTypedData()->validate();
    self::assertSame([], self::violationsToArray($violations), $article_template->getConfigTarget());
    $article_template->save();

    // Save the correct, optimal LanguageConfigOverride. Testing how that is
    // generated is out of scope here; that's for a kernel test.
    // @see \Drupal\Tests\canvas\Kernel\Config\ContentTemplateTest::testTranslation()
    $language_manager = $this->container->get(LanguageManagerInterface::class);
    self::assertInstanceOf(ConfigurableLanguageManagerInterface::class, $language_manager);
    $override = $language_manager->getLanguageConfigOverride('fr', $article_template->getConfigDependencyName());
    self::assertTrue($override->isNew());
    self::assertSame([], $override->getRawData());
    $override->setData([
      'component_tree' => [
        self::UUID_STATIC_CTA => [
          'inputs' => [
            'text' => 'Propulsé par Drupal Canvas',
          ],
        ],
      ],
    ]);
    $override->save();

    // Display the `field_canvas_test` field.
    \Drupal::service('entity_display.repository')
      ->getViewDisplay('node', 'article')
      ->setComponent('field_canvas_test', [
        'label' => 'hidden',
        'type' => 'canvas_naive_render_sdc_tree',
      ])
      ->save();

    $page = $this->getSession()->getPage();
    $this->drupalLogin($this->rootUser);
    $this->drupalGet('admin/config/regional/language');
    $this->clickLink('Add language');
    $page->selectFieldOption('predefined_langcode', 'fr');
    $page->pressButton('Add language');
    $this->assertSession()->pageTextContains('The language French has been created and can now be used.');
    // Rebuild the container so that the new languages are picked up by services
    // that hold a list of languages.
    $this->rebuildContainer();
    $this->enableContentTranslation('node', 'article');
  }

  /**
   * Tests loading of ContentTemplate translations.
   *
   * @see \Drupal\Tests\canvas\Kernel\Config\ContentTemplateTest::testTranslationLifeCycleInDepth()
   */
  public function testContentTemplateTranslationRendered(): void {
    $template = ContentTemplate::load('node.article.full');
    self::assertNotNull($template);
    $template->setStatus(TRUE)->save();

    $original_node = $this->createCanvasNodeWithTranslation();
    $this->assertTrue($original_node->isDefaultTranslation());
    $translated_node = $original_node->getTranslation('fr');
    $this->assertSame('The French title', (string) $translated_node->getTitle());

    // The content template component instance string is English, not French.
    $this->drupalGet($original_node->toUrl());
    $original_page = $this->getSession()->getPage();
    // A component instance with:
    // - a translatable prop: `text`
    // - an untranslatable prop: `href`
    self::assertSame('https://drupal.org/project/canvas', $original_page->findLink('Powered by Drupal Canvas')?->getAttribute('href'));
    self::assertNull($original_page->findLink('Propulsé par Drupal Canvas'));
    // A component instance with:
    // - an EntityFieldPropSource (`text`)
    // - a HostEntityUrlPropSource (`href`)
    self::assertSame($GLOBALS['base_url'] . '/node/1', $original_page->findLink((string) $original_node->getTitle())?->getAttribute('href'));
    self::assertNull($original_page->findLink((string) $translated_node->getTitle()));

    $this->drupalGet($translated_node->toUrl());
    $translated_page = $this->getSession()->getPage();
    // A component instance with:
    // - `text`: translated StaticPropSource, stored in LanguageConfigOverride
    // - `href`: the original ("default translation") value is inherited/merged
    // @see \Drupal\Core\Config\ConfigFactory::loadOverrides()
    self::assertNull($translated_page->findLink('Powered by Drupal Canvas'));
    $canvas_link = $translated_page->findLink('Propulsé par Drupal Canvas');
    self::assertNotNull($canvas_link);
    self::assertSame('https://drupal.org/project/canvas', $canvas_link->getAttribute('href'));
    // A component instance with:
    // - an EntityFieldPropSource (`text`) with the translated node's value
    //   automatically fetched thanks to automatic translation loading
    // - a HostEntityUrlPropSource (`href`) with the translated node's URL (this
    //   is the requested URL)
    // @see \Drupal\Core\Entity\EntityRepositoryInterface::getTranslationFromContext()
    self::assertNull($translated_page->findLink((string) $original_node->getTitle()));
    $node_link = $translated_page->findLink((string) $translated_node->getTitle());
    self::assertNotNull($node_link);
    self::assertSame($GLOBALS['base_url'] . '/fr/node/1', $node_link->getAttribute('href'));

    // Assert order of component instances.
    $html = $translated_page->getHtml();
    $translated_canvas_link_html = $canvas_link->getOuterHtml();
    $translated_node_link_html = $node_link->getOuterHtml();
    self::assertTrue(strpos($html, $translated_canvas_link_html) < strpos($html, $translated_node_link_html));

    // Reorder the component instances to test the effect on the loading of
    // translations.
    $tree = $template->getComponentTree();
    self::assertSame([
      self::UUID_STATIC_CTA,
      self::UUID_DYNAMIC_CTA,
    ], \array_column($template->get('component_tree'), 'uuid'));
    $component_instances = $tree->getValue();
    self::assertTrue(\array_is_list($component_instances));
    $template->setComponentTree(\array_reverse($component_instances))->save();
    self::assertSame([
      self::UUID_DYNAMIC_CTA,
      self::UUID_STATIC_CTA,
    ], \array_column($template->get('component_tree'), 'uuid'));

    // The updated component reorder is also visible on the French translation,
    // and the LanguageConfigOverride targeting a particular explicit input of a
    // particular component instance still works.
    $this->drupalGet($translated_node->toUrl());
    $html = $this->getSession()->getPage()->getHtml();
    // Assert order of component instances.
    self::assertTrue(strpos($html, $translated_canvas_link_html) > strpos($html, $translated_node_link_html));

    $language_manager = $this->container->get(LanguageManagerInterface::class);
    self::assertInstanceOf(ConfigurableLanguageManagerInterface::class, $language_manager);
    $override = $language_manager->getLanguageConfigOverride('fr', $template->getConfigDependencyName());
    self::assertSame([self::UUID_STATIC_CTA], \array_keys($override->getRawData()['component_tree']));
  }

  /**
   * Tests config translation UI with mixed component instance input types.
   *
   * @see \Drupal\Tests\canvas\Kernel\Config\ContentTemplateTest::testTranslationLifeCycleInDepth()
   * @see \Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource\ComponentSourceTestBase::testGetTranslatableInputKeys()
   * @see \Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource\ComponentSourceTestBase::providerSymmetricallyTranslatableComponentInstanceScenarios()
   */
  public function testContentTemplateConfigTranslationUi(): void {
    $module_installer = $this->container->get('module_installer');
    \assert($module_installer instanceof ModuleInstallerInterface);
    if (!$this->container->get('module_handler')->moduleExists('config_translation')) {
      $module_installer->install(['config_translation']);
      $this->rebuildContainer();
      $module_installer = $this->container->get('module_installer');
      \assert($module_installer instanceof ModuleInstallerInterface);
    }

    // 1. SETUP: create a fresh ContentTemplate with mixed component types.
    $banner = Component::load('sdc.canvas_test_sdc.banner');
    $my_hero = Component::load('sdc.canvas_test_sdc.my-hero');
    $branding_block = Component::load('block.system_branding_block');
    \assert($banner instanceof Component);
    \assert($my_hero instanceof Component);
    \assert($branding_block instanceof Component);

    $banner_version = $banner->getActiveVersion();
    $my_hero_version = $my_hero->getActiveVersion();
    $branding_block_version = $branding_block->getActiveVersion();
    $banner->loadVersion($banner_version);
    $my_hero->loadVersion($my_hero_version);
    $branding_block->loadVersion($branding_block_version);

    $existing_template = ContentTemplate::load('node.article.full');
    if ($existing_template instanceof ContentTemplate) {
      $existing_template->delete();
    }

    $template = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => self::populateActiveComponentVersionPlaceholders([
        [
          'uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1',
          'component_id' => 'sdc.canvas_test_sdc.banner',
          'component_version' => '::ACTIVE_VERSION_IN_SUT::',
          'inputs' => [
            'heading' => 'Welcome',
            'text' => [
              'value' => '<p>Hello</p>',
              'format' => 'canvas_html_block',
            ],
          ],
        ],
        [
          'uuid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb2',
          'component_id' => 'sdc.canvas_test_sdc.my-hero',
          'component_version' => '::ACTIVE_VERSION_IN_SUT::',
          'inputs' => [
            'heading' => 'Welcome to Canvas',
            // ⚠️ `subheading` is optional and not populated, but should still
            // be translatable.
            // @see \Drupal\canvas\ConfigTranslation\CanvasComponentTreeItemInputsMappingFormElement
            'cta1' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
            ],
            'cta1href' => [
              'sourceType' => PropSource::HostEntityUrl->value,
              'absolute' => TRUE,
            ],
            'cta2' => 'Learn more',
          ],
        ],
        [
          'uuid' => 'cccccccc-cccc-4ccc-8ccc-ccccccccccc3',
          'component_id' => 'block.system_branding_block',
          'component_version' => '::ACTIVE_VERSION_IN_SUT::',
          'inputs' => [
            'label' => 'Branding',
            'label_display' => 'visible',
            'use_site_logo' => TRUE,
            'use_site_name' => TRUE,
            'use_site_slogan' => FALSE,
          ],
        ],
      ]),
    ]);
    $violations = $template->getTypedData()->validate();
    self::assertSame([], self::violationsToArray($violations), $template->getConfigTarget());
    $template->save();

    $config_name = 'canvas.content_template.node.article.full';
    $translation_path = '/admin/structure/content-template/node.article.full/translate/fr/add';
    $field_name_prefix = "translation[config_names][$config_name][component_tree]";
    $field = static fn (string $suffix): string => $field_name_prefix . $suffix;

    // 2. Confirm Templates are not translatable via the UI without
    // `canvas_dev_translation` enabled.
    $this->drupalLogin($this->rootUser);
    $this->drupalGet($translation_path);
    $assert_session = $this->assertSession();
    $assert_session->statusCodeEquals(404);

    if (!$this->container->get('module_handler')->moduleExists('canvas_dev_translation')) {
      $module_installer->install(['canvas_dev_translation']);
      $this->rebuildContainer();
    }

    // 3. Confirm Templates are translatable via the UI once
    // `canvas_dev_translation` is enabled.
    $this->drupalGet($translation_path);
    $assert_session = $this->assertSession();
    $assert_session->statusCodeEquals(200);

    // 4. ASSERTIONS: verify rendered translatable/non-translatable fields.
    $assert_session->fieldExists($field('[aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1][inputs][heading][0][value]'));
    $assert_session->fieldExists($field('[aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1][inputs][text][0][value]'));
    $assert_session->elementExists(
      'css',
      'input[type="hidden"][name="' . $field('[aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1][inputs][text][0][format]') . '"][value="canvas_html_block"]',
    );

    // My-hero: static props should exist
    $assert_session->fieldExists($field('[bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb2][inputs][heading][0][value]'));
    $assert_session->fieldExists($field('[bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb2][inputs][cta2][0][value]'));

    // My-hero: non-static source props should NOT exist
    $assert_session->fieldNotExists($field('[bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb2][inputs][cta1]'));
    $assert_session->fieldNotExists($field('[bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb2][inputs][cta1][0][value]'));
    $assert_session->fieldNotExists($field('[bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb2][inputs][cta1href]'));
    $assert_session->fieldNotExists($field('[bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb2][inputs][cta1href][0][uri]'));

    // My-hero: optional prop NOT in default SHOULD render: the translation of
    // the component instance may opt to use it.
    // @see \Drupal\canvas\ConfigTranslation\CanvasComponentTreeItemInputsMappingFormElement
    $assert_session->fieldExists($field('[bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb2][inputs][subheading][0][value]'));

    $assert_session->fieldExists($field('[cccccccc-cccc-4ccc-8ccc-ccccccccccc3][inputs][label]'));

    $assert_session->fieldNotExists($field('[cccccccc-cccc-4ccc-8ccc-ccccccccccc3][inputs][label_display]'));
    $assert_session->fieldNotExists($field('[cccccccc-cccc-4ccc-8ccc-ccccccccccc3][inputs][use_site_logo]'));
    $assert_session->fieldNotExists($field('[cccccccc-cccc-4ccc-8ccc-ccccccccccc3][inputs][use_site_name]'));
    $assert_session->fieldNotExists($field('[cccccccc-cccc-4ccc-8ccc-ccccccccccc3][inputs][use_site_slogan]'));

    // 5. SUBMIT: provide French translations in a single form submission.
    $edit = [
      $field('[aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1][inputs][heading][0][value]') => 'Welcome',
      $field('[aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1][inputs][text][0][value]') => '<p>Bonjour</p>',
      $field('[bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb2][inputs][heading][0][value]') => 'Bienvenue à Canvas',
      $field('[bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb2][inputs][cta2][0][value]') => 'En savoir plus',
      $field('[bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb2][inputs][subheading][0][value]') => 'Découvrez Canvas',
      $field('[cccccccc-cccc-4ccc-8ccc-ccccccccccc3][inputs][label]') => 'Identité visuelle',
    ];
    $this->submitForm($edit, 'Save translation');
    $assert_session->pageTextContains('Successfully saved French translation');

    // 6. VERIFY: ensure the exact expected LanguageConfigOverride is stored.
    $language_manager = $this->container->get(LanguageManagerInterface::class);
    self::assertInstanceOf(ConfigurableLanguageManagerInterface::class, $language_manager);
    $override = $language_manager->getLanguageConfigOverride('fr', $config_name);
    self::assertFalse($override->isNew());
    self::assertSame([
      'component_tree' => [
        'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1' => [
          'inputs' => [
            'text' => [
              'value' => '<p>Bonjour</p>',
            ],
          ],
        ],
        'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb2' => [
          'inputs' => [
            'heading' => 'Bienvenue à Canvas',
            'subheading' => 'Découvrez Canvas',
            'cta2' => 'En savoir plus',
          ],
        ],
        'cccccccc-cccc-4ccc-8ccc-ccccccccccc3' => [
          'inputs' => [
            'label' => 'Identité visuelle',
          ],
        ],
      ],
    ], $override->getRawData());

    self::assertArrayNotHasKey('heading', $override->getRawData()['component_tree']['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1']['inputs']);
    self::assertArrayNotHasKey(3, $override->getRawData()['component_tree']);
  }

  /**
   * Data provider for testCanvasFieldTranslation().
   *
   * @return array<array{0: array, 1: bool}>
   */
  public static function canvasFieldTranslationDataProvider(): array {
    return [
      // In the symmetric case, the 'tree' property is not translatable. This
      // means every translation has the same components but can have different
      // properties.
      'symmetric' => [['inputs'], TRUE],
      // In the asymmetric case, both 'tree' and 'inputs' properties are
      // translatable. This means every translation can have different components
      // and properties for those components. There no connection at all between
      // the components in the different translations.
      'asymmetric' => [['tree', 'inputs'], FALSE],
      // This case tests when the field is not translatable, but it is used on
      // an entity that has translations. In this case, the components and their
      // properties are shared between the translations.
      'not translatable' => [[], TRUE],
    ];
  }

  /**
   * Tests translating the Canvas field.
   *
   * @param array<string> $translatable_properties
   *   The properties on the Canvas field that should be
   *   translatable.
   * @param bool $expect_component_removed_on_translation
   *   Whether the last component in Canvas tree is expected to be removed from the
   *   translation. The component is always removed from the default
   *   translation.
   */
  #[DataProvider('canvasFieldTranslationDataProvider')]
  public function testCanvasFieldTranslation(array $translatable_properties, bool $expect_component_removed_on_translation): void {
    $page = $this->getSession()->getPage();
    $assert_session = $this->assertSession();
    $language_manager = $this->container->get(LanguageManagerInterface::class);
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);

    // Content template translation exists.
    $override = $language_manager->getLanguageConfigOverride('fr', 'canvas.content_template.node.article.full');
    $this->assertFalse($override->isNew());
    // But content template is disabled.
    $template = ContentTemplate::load('node.article.full');
    self::assertNotNull($template);
    self::assertFalse($template->status());

    $field_is_translatable = !empty($translatable_properties);

    $this->drupalGet('admin/config/regional/content-language');
    if ($field_is_translatable) {
      $page->checkField('settings[node][article][fields][field_canvas_test]');
      foreach (['tree', 'inputs'] as $field_property) {
        \in_array($field_property, $translatable_properties, TRUE)
          ? $page->checkField("settings[node][article][columns][field_canvas_test][$field_property]")
          : $page->uncheckField("settings[node][article][columns][field_canvas_test][$field_property]");
      }
    }
    else {
      $page->uncheckField('settings[node][article][fields][field_canvas_test]');
    }

    $page->pressButton('Save configuration');
    $this->assertSession()->pageTextContains('Settings successfully updated.');
    $original_node = $this->createCanvasNodeWithTranslation();
    $this->assertTrue($original_node->isDefaultTranslation());
    $translated_node = $original_node->getTranslation('fr');
    $this->assertSame('The French title', (string) $translated_node->getTitle());

    $this->drupalGet($original_node->toUrl());
    $hero_component = $assert_session->elementExists('css', '[data-component-id="canvas_test_sdc:my-hero"]');

    // Confirm the translated property is not on the page anywhere.
    $assert_session->pageTextNotContains('bonjour');
    // Confirm the first hero component does not use the translated properties
    // because it uses a StaticPropSource.
    $this->assertSame('hello, new world!', $hero_component->find('css', 'h1')?->getText());
    // Confirm the heading has been removed from display. This was changed on
    // the default translation.
    $assert_session->elementsCount('css', '[data-component-id="canvas_test_sdc:heading"]', 0);

    $this->drupalGet($translated_node->toUrl());
    $assert_session->elementTextEquals('css', '#block-stark-page-title h1', 'The French title');

    $hero_component = $assert_session->elementExists('css', '[data-component-id="canvas_test_sdc:my-hero"]');
    if ($field_is_translatable) {
      // If the field is translatable updating inputs in the default translation
      // should not have updated the French translation.
      $this->assertSame('bonjour, monde!', $hero_component->find('css', 'h1')?->getText());
      $assert_session->pageTextNotContains('hello, new world!');
    }
    else {
      // If the field is not translatable updating inputs in the default translation
      // should have also updated the French translation.
      $assert_session->pageTextNotContains('bonjour');
      $this->assertSame('hello, new world!', $hero_component->find('css', 'h1')?->getText());
    }

    // Confirm the heading component has been removed or not based the test case
    // expectation.
    $assert_session->elementsCount(
      'css',
      '[data-component-id="canvas_test_sdc:heading"]',
      $expect_component_removed_on_translation ? 0 : 1
    );

    // Verify the `name` for a single component instance is only present on the
    // original translation — both in the server-side storage, and in the
    // information provided to the client for the UI.
    $get_name = function (NodeInterface $node): ?string {
      $component_tree = $node->get('field_canvas_test');
      \assert($component_tree instanceof ComponentTreeItemList);
      return $component_tree->getComponentTreeItemByUuid('208452de-10d6-4fb8-89a1-10e340b3744c')?->getLabel();
    };
    // If the field is not translatable updating inputs in the French
    // translation should have also updated the default translation.
    $expected_original_label = $field_is_translatable ? 'Starring … Drupal as the hero! 🤩' : "Drupal, c'est magnifique !";
    self::assertSame($expected_original_label, $get_name($original_node));
    self::assertSame("Drupal, c'est magnifique !", $get_name($translated_node));
    $get_name_in_api_response = function (string $root_relative_url): ?string {
      $response = $this->makeApiRequest('GET', Url::fromUri("base:$root_relative_url"), []);
      self::assertSame(200, $response->getStatusCode());
      $layout = json_decode((string) $response->getBody(), TRUE)['layout'];
      return $layout[0]['components'][0]['slots'][0]['components'][0]['name'];
    };
    self::assertSame($expected_original_label, $get_name_in_api_response('/canvas/api/v0/layout/node/1'));
    self::assertSame("Drupal, c'est magnifique !", $get_name_in_api_response('/fr/canvas/api/v0/layout/node/1'));
  }

  /**
   * Creates an article node with a translation.
   *
   * @return \Drupal\node\Entity\Node
   *   The default translation of the node.
   */
  protected function createCanvasNodeWithTranslation(): Node {
    $node = $this->createTestNode();
    $list = $node->get('field_canvas_test');
    \assert($list instanceof ComponentTreeItemList);
    // There are five items in the default values for this field.
    self::assertEquals(5, $list->count());

    // Create a translation from the original English node.
    $translation = $node->addTranslation('fr');
    $this->assertInstanceOf(Node::class, $translation);
    $this->container->get('content_translation.manager')->getTranslationMetadata($translation)->setSource($node->language()->getId());
    // @phpstan-ignore-next-line
    $translation->title = 'The French title';
    $translation->save();
    $translation = $node->getTranslation('fr');
    $updated_item = $list->getComponentTreeItemByUuid('208452de-10d6-4fb8-89a1-10e340b3744c');
    \assert($updated_item instanceof ComponentTreeItem);
    $updated_item_inputs = $updated_item->getInputs();

    // In both the Symmetric and Asymmetric translation cases, the `inputs` and
    // `label` field properties are translatable and this should only change the
    // translation.
    $french_inputs = $updated_item_inputs;
    $french_inputs['heading'] = 'bonjour, monde!';
    $french_list = $translation->get('field_canvas_test');
    \assert($french_list instanceof ComponentTreeItemList);
    $french_item = $french_list->getComponentTreeItemByUuid('208452de-10d6-4fb8-89a1-10e340b3744c');
    \assert($french_item instanceof ComponentTreeItem);
    $french_item->setInput($french_inputs)
      ->setLabel("Drupal, c'est magnifique !");
    $translation->save();

    // Update the English version.
    $updated_item_inputs['heading'] = 'hello, new world!';
    // In both the Symmetric and Asymmetric cases, the `inputs` property is
    // translatable and this should only change the original. If the field is
    // not translatable, this should change both the original and the
    // translation.
    $updated_item->setInput($updated_item_inputs);
    // Remove the heading from the tree.
    // In the asymmetric case, where 'tree' is translatable, this should only
    // affect the untranslated node.
    // In the symmetric case, where 'tree' is not translatable, this should
    // change both the original and the translation.
    $delta_to_remove = $list->getComponentTreeDeltaByUuid('e660e407-0901-4639-9726-9f99bc250c4c');
    \assert(\is_int($delta_to_remove));
    $list->removeItem($delta_to_remove);
    $node->save();
    return $node;
  }

}
