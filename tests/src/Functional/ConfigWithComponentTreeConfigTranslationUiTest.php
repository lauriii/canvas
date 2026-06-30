<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

// cspell:ignore Bienvenue savoir Découvrez Identité visuelle Gitane Charte graphique

use Behat\Mink\Element\NodeElement;
use Drupal\canvas\ConfigTranslation\CanvasStaticPropSourceFieldWidget;
use Drupal\Core\Extension\ModuleInstallerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[Group('canvas')]
#[Group('canvas_translation')]
#[CoversClass(CanvasStaticPropSourceFieldWidget::class)]
class ConfigWithComponentTreeConfigTranslationUiTest extends ConfigWithComponentTreeTranslationTestBase {

  public function test(): void {
    $module_installer = $this->container->get('module_installer');
    \assert($module_installer instanceof ModuleInstallerInterface);
    if (!$this->container->get('module_handler')->moduleExists('config_translation')) {
      $module_installer->install(['config_translation']);
      $this->rebuildContainer();
      $module_installer = $this->container->get('module_installer');
      \assert($module_installer instanceof ModuleInstallerInterface);
    }

    $translation_path = '/admin/structure/content-template/node.article.full/translate/fr/add';
    $field = static fn (string $suffix): string => 'translation[config_names][' . self::CONFIG_NAME . '][component_tree]' . $suffix;

    // 1. Confirm Templates are not translatable via the UI without
    // `canvas_dev_translation` enabled.
    $this->drupalGet($translation_path);
    $assert_session = $this->assertSession();
    $assert_session->statusCodeEquals(404);

    if (!$this->container->get('module_handler')->moduleExists('canvas_dev_translation')) {
      $module_installer->install(['canvas_dev_translation']);
      $this->rebuildContainer();
    }

    // 2. Confirm Templates are translatable via the UI once
    // `canvas_dev_translation` is enabled.
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
    ], $this->getConfigTranslationUiFormElementsForComponentInstances());
    // The "format" input exists (to load CKEditor) but is hidden, so it cannot
    // be changed.
    $assert_session->elementExists(
      'css',
      'input[type="hidden"][name="' . $field('[' . self::UUID_BANNER . '][inputs][text][0][format]') . '"][value="canvas_html_block"]',
    );

    // Provide French translations for all 7 component instances.
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
