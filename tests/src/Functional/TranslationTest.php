<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

// cspell:ignore magnifique Propulsé Bienvenue savoir Découvrez Identité visuelle Nœud prévisualisation Bonjour région utilisant नोड

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\canvas\PropSource\PropSource;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Url;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\ApiRequestTrait;
use Drupal\Tests\canvas\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\canvas\Traits\DataProviderWithComponentTreeTrait;
use Drupal\Tests\content_translation\Traits\ContentTranslationTestTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Response;

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

    // Add Hindi with no translations — used for fallback testing.
    ConfigurableLanguage::createFromLangcode('hi')->save();
    $this->rebuildContainer();
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

  /**
   * Returns the active version string for the heading SDC component.
   */
  private function getHeadingComponentVersion(): string {
    $component = $this->container->get('entity_type.manager')
      ->getStorage('component')
      ->load('sdc.canvas_test_sdc.heading');
    \assert($component instanceof Component);
    return $component->getActiveVersion();
  }

  /**
   * Creates a canvas_page entity with English and French translations.
   *
   * Also enables content translation for canvas_page entities, which is
   * required before creating translated canvas_page instances.
   *
   * @return \Drupal\canvas\Entity\Page
   *   The saved Page entity (default/English translation).
   */
  private function createCanvasTranslationTestPage(): Page {
    $content_language_settings = ContentLanguageSettings::loadByEntityTypeBundle('canvas_page', 'canvas_page');
    $content_language_settings
      ->setDefaultLangcode(LanguageInterface::LANGCODE_SITE_DEFAULT)
      ->setLanguageAlterable(TRUE)
      ->save();
    $this->container->get('content_translation.manager')->setEnabled('canvas_page', 'canvas_page', TRUE);
    $this->container->get('router.builder')->setRebuildNeeded();

    $version = $this->getHeadingComponentVersion();

    $page = Page::create([
      'title' => 'Canvas Translation Test Page',
      'path' => '/canvas-translation-test',
      'status' => TRUE,
      'components' => [
        [
          'uuid' => '11111111-1111-4111-8111-111111111111',
          'component_id' => 'sdc.canvas_test_sdc.heading',
          'component_version' => $version,
          'inputs' => [
            'text' => 'Hello, Canvas!',
            'element' => 'h1',
          ],
          'label' => 'English heading',
        ],
      ],
    ]);
    $page->save();

    $fr_page = $page->addTranslation('fr');
    $fr_page->set('title', 'Page de test Canvas');
    $fr_page->set('components', $page->get('components')->getValue());
    $fr_tree = $fr_page->getComponentTree();
    \assert($fr_tree instanceof ComponentTreeItemList);
    $fr_item = $fr_tree->getComponentTreeItemByUuid('11111111-1111-4111-8111-111111111111');
    \assert($fr_item !== NULL);
    $fr_item->setInput(['text' => 'Bonjour, Canvas!', 'element' => 'h1'])
      ->setLabel('French heading');
    $fr_page->save();

    return $page;
  }

  /**
   * Creates a PageRegion for the default theme with a French language override.
   *
   * @return \Drupal\canvas\Entity\PageRegion
   *   The saved PageRegion entity.
   */
  private function createPageRegionWithFrenchOverride(): PageRegion {
    $version = $this->getHeadingComponentVersion();

    $default_theme = $this->container->get('theme_handler')->getDefault();
    $regions = PageRegion::createFromBlockLayout($default_theme);
    $new_region = reset($regions);
    \assert($new_region instanceof PageRegion);
    $existing = $this->container->get('entity_type.manager')
      ->getStorage(PageRegion::ENTITY_TYPE_ID)
      ->load($new_region->id());
    $region = $existing instanceof PageRegion ? $existing : $new_region;
    $region->set('component_tree', [
      [
        'uuid' => '33333333-3333-4333-8333-333333333333',
        'component_id' => 'sdc.canvas_test_sdc.heading',
        'component_version' => $version,
        'inputs' => [
          'text' => 'Hello from region',
          'element' => 'h3',
        ],
        'label' => 'English region heading',
      ],
    ]);
    $region->enable()->save();

    $language_manager = $this->container->get(LanguageManagerInterface::class);
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);
    $region_override = $language_manager->getLanguageConfigOverride('fr', $region->getConfigDependencyName());
    $region_override->setData([
      'component_tree' => [
        '33333333-3333-4333-8333-333333333333' => [
          'label' => 'French region heading',
          'inputs' => ['text' => 'Bonjour de la région'],
        ],
      ],
    ])->save();

    return $region;
  }

  /**
   * Tests that the layout API returns translated content from language-prefixed routes when canvas_dev_translation is enabled.
   *
   * @todo This might just be temporary test until we have a Playwright test
   *    that test this functionality with the translation preview.
   */
  public function testCanvasDevTranslationLayoutApi(): void {
    // Delete the content template created in setUp() — this test needs a
    // heading-based template with explicit labels to assert translation.
    $existing_template = ContentTemplate::load('node.article.full');
    if ($existing_template instanceof ContentTemplate) {
      $existing_template->delete();
    }

    $module_installer = $this->container->get(ModuleInstallerInterface::class);
    $module_installer->install(['canvas_dev_translation']);
    $this->rebuildContainer();

    $page = $this->createCanvasTranslationTestPage();
    $this->createPageRegionWithFrenchOverride();
    $page_id = $page->id();

    // Create a ContentTemplate for node/article/full with French language
    // override, so that ContentTemplate translation can be tested.
    $version = $this->getHeadingComponentVersion();
    $template = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'status' => TRUE,
      'component_tree' => [
        [
          'uuid' => '22222222-2222-4222-8222-222222222222',
          'component_id' => 'sdc.canvas_test_sdc.heading',
          'component_version' => $version,
          'inputs' => [
            'text' => 'Hello from template',
            'element' => 'h2',
          ],
          'label' => 'English template heading',
        ],
        [
          'uuid' => '22222222-2222-4222-8221-222222222222',
          'component_id' => 'sdc.canvas_test_sdc.heading',
          'component_version' => $version,
          'inputs' => [
            'text' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
            ],
            'element' => 'h2',
          ],
          'label' => 'English dynamic heading',
        ],
      ],
    ]);
    $template->save();
    $language_manager = $this->container->get(LanguageManagerInterface::class);
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);
    $template_override = $language_manager->getLanguageConfigOverride('fr', $template->getConfigDependencyName());
    $template_override->setData([
      'component_tree' => [
        '22222222-2222-4222-8222-222222222222' => [
          'label' => 'French template heading',
          'inputs' => ['text' => 'Bonjour du template'],
        ],
      ],
    ])->save();

    // Helper: returns the first component's name from the main content region.
    $get_name_in_api_response = function (string $root_relative_url): ?string {
      $response = $this->makeApiRequest('GET', Url::fromUri("base:$root_relative_url"), []);
      self::assertSame(200, $response->getStatusCode());
      $layout = json_decode((string) $response->getBody(), TRUE)['layout'];
      // The layout may contain multiple regions; find the 'content' region by
      // its id rather than relying on array position.
      $content_region = current(array_filter($layout, fn($r) => $r['id'] === 'content'));
      return $content_region['components'][0]['name'];
    };

    // Helper: returns the first component's name from the first non-content
    // region (the PageRegion created by canvas_dev_translation hook_install()).
    $get_region_name_in_api_response = function (string $root_relative_url): ?string {
      $response = $this->makeApiRequest('GET', Url::fromUri("base:$root_relative_url"), []);
      self::assertSame(200, $response->getStatusCode());
      $layout = json_decode((string) $response->getBody(), TRUE)['layout'];
      $page_region = current(array_filter($layout, fn($r) => $r['id'] !== 'content'));
      return $page_region['components'][0]['name'];
    };

    // Assert the canvas_page layout API returns the correct translation per
    // language prefix.
    self::assertSame('English heading', $get_name_in_api_response("/canvas/api/v0/layout/canvas_page/$page_id"));
    self::assertSame('French heading', $get_name_in_api_response("/fr/canvas/api/v0/layout/canvas_page/$page_id"));
    // Language that does not have translations enabled should fallback to default language.
    self::assertSame('English heading', $get_name_in_api_response("/hi/canvas/api/v0/layout/canvas_page/$page_id"));

    // Assert the PageRegion layout API returns the correct translation per
    // language prefix.
    self::assertSame('English region heading', $get_region_name_in_api_response("/canvas/api/v0/layout/canvas_page/$page_id"));
    self::assertSame('French region heading', $get_region_name_in_api_response("/fr/canvas/api/v0/layout/canvas_page/$page_id"));
    // Language that does not have translations enabled should fallback to default language.
    self::assertSame('English region heading', $get_region_name_in_api_response("/hi/canvas/api/v0/layout/canvas_page/$page_id"));

    // Create an article node with English and French translations to use as the
    // ContentTemplate preview entity. The French translation has a distinct
    // title so we can assert language-aware field resolution.
    $node_storage = $this->container->get('entity_type.manager')->getStorage('node');
    $node = $node_storage->create([
      'type' => 'article',
      'title' => 'Preview node',
      'status' => 1,
    ]);
    $node->save();
    $fr_node = $node->addTranslation('fr');
    $fr_node->set('title', 'Nœud de prévisualisation');
    $fr_node->save();
    $node_id = $node->id();

    // Assert the ContentTemplate layout API returns the correct translation per
    // language prefix (component label from LanguageConfigOverride).
    self::assertSame('English template heading', $get_name_in_api_response("/canvas/api/v0/layout-content-template/node.article.full/$node_id"));
    self::assertSame('French template heading', $get_name_in_api_response("/fr/canvas/api/v0/layout-content-template/node.article.full/$node_id"));
    // Language that does not have translations enabled should fallback to default language.
    self::assertSame('English template heading', $get_name_in_api_response("/hi/canvas/api/v0/layout-content-template/node.article.full/$node_id"));

    // Assert the entity field prop source (second component, UUID
    // '22222222-2222-4222-8221-222222222222') resolves the node title in the
    // correct language based on the language prefix in the URL.
    $get_resolved_title_in_api_response = function (string $root_relative_url): mixed {
      $response = $this->makeApiRequest('GET', Url::fromUri("base:$root_relative_url"), []);
      self::assertSame(200, $response->getStatusCode());
      return json_decode((string) $response->getBody(), TRUE)['model']['22222222-2222-4222-8221-222222222222']['resolved']['text'];
    };
    self::assertSame('Preview node', $get_resolved_title_in_api_response("/canvas/api/v0/layout-content-template/node.article.full/$node_id"));
    self::assertSame('Nœud de prévisualisation', $get_resolved_title_in_api_response("/fr/canvas/api/v0/layout-content-template/node.article.full/$node_id"));

    // Add a Hindi node translation to test fallback behavior when the
    // ContentTemplate has no Hindi translation. The template label should
    // fall back to English, but EntityFieldPropSource should resolve to the
    // Hindi node field value.
    $hi_node = $node->addTranslation('hi');
    $hi_node->set('title', 'नोड');
    $hi_node->save();

    // Assert ContentTemplate label falls back to English when no Hindi
    // translation exists.
    self::assertSame('English template heading', $get_name_in_api_response("/hi/canvas/api/v0/layout-content-template/node.article.full/$node_id"));

    // Assert EntityFieldPropSource resolves to the Hindi node title, even
    // though the ContentTemplate has no Hindi translation.
    self::assertSame('नोड', $get_resolved_title_in_api_response("/hi/canvas/api/v0/layout-content-template/node.article.full/$node_id"));
  }

  /**
   * Tests canvas_page translation on /fr/page/{id}.
   *
   * Verifies that visiting /fr/page/{id} displays the French translation
   * of the canvas_page.
   */
  public function testTranslationForPage(): void {
    $module_installer = $this->container->get(ModuleInstallerInterface::class);
    $module_installer->install(['canvas_dev_translation']);
    $this->rebuildContainer();

    $page = $this->createCanvasTranslationTestPage();
    $this->createPageRegionWithFrenchOverride();
    $page_id = $page->id();

    // Retrieve the French language object for constructing the /fr/ URL.
    $language_manager = $this->container->get(LanguageManagerInterface::class);
    self::assertInstanceOf(ConfigurableLanguageManagerInterface::class, $language_manager);
    $fr_language = $language_manager->getLanguage('fr');
    self::assertNotNull($fr_language, 'French language must exist.');

    $english_page_url = Url::fromRoute('entity.canvas_page.canonical', ['canvas_page' => $page_id]);
    $french_page_url = Url::fromRoute('entity.canvas_page.canonical', ['canvas_page' => $page_id], ['language' => $fr_language]);

    // Visit the French page URL.
    $this->drupalGet($french_page_url);
    $this->assertSession()->statusCodeEquals(200);

    // The French translation of the canvas_page should be displayed.
    $this->assertSession()->pageTextContains('Bonjour, Canvas!');
    $this->assertSession()->pageTextContains('Bonjour de la région');
    $this->assertSession()->pageTextNotContains('Hello, Canvas!');

    // Visit the English page URL to verify the original content.
    $this->drupalGet($english_page_url);
    $this->assertSession()->statusCodeEquals(200);

    // The English (default) translation should be displayed.
    $this->assertSession()->pageTextContains('Hello, Canvas!');
    $this->assertSession()->pageTextNotContains('Bonjour, Canvas!');
  }

  /**
   * Tests that /fr/node/{nid} uses the French ContentTemplate translation.
   *
   * Visiting a non-default translation URL (e.g., French-language URL) applies
   * the French ContentTemplate LanguageConfigOverride regardless of whether the
   * node itself has a French translation. EntityFieldPropSource values follow
   * the node's own translation availability: if no French node translation
   * exists, the English node field value is used; once a French translation is
   * added, the French field value is used.
   */
  public function testNonDefaultLanguageNodePathContentTemplateTranslation(): void {
    $template = ContentTemplate::load('node.article.full');
    self::assertNotNull($template);
    $template->setStatus(TRUE)->save();

    $language_manager = $this->container->get(LanguageManagerInterface::class);
    self::assertInstanceOf(ConfigurableLanguageManagerInterface::class, $language_manager);
    $fr_language = $language_manager->getLanguage('fr');
    self::assertNotNull($fr_language, 'French language must exist.');

    // Create a plain English-only article node (no French translation).
    $node = $this->createTestNode();
    $nid = $node->id();
    $english_title = (string) $node->getTitle();
    self::assertSame('The first entity using Canvas!', $english_title);

    // Build both URL variants for convenience.
    $english_url = $node->toUrl();
    $french_url = Url::fromRoute('entity.node.canonical', ['node' => $nid], ['language' => $fr_language]);

    $this->drupalGet($french_url);
    $this->assertSession()->statusCodeEquals(200);
    $page = $this->getSession()->getPage();

    // The static CTA text is overridden by the French LanguageConfigOverride on
    // the ContentTemplate, so the French text must appear and the English text
    // must not.
    $french_canvas_link = $page->findLink('Propulsé par Drupal Canvas');
    self::assertNotNull(
      $french_canvas_link,
      'French ContentTemplate translation must be applied on /fr/ URL (static prop "Propulsé par Drupal Canvas" not found).',
    );
    self::assertNull(
      $page->findLink('Powered by Drupal Canvas'),
      'English static CTA text must not appear when visiting the French URL.',
    );

    // The static CTA uses a plain string href ('https://drupal.org/…'), not a
    // HostEntityUrlPropSource, so it must remain unchanged regardless of
    // language context.
    self::assertSame(
      'https://drupal.org/project/canvas',
      $french_canvas_link->getAttribute('href'),
      'The static CTA href must remain unchanged on the French URL.',
    );

    // The dynamic CTA (UUID_DYNAMIC_CTA) reads `text` from the node title via
    // EntityFieldPropSource. Because there is no French node translation yet,
    // it should display the English node title (the only available translation).
    $dynamic_cta_english = $page->findLink($english_title);
    self::assertNotNull(
      $dynamic_cta_english,
      'Dynamic CTA must show the English node title when no French node translation exists.',
    );

    // The HostEntityUrlPropSource on the dynamic CTA resolves the entity's own
    // URL. Because no French translation of the node exists, Drupal generates
    // the canonical URL without the /fr/ prefix — the entity only has an English
    // version.
    // @see \Drupal\Core\Entity\EntityRepositoryInterface::getTranslationFromContext()
    self::assertSame(
      $GLOBALS['base_url'] . '/node/' . $nid,
      $dynamic_cta_english->getAttribute('href'),
      'Dynamic CTA href must be the default-language node URL when no French node translation exists.',
    );

    // Add a French translation to the existing node.
    $node_fresh = Node::load($nid);
    self::assertNotNull($node_fresh);
    $fr_translation = $node_fresh->addTranslation('fr');
    $fr_translation->set('title', 'The French title');
    $fr_translation->save();

    // Re-visit the French URL — the French node title must now be used by the
    // EntityFieldPropSource.
    $this->drupalGet($french_url);
    $this->assertSession()->statusCodeEquals(200);
    $fr_page = $this->getSession()->getPage();

    // French ContentTemplate static text still applies.
    self::assertNotNull(
      $fr_page->findLink('Propulsé par Drupal Canvas'),
      'French ContentTemplate translation must still be applied after adding a French node translation.',
    );
    self::assertNull(
      $fr_page->findLink('Powered by Drupal Canvas'),
      'English static CTA text must still not appear on the French URL after adding a French node translation.',
    );

    // The dynamic CTA must now show the French node title, not the English one.
    $fr_node_link = $fr_page->findLink('The French title');
    self::assertNotNull(
      $fr_node_link,
      'Dynamic CTA must show the French node title once a French node translation exists.',
    );
    self::assertNull(
      $fr_page->findLink($english_title),
      'Dynamic CTA must not show the English title when visiting the French URL with a French node translation present.',
    );

    // The dynamic CTA href must still resolve to the French node URL.
    self::assertSame(
      $GLOBALS['base_url'] . '/fr/node/' . $nid,
      $fr_node_link->getAttribute('href'),
      'Dynamic CTA href must remain the French-language node URL after adding a French node translation.',
    );

    $this->drupalGet($english_url);
    $this->assertSession()->statusCodeEquals(200);
    $en_page = $this->getSession()->getPage();

    // English static CTA must appear on the English URL.
    $en_canvas_link = $en_page->findLink('Powered by Drupal Canvas');
    self::assertNotNull(
      $en_canvas_link,
      'English ContentTemplate text must be applied when visiting the English URL.',
    );
    self::assertSame('https://drupal.org/project/canvas', $en_canvas_link->getAttribute('href'));

    // French text must not appear on the English URL.
    self::assertNull(
      $en_page->findLink('Propulsé par Drupal Canvas'),
      'French ContentTemplate translation must not appear when visiting the English URL.',
    );

    // The dynamic CTA must show the English node title on the English URL.
    $en_node_link = $en_page->findLink($english_title);
    self::assertNotNull(
      $en_node_link,
      'Dynamic CTA must show the English node title when visiting the English URL.',
    );
    self::assertSame(
      $GLOBALS['base_url'] . '/node/' . $nid,
      $en_node_link->getAttribute('href'),
      'Dynamic CTA href must be the English-language node URL when visiting the English URL.',
    );
  }

  /**
   * Tests that /fr/* paths use the French PageRegion translation.
   *
   * Visiting any path with a French language prefix applies the French
   * LanguageConfigOverride of the PageRegion config entity. This is true
   * regardless of whether the entity displayed at the current path itself has
   * a French translation.
   *
   * Also tests that when a language exists but has no PageRegion translation,
   * it falls back to the default language (English).
   */
  public function testNonDefaultTranslationPageRegionTranslation(): void {
    $module_installer = $this->container->get(ModuleInstallerInterface::class);
    $module_installer->install(['canvas_dev_translation']);
    $this->rebuildContainer();

    $this->createPageRegionWithFrenchOverride();

    // Create a plain article node with no French translation.
    $node = $this->createTestNode();
    $nid = $node->id();

    // Retrieve the French language object for constructing the /fr/ URL.
    $language_manager = $this->container->get(LanguageManagerInterface::class);
    self::assertInstanceOf(ConfigurableLanguageManagerInterface::class, $language_manager);
    $fr_language = $language_manager->getLanguage('fr');
    self::assertNotNull($fr_language, 'French language must exist.');

    $french_node_url = Url::fromRoute('entity.node.canonical', ['node' => $nid], ['language' => $fr_language]);

    // Visit /fr/node/{nid} — the node has no French translation, but the URL
    // prefix is French, so the French PageRegion override must still be used.
    $this->drupalGet($french_node_url);
    $this->assertSession()->statusCodeEquals(200);

    // French PageRegion text must appear because the URL prefix is French,
    // regardless of the node lacking a French translation.
    $this->assertSession()->pageTextContains('Bonjour de la région');

    // English PageRegion text must NOT appear on the French-prefixed node URL.
    $this->assertSession()->pageTextNotContains('Hello from region');

    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(200);

    // English PageRegion text must appear on the English node URL.
    $this->assertSession()->pageTextContains('Hello from region');

    // French PageRegion text must NOT appear on the English node URL.
    $this->assertSession()->pageTextNotContains('Bonjour de la région');

    // Retrieve the Hindi language object for constructing the /hi/ URL.
    $hi_language = $language_manager->getLanguage('hi');
    self::assertNotNull($hi_language, 'Hindi language must exist.');

    $hindi_node_url = Url::fromRoute('entity.node.canonical', ['node' => $nid], ['language' => $hi_language]);

    // Visit /hi/node/{nid} — there is no Hindi PageRegion translation, so it
    // must fall back to the default language (English).
    $this->drupalGet($hindi_node_url);
    $this->assertSession()->statusCodeEquals(200);

    // English PageRegion text must appear because no Hindi translation exists.
    $this->assertSession()->pageTextContains('Hello from region');

  }

  /**
   * Tests the Canvas API delete translation endpoint for canvas_page.
   *
   * Covers:
   *  - 204 response when deleting a non-default translation.
   *  - 400 response when attempting to delete the default translation.
   *  - 400 response when the translation no longer exists.
   *
   * @see \Drupal\canvas\Controller\ApiTranslationControllers::delete()
   */
  public function testDeleteCanvasPageTranslation(): void {
    $page_storage = $this->container->get(EntityTypeManagerInterface::class)->getStorage(Page::ENTITY_TYPE_ID);
    $page = $this->createCanvasTranslationTestPage();
    $page_id = (int) $page->id();
    self::assertTrue($page->hasTranslation('fr'));
    $fr_delete_url = Url::fromUserInput("/fr/canvas/api/v0/content/canvas_page/{$page_id}/translations");

    $user = $this->drupalCreateUser([]);
    \assert($user instanceof UserInterface);
    $this->drupalLogin($user);
    $request_options['headers']['X-CSRF-Token'] = $this->drupalGet('session/token');
    // Attempting to delete non-default translation without the correct
    // permission returns a 403.
    $response = $this->makeApiRequest('DELETE', $fr_delete_url, $request_options);
    self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    // Ensure the translation was not removed.
    $reloaded = $page_storage->loadUnchanged($page_id);
    self::assertInstanceOf(Page::class, $reloaded);
    self::assertTrue($reloaded->hasTranslation('fr'));

    $user = $this->drupalCreateUser([Page::EDIT_PERMISSION]);
    \assert($user instanceof UserInterface);
    $this->drupalLogin($user);
    $request_options['headers']['X-CSRF-Token'] = $this->drupalGet('session/token');

    // Deleting a non-default translation removes it and returns 204.
    $response = $this->makeApiRequest('DELETE', $fr_delete_url, $request_options);
    self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());

    $reloaded = $page_storage->loadUnchanged($page_id);
    self::assertInstanceOf(Page::class, $reloaded);
    self::assertFalse($reloaded->hasTranslation('fr'));
    self::assertTrue($reloaded->hasTranslation('en'));

    // Trying to delete the default/source language is not allowed.
    $delete_default_url = Url::fromUserInput("/canvas/api/v0/content/canvas_page/{$page_id}/translations");
    $response = $this->makeApiRequest('DELETE', $delete_default_url, $request_options);
    self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $reloaded = $page_storage->loadUnchanged($page_id);
    self::assertInstanceOf(Page::class, $reloaded);
    self::assertTrue($reloaded->hasTranslation('en'));

    // Trying to delete a translation that no longer exists returns 400 because
    // Drupal's entity translation negotiation will load the default translation.
    $response = $this->makeApiRequest('DELETE', $fr_delete_url, $request_options);
    self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
  }

  /**
   * Data provider for testDeleteConfigEntityTranslation().
   *
   * @return array<string, array{string}>
   *   Keyed by label, each value is [entity_type_id].
   */
  public static function deleteConfigEntityTranslationProvider(): array {
    return [
      'Content Template' => [ContentTemplate::ENTITY_TYPE_ID],
      'Page Region' => [PageRegion::ENTITY_TYPE_ID],
    ];
  }

  /**
   * Tests the Canvas API delete translation endpoint for config entities.
   *
   * Covers:
   *  - 204 response when deleting a non-default translation.
   *  - 400 response when the translation no longer exists.
   *
   * @see \Drupal\canvas\Controller\ApiTranslationControllers::deleteConfigTranslation()
   */
  #[DataProvider('deleteConfigEntityTranslationProvider')]
  public function testDeleteConfigEntityTranslation(string $entity_type_id): void {
    $this->container->get(ModuleInstallerInterface::class)->install(['config_translation']);
    $this->rebuildContainer();

    if ($entity_type_id === ContentTemplate::ENTITY_TYPE_ID) {
      $entity = ContentTemplate::load('node.article.full');
      self::assertInstanceOf(ContentTemplate::class, $entity);
    }
    else {
      $entity = $this->createPageRegionWithFrenchOverride();
    }
    $entity_id = $entity->id();

    $language_manager = $this->container->get(LanguageManagerInterface::class);
    self::assertInstanceOf(ConfigurableLanguageManagerInterface::class, $language_manager);
    $override = $language_manager->getLanguageConfigOverride('fr', $entity->getConfigDependencyName());
    self::assertFalse($override->isNew());

    $fr_delete_url = Url::fromUserInput("/fr/canvas/api/v0/config/{$entity_type_id}/{$entity_id}/translations");

    $user = $this->drupalCreateUser([]);
    \assert($user instanceof UserInterface);
    $this->drupalLogin($user);
    $request_options['headers']['X-CSRF-Token'] = $this->drupalGet('session/token');
    // Attempting to delete non-default translation without the correct
    // permission returns a 403.
    $response = $this->makeApiRequest('DELETE', $fr_delete_url, $request_options);
    self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    // Ensure the translation was not removed.
    $override = $language_manager->getLanguageConfigOverride('fr', $entity->getConfigDependencyName());
    self::assertFalse($override->isNew());

    $user = $this->drupalCreateUser(['translate configuration']);
    \assert($user instanceof UserInterface);
    $this->drupalLogin($user);
    $request_options['headers']['X-CSRF-Token'] = $this->drupalGet('session/token');

    // Deleting a non-default translation removes it and returns 204.
    $response = $this->makeApiRequest('DELETE', $fr_delete_url, $request_options);
    self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), $response->getBody()->__toString());

    $override = $language_manager->getLanguageConfigOverride('fr', $entity->getConfigDependencyName());
    self::assertTrue($override->isNew());

    // Trying to delete a translation that no longer exists returns 400.
    $response = $this->makeApiRequest('DELETE', $fr_delete_url, $request_options);
    self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
  }

}
