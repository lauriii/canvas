<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

// cspell:ignore Bienvenue savoir Découvrez Identité visuelle

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

    // 3. ASSERTIONS: verify rendered translatable/non-translatable fields.
    // Banner: rich text fields exist.
    $assert_session->fieldExists($field('[' . self::UUID_BANNER . '][inputs][heading][0][value]'));
    $assert_session->fieldExists($field('[' . self::UUID_BANNER . '][inputs][text][0][value]'));
    $assert_session->elementExists(
      'css',
      'input[type="hidden"][name="' . $field('[' . self::UUID_BANNER . '][inputs][text][0][format]') . '"][value="canvas_html_block"]',
    );

    // SDC props populated by StaticPropSources are translatable.
    $assert_session->fieldExists($field('[' . self::UUID_MY_HERO . '][inputs][heading][0][value]'));
    $assert_session->fieldExists($field('[' . self::UUID_MY_HERO . '][inputs][cta2][0][value]'));
    // SDC prop populated by EntityFieldPropSource is NOT translatable.
    $assert_session->fieldNotExists($field('[' . self::UUID_MY_HERO . '][inputs][cta1]'));
    $assert_session->fieldNotExists($field('[' . self::UUID_MY_HERO . '][inputs][cta1][0][value]'));
    // SDC prop populated by HostEntityUrlPropSource is NOT translatable.
    $assert_session->fieldNotExists($field('[' . self::UUID_MY_HERO . '][inputs][cta1href]'));
    $assert_session->fieldNotExists($field('[' . self::UUID_MY_HERO . '][inputs][cta1href][0][uri]'));

    // Optional prop NOT populated in default is translatable: a translation may
    // opt to populate it even when the default translation leaves it empty.
    // @see \Drupal\canvas\ConfigTranslation\CanvasComponentTreeItemInputsMappingFormElement::ensureOmittedOptionalInputsAreTranslatable()
    $assert_session->fieldExists($field('[' . self::UUID_MY_HERO . '][inputs][subheading][0][value]'));

    // Tags: each item in the sequence renders as a separate text field.
    $assert_session->fieldExists($field('[' . self::UUID_TAGS . '][inputs][tags][0][value]'));
    $assert_session->fieldExists($field('[' . self::UUID_TAGS . '][inputs][tags][1][value]'));
    $assert_session->fieldExists($field('[' . self::UUID_TAGS . '][inputs][tags][2][value]'));

    // My-cta: text and href.uri are translatable.
    $assert_session->fieldExists($field('[' . self::UUID_MY_CTA . '][inputs][text][0][value]'));
    $assert_session->fieldExists($field('[' . self::UUID_MY_CTA . '][inputs][href][0][uri]'));

    // Branding: only `label` is translatable.
    $assert_session->fieldExists($field('[' . self::UUID_BRANDING . '][inputs][label]'));
    $assert_session->fieldNotExists($field('[' . self::UUID_BRANDING . '][inputs][label_display]'));
    $assert_session->fieldNotExists($field('[' . self::UUID_BRANDING . '][inputs][use_site_logo]'));
    $assert_session->fieldNotExists($field('[' . self::UUID_BRANDING . '][inputs][use_site_name]'));
    $assert_session->fieldNotExists($field('[' . self::UUID_BRANDING . '][inputs][use_site_slogan]'));

    // 4. SUBMIT: provide French translations for all 5 component instances.
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
    ], 'Save translation');
    $assert_session->pageTextContains('Successfully saved French translation');

    $this->assertTranslatedConfigComponentTree();
  }

}
