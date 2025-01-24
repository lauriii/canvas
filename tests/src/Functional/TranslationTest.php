<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Functional;

use Drupal\experience_builder\Plugin\DataType\ComponentInputs;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\node\Entity\Node;
use Drupal\Tests\content_translation\Traits\ContentTranslationTestTrait;

/**
 * @todo Add test coverage for dynamic prop sources used in the content type
 *   templates in https://drupal.org/i/3455629. This will most likely require
 *   adding back `experience_builder_entity_prepare_view()` which was removed in
 *   https://www.drupal.org/i/3481720.
 * @see https://www.drupal.org/project/experience_builder/issues/3455629#comment-15831060
 */
class TranslationTest extends FunctionalTestBase {

  use ContentTranslationTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'xb_test_config_node_article',
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

    // Display the `field_xb_test` field.
    \Drupal::service('entity_display.repository')
      ->getViewDisplay('node', 'article')
      ->setComponent('field_xb_test', [
        'label' => 'hidden',
        'type' => 'experience_builder_naive_render_sdc_tree',
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
   * Data provider for testTranslation().
   *
   * @return array<array{0: array, 1: bool}>
   */
  public static function translationDataProvider(): array {
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
   * Tests translating the XB field.
   *
   * @param array<string> $translatable_properties
   *   The properties on the XB field that should be
   *   translatable.
   * @param bool $expect_component_removed_on_translation
   *   Whether the last component in XB tree is expected to be removed from the
   *   translation. The component is always removed from the default
   *   translation.
   *
   * @dataProvider translationDataProvider
   */
  public function testTranslation(array $translatable_properties, bool $expect_component_removed_on_translation): void {
    $page = $this->getSession()->getPage();
    $assert_session = $this->assertSession();

    $field_is_translatable = !empty($translatable_properties);

    $this->drupalGet('admin/config/regional/content-language');
    if ($field_is_translatable) {
      $page->checkField('settings[node][article][fields][field_xb_test]');
      foreach (['tree', 'inputs'] as $field_property) {
        in_array($field_property, $translatable_properties)
          ? $page->checkField("settings[node][article][columns][field_xb_test][$field_property]")
          : $page->uncheckField("settings[node][article][columns][field_xb_test][$field_property]");
      }
    }
    else {
      $page->uncheckField('settings[node][article][fields][field_xb_test]');
    }

    $page->pressButton('Save configuration');
    $this->assertSession()->pageTextContains('Settings successfully updated.');
    $original_node = $this->createXbNodeWithTranslation();
    $this->assertTrue($original_node->isDefaultTranslation());
    $translated_node = $original_node->getTranslation('fr');
    $this->assertSame('The French title', (string) $translated_node->getTitle());

    $this->drupalGet($original_node->toUrl());
    $hero_component = $assert_session->elementExists('css', 'article [data-component-id="experience_builder:my-hero"]');

    // Confirm the translated property is no on the page anywhere.
    $assert_session->pageTextNotContains('bonjour');
    // Confirm the first hero component does not use the translated properties
    // because it uses a StaticPropSource.
    $this->assertSame('hello, new world!', $hero_component->getText());
    // Confirm the heading has been removed from display. This was changed on
    // the default translation.
    $assert_session->elementsCount('css', 'article [data-component-id="experience_builder:heading"]', 0);

    $this->drupalGet($translated_node->toUrl());
    $assert_session->elementTextEquals('css', '#block-stark-page-title h1', 'The French title');

    $hero_component = $assert_session->elementExists('css', 'article [data-component-id="experience_builder:my-hero"]');
    if ($field_is_translatable) {
      // If the field is translatable updating inputs in the default translation
      // should not have updated the French translation.
      $this->assertSame('bonjour, monde!', $hero_component->getText());
      $assert_session->pageTextNotContains('hello, new world!');
    }
    else {
      // If the field is not translatable updating inputs in the default translation
      // should have also updated the French translation.
      $assert_session->pageTextNotContains('bonjour');
      $this->assertSame('hello, new world!', $hero_component->getText());
    }

    // Confirm the heading component has been removed or not based the test case
    // expectation.
    $assert_session->elementsCount(
      'css',
      'article [data-component-id="experience_builder:heading"]',
      $expect_component_removed_on_translation ? 0 : 1
    );
  }

  /**
   * Creates an article node with a translation.
   *
   * @return \Drupal\node\Entity\Node
   *   The default translation of the node.
   */
  protected function createXbNodeWithTranslation(): Node {
    $node = $this->createTestNode();
    // Create a translation from the original English node.
    $translation = $node->addTranslation('fr');
    $this->assertInstanceOf(Node::class, $translation);
    $this->container->get('content_translation.manager')->getTranslationMetadata($translation)->setSource($node->language()->getId());
    // @phpstan-ignore-next-line
    $translation->title = 'The French title';
    $translation->save();
    $translation = $node->getTranslation('fr');
    assert($node->get('field_xb_test')[0] instanceof ComponentTreeItem);
    $inputs = $node->get('field_xb_test')[0]->get('inputs');
    $this->assertInstanceOf(ComponentInputs::class, $inputs);
    $original_inputs_value = $inputs->getValue();

    // In both the Symmetric and Asymmetric translation cases, the `inputs` property
    // is translatable and this should only change the translation.
    $french_prop = str_replace('hello, world!', 'bonjour, monde!', $original_inputs_value);
    assert($translation->get('field_xb_test')[0] instanceof ComponentTreeItem);
    $translation->get('field_xb_test')[0]->set('inputs', $french_prop);
    $translation->save();

    $updated_inputs_value = str_replace('hello, world!', 'hello, new world!', $original_inputs_value);
    // In both the Symmetric and Asymmetric cases, the `inputs` property is
    // translatable and this should only change the original. If the field is
    // not translatable, this should change both the original and the
    // translation.
    $node->get('field_xb_test')[0]->set('inputs', $updated_inputs_value);
    $tree = $node->get('field_xb_test')[0]->get('tree');
    $this->assertInstanceOf(ComponentTreeStructure::class, $tree);
    $tree_value = $tree->getValue();
    $tree_decoded = json_decode($tree_value, TRUE);
    // Remove the heading from the tree.
    // In the asymmetric case, where 'tree' is translatable, this should only
    // affect the untranslated node.
    // In the symmetric case, where 'tree' is not translatable, this should
    // change both the original and the translation.
    $this->assertSame('static-heading-some-uuid', $tree_decoded['two-column-uuid']['column_two'][2]['uuid']);
    unset($tree_decoded['two-column-uuid']['column_two'][2]);
    $node->get('field_xb_test')[0]->set('tree', json_encode($tree_decoded));
    $node->save();
    return $node;
  }

}
