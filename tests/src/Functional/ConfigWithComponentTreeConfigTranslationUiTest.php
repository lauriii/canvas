<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

// cspell:ignore Bienvenue savoir Découvrez Identité visuelle Gitane Charte graphique Traduit française

use Behat\Mink\Element\NodeElement;
use Drupal\canvas\ConfigTranslation\CanvasStaticPropSourceFieldWidget;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Marker;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\language\ConfigurableLanguageManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[Group('canvas')]
#[Group('canvas_translation')]
#[CoversClass(CanvasStaticPropSourceFieldWidget::class)]
class ConfigWithComponentTreeConfigTranslationUiTest extends ConfigWithComponentTreeTranslationTestBase {

  public function test(): void {
    $module_installer = $this->container->get(ModuleInstallerInterface::class);
    \assert($module_installer instanceof ModuleInstallerInterface);
    if (!$this->container->get(ModuleHandlerInterface::class)->moduleExists('config_translation')) {
      $module_installer->install(['config_translation']);
      $this->rebuildContainer();
      $module_installer = $this->container->get(ModuleInstallerInterface::class);
      \assert($module_installer instanceof ModuleInstallerInterface);
    }

    $page_variant_definition = $this->container->get(EntityTypeManagerInterface::class)->getDefinition(PageVariant::ENTITY_TYPE_ID);
    self::assertSame('/admin/structure/page-variant/{page_variant}', $page_variant_definition->getLinkTemplate('edit-form'));
    self::assertSame('/admin/structure/page-variant/{page_variant}/translate', $page_variant_definition->getLinkTemplate('config-translation-overview'));

    $translation_path = '/admin/structure/content-template/node.article.full/translate/fr/add';
    $field = static fn (string $suffix): string => 'translation[config_names][' . self::CONFIG_NAME . '][component_tree]' . $suffix;

    // Confirm Templates are translatable via the UI with config_translation.
    $this->drupalGet($translation_path);
    $assert_session = $this->assertSession();
    $assert_session->statusCodeEquals(200);

    // Explicitly assert all the form fields that exist per component instance,
    // to prove the generated UI matches expectations.
    self::assertSame([
      // `tags`: each item in the sequence renders as a separate text field.
      self::UUID_TAGS => [
        $field('[' . self::UUID_TAGS . '][inputs][tags][0][value]'),
        $field('[' . self::UUID_TAGS . '][inputs][tags][1][value]'),
        $field('[' . self::UUID_TAGS . '][inputs][tags][2][value]'),
      ],
      self::UUID_MY_HERO => [
        // SDC props populated by StaticPropSources are translatable.
        $field('[' . self::UUID_MY_HERO . '][inputs][heading][0][value]'),

        // Optional prop NOT populated in default is translatable: a translation
        // may opt to populate it even when the default translation leaves it
        // empty.
        // @see \Drupal\canvas\ConfigTranslation\CanvasComponentTreeItemInputsMappingFormElement::ensureOmittedOptionalInputsAreTranslatable()
        $field('[' . self::UUID_MY_HERO . '][inputs][subheading][0][value]'),

        // ⚠️ SDC prop populated by EntityFieldPropSource is NOT translatable:
        // that would have listed `…[inputs][cta1][0][value]`, too.

        // ⚠️ SDC prop populated by HostEntityUrlPropSource is NOT translatable:
        // that would have listed `…[inputs][cta1href][0][uri]`, too.

        // SDC props populated by StaticPropSources are translatable.
        $field('[' . self::UUID_MY_HERO . '][inputs][cta2][0][value]'),
      ],
      // `my-cta`: text and href.uri are translatable.
      self::UUID_MY_CTA => [
        $field('[' . self::UUID_MY_CTA . '][inputs][text][0][value]'),
        $field('[' . self::UUID_MY_CTA . '][inputs][href][0][uri]'),
      ],
      self::UUID_BANNER => [
        $field('[' . self::UUID_BANNER . '][inputs][heading][0][value]'),
        $field('[' . self::UUID_BANNER . '][inputs][text][0][value]'),
        // Text format is present to load CKEditor 5, but is immutable because
        // it is an `input[type=hidden]`. See the next assertion.
        $field('[' . self::UUID_BANNER . '][inputs][text][0][format]'),
      ],
      // Branding block: only `label` is translatable.
      self::UUID_BRANDING => [
        $field('[' . self::UUID_BRANDING . '][inputs][label]'),
      ],
      // Translatability test block: only `label` and the deeply nested `bar`
      // are translatable.
      self::UUID_BLOCK_DEEP_TRANSLATABLE => [
        $field('[' . self::UUID_BLOCK_DEEP_TRANSLATABLE . '][inputs][label]'),
        $field('[' . self::UUID_BLOCK_DEEP_TRANSLATABLE . '][inputs][deeply_nested_translatable][0][bar]'),
      ],
      // Block whose translatable `name` is empty in the source language: it is
      // still offered for translation, alongside the populated `label`.
      self::UUID_BLOCK_EMPTY_TRANSLATABLE_INPUT => [
        $field('[' . self::UUID_BLOCK_EMPTY_TRANSLATABLE_INPUT . '][inputs][label]'),
        $field('[' . self::UUID_BLOCK_EMPTY_TRANSLATABLE_INPUT . '][inputs][name]'),
      ],
      // untranslatable-prop-shapes component: no prop shape is translatable, so
      // none is offered for translation. `date` (datetime field, date-only),
      // `email`, `integer` and `boolean` are all excluded.
      self::UUID_UNTRANSLATABLE_PROP_SHAPES => [],
    ], $this->getConfigTranslationUiFormElementsForComponentInstances());
    // The "format" input exists (to load CKEditor) but is hidden, so it cannot
    // be changed.
    $assert_session->elementExists(
      'css',
      'input[type="hidden"][name="' . $field('[' . self::UUID_BANNER . '][inputs][text][0][format]') . '"][value="canvas_html_block"]',
    );

    // Provide French translations for all translatable component instances.
    $this->submitForm([
      $field('[' . self::UUID_TAGS . '][inputs][tags][0][value]') => 'fr: baz',
      $field('[' . self::UUID_TAGS . '][inputs][tags][1][value]') => 'fr: bar',
      $field('[' . self::UUID_TAGS . '][inputs][tags][2][value]') => 'fr: foo',
      $field('[' . self::UUID_MY_CTA . '][inputs][text][0][value]') => 'fr: Press',
      $field('[' . self::UUID_MY_CTA . '][inputs][href][0][uri]') => 'https://fr.drupal.org',
      $field('[' . self::UUID_BANNER . '][inputs][heading][0][value]') => 'fr: A heading element! :)',
      $field('[' . self::UUID_BANNER . '][inputs][text][0][value]') => 'fr: <p>In a curious work, published in <em>Paris</em> in 1863 by <strong>Delaville Dedreux</strong>, there is a suggestion for reaching the North Pole by an aerostat.</p>',
      $field('[' . self::UUID_MY_HERO . '][inputs][heading][0][value]') => 'Bienvenue à Canvas',
      $field('[' . self::UUID_MY_HERO . '][inputs][cta2][0][value]') => 'En savoir plus',
      $field('[' . self::UUID_MY_HERO . '][inputs][subheading][0][value]') => 'Découvrez Canvas',
      $field('[' . self::UUID_BRANDING . '][inputs][label]') => 'Identité visuelle',
      $field('[' . self::UUID_BLOCK_DEEP_TRANSLATABLE . '][inputs][label]') => 'fr: Canvas Test Block for testing input translatability',
      $field('[' . self::UUID_BLOCK_DEEP_TRANSLATABLE . '][inputs][deeply_nested_translatable][0][bar]') => 'fr: Gitane',
      $field('[' . self::UUID_BLOCK_EMPTY_TRANSLATABLE_INPUT . '][inputs][label]') => 'fr: Test block with settings',
      // Translate the `name` that was empty in the source language.
      $field('[' . self::UUID_BLOCK_EMPTY_TRANSLATABLE_INPUT . '][inputs][name]') => 'Charte graphique',
    ], 'Save translation');
    $assert_session->pageTextContains('Successfully saved French translation');

    $this->assertTranslatedConfigComponentTree();
  }

  /**
   * Tests translating component props in a page variant.
   */
  public function testPageVariantTranslation(): void {
    $module_installer = $this->container->get(ModuleInstallerInterface::class);
    \assert($module_installer instanceof ModuleInstallerInterface);
    if (!$this->container->get(ModuleHandlerInterface::class)->moduleExists('config_translation')) {
      $module_installer->install(['config_translation']);
      $this->rebuildContainer();
    }

    $marker = Component::load(Marker::PAGE_CONTENT_COMPONENT_ID);
    self::assertInstanceOf(Component::class, $marker);
    $component_tree = self::componentTreeItems(cta1: 'Contact us', cta1href: '/contact');
    $variant = PageVariant::create([
      'id' => 'translated',
      'label' => 'Translated',
      'component_tree' => self::populateActiveComponentVersionPlaceholders([
        [
          'uuid' => '11111111-1111-4111-8111-111111111111',
          'component_id' => Marker::PAGE_CONTENT_COMPONENT_ID,
          'component_version' => $marker->getActiveVersion(),
          'inputs' => [],
        ],
        $component_tree[self::COMPONENT_DELTA[self::UUID_MY_CTA]],
      ]),
    ]);
    $violations = $variant->getTypedData()->validate();
    self::assertCount(0, $violations, (string) $violations);
    $variant->save();

    $config_name = $variant->getConfigDependencyName();
    $field = static fn (string $suffix): string => "translation[config_names][$config_name][component_tree]$suffix";
    $this->drupalGet('/admin/structure/page-variant/translated/translate/fr/add');
    $assert_session = $this->assertSession();
    $assert_session->statusCodeEquals(200);
    $assert_session->fieldExists("translation[config_names][$config_name][label]");
    $assert_session->fieldExists("translation[config_names][$config_name][description]");
    $assert_session->fieldExists($field('[' . self::UUID_MY_CTA . '][inputs][text][0][value]'));
    $assert_session->fieldExists($field('[' . self::UUID_MY_CTA . '][inputs][href][0][uri]'));

    $this->submitForm([
      "translation[config_names][$config_name][label]" => 'Traduit',
      "translation[config_names][$config_name][description]" => 'Description française',
      $field('[' . self::UUID_MY_CTA . '][inputs][text][0][value]') => 'fr: Press',
      $field('[' . self::UUID_MY_CTA . '][inputs][href][0][uri]') => 'https://fr.drupal.org',
    ], 'Save translation');
    $assert_session->pageTextContains('Successfully saved French translation');

    $language_manager = $this->container->get(LanguageManagerInterface::class);
    self::assertInstanceOf(ConfigurableLanguageManagerInterface::class, $language_manager);
    $override = $language_manager->getLanguageConfigOverride('fr', $config_name);
    self::assertSame('Traduit', $override->get('label'));
    self::assertSame('Description française', $override->get('description'));
    self::assertSame([
      'text' => 'fr: Press',
      'href' => ['uri' => 'https://fr.drupal.org'],
    ], $override->get('component_tree.' . self::UUID_MY_CTA . '.inputs'));
  }

  private function getConfigTranslationUiFormElementsForComponentInstances(): array {
    $page = $this->getSession()->getPage();

    $form_field_names = [];
    foreach (\array_keys(self::COMPONENT_DELTA) as $component_instance_uuid) {
      $form_field_names[$component_instance_uuid] = \array_map(
        fn(NodeElement $n) => $n->getAttribute('name'),
        $page->findAll('css', "[name^='translation['][name*='[$component_instance_uuid][inputs]']:not([name$='[_weight]'])"),
      );
    }
    return $form_field_names;
  }

}
