<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Functional;

use Drupal\experience_builder\Plugin\DataType\ComponentPropsValues;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\image\Entity\ImageStyle;
use Drupal\node\Entity\Node;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\content_translation\Traits\ContentTranslationTestTrait;
use Drupal\Tests\TestFileCreationTrait;

class TranslationTest extends BrowserTestBase {

  use TestFileCreationTrait;
  use ContentTranslationTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['experience_builder', 'content_translation', 'language'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected $profile = 'standard';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
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

    // The `thumbnail` image style already exists.
    $this->assertInstanceOf(ImageStyle::class, ImageStyle::load('thumbnail'));
  }

  /**
   * Data provider for testTranslation().
   * @return array<array{0: array, 1: bool}>
   */
  public function translationDataProvider(): array {
    return [
      // In the symmetric case, the 'tree' property is not translatable. This
      // means every translation has the same components but can have different
      // properties.
      'symmetric' => [['props'], TRUE],
      // In the asymmetric case, both 'tree' and 'props' property are
      // translatable. This means every translation can have different components
      // and properties for those components. There no connection at all between
      // the components in the different translations.
      'asymmetric' => [['tree', 'props'], FALSE],
      // This case tests when the field is not translatable, but it is used on
      // an entity that has translations. In this case, the components and their
      // properties are shared between the translations. But dynamic properties
      // of the entity should use the values of the current translation.
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
      $page->checkField('settings[node][article][fields][field_xb_demo]');
      foreach (['tree', 'props'] as $field_property) {
        in_array($field_property, $translatable_properties)
          ? $page->checkField("settings[node][article][columns][field_xb_demo][$field_property]")
          : $page->uncheckField("settings[node][article][columns][field_xb_demo][$field_property]");
      }
    }
    else {
      $page->uncheckField('settings[node][article][fields][field_xb_demo]');
    }

    $page->pressButton('Save configuration');
    $this->assertSession()->pageTextContains('Settings successfully updated.');
    $original_node = $this->createXbNodeWithTranslation();
    $this->assertTrue($original_node->isDefaultTranslation());
    $translated_node = $original_node->getTranslation('fr');
    $this->assertSame('The French title', (string) $translated_node->getTitle());

    $this->drupalGet($original_node->toUrl());
    $hero_components = $this->getSession()->getPage()->findAll('css', 'article [data-component-id="experience_builder:my-hero"]');
    $this->assertCount(3, $hero_components);
    // Confirm the 2 components that use a DynamicPropSource to retrieve the
    // actual node title, which should be the untranslated version here.
    $this->assertSame('The first entity using XB!', $hero_components[1]->getText());
    $this->assertSame('The first entity using XB!', $hero_components[2]->getText());
    // Confirm the translated property is no on the page anywhere.
    $assert_session->pageTextNotContains('bonjour');
    // Confirm the first hero component does not use the translated properties
    // because it uses a StaticPropSource.
    $this->assertSame('hello, new world!', $hero_components[0]->getText());
    // Confirm the image component that displays the thumbnail image has been
    // removed from display. This was changed on the default translation.
    $assert_session->elementsCount('css', 'article img[src*="/files/styles/thumbnail/"]', 0);

    $this->drupalGet($translated_node->toUrl());
    $assert_session->elementTextEquals('css', '#block-stark-page-title h1', 'The French title');

    // Confirm the 2 components that use a DynamicPropSource to retrieve the
    // actual node title now use the translated version.
    // @see \experience_builder_entity_prepare_view()
    $hero_components = $this->getSession()->getPage()->findAll('css', 'article [data-component-id="experience_builder:my-hero"]');
    $this->assertCount(3, $hero_components);
    $this->assertSame('The French title', $hero_components[1]->getText());
    $this->assertSame('The French title', $hero_components[2]->getText());
    if ($field_is_translatable) {
      // If the field is translatable updating props in the default translation
      // should not have updated the French translation.
      $this->assertSame('bonjour, monde!', $hero_components[0]->getText());
      $assert_session->pageTextNotContains('hello, new world!');
    }
    else {
      // If the field is not translatable updating props in the default translation
      // should have also updated the French translation.
      $assert_session->pageTextNotContains('bonjour');
      $this->assertSame('hello, new world!', $hero_components[0]->getText());
    }

    // Confirm the image component that displays the thumbnail image has been
    // removed or not based the test case expectation.
    $assert_session->elementsCount(
      'css',
      'article img[src*="/files/styles/thumbnail/"]',
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
    $page = $this->getSession()->getPage();
    $assert_session = $this->assertSession();
    // Node 1 does not exist.
    $this->assertNull(Node::load(1));
    $this->drupalGet('node/add/article');
    $assert_session->statusCodeEquals(200);
    $page->pressButton('Save');
    $this->assertStringEndsWith('node/add/article',
      $this->getSession()->getCurrentUrl());
    // @todo For some reason, specifying `type: 'error'` fails: the expected HTML structure is different?! 🤯
    $this->assertSession()->statusMessageContains('Title field is required.');
    $this->assertSession()->statusMessageContains('Hero field is required.');

    // Two entity fields are required: `Title` + `Hero`. Fill 'em, press `Save`.
    $page->fillField('title[0][value]', 'The first entity using XB!');
    $image_file = current($this->getTestFiles('image'));
    // @phpstan-ignore-next-line
    $image_path = $this->container->get('file_system')->realpath($image_file->uri);
    $this->assertNotFalse($image_path);
    $page->attachFileToField('files[field_hero_0]', $image_path);
    $page->pressButton('Save');

    // Now that a file has been uploaded, we also need to specify `alt`.
    $this->assertSession()
      ->statusMessageContains('Alternative text field is required.');
    $page->fillField('field_hero[0][alt]',
      'A random image for testing purposes.');
    $page->pressButton('Save');
    // Success!
    $this->assertStringEndsWith('node/1', $this->getSession()->getCurrentUrl());

    $node = Node::load(1);
    // @phpstan-ignore-next-line
    $this->assertInstanceOf(Node::class, $node);
    // Create a translation from the original English node.
    $translation = $node->addTranslation('fr');
    $this->assertInstanceOf(Node::class, $translation);
    $this->container->get('content_translation.manager')->getTranslationMetadata($translation)->setSource($node->language()->getId());
    // @phpstan-ignore-next-line
    $translation->title = 'The French title';
    $translation->save();
    $translation = $node->getTranslation('fr');
    $props = $node->get('field_xb_demo')[0]->get('props');
    $this->assertInstanceOf(ComponentPropsValues::class, $props);
    $original_props_value = $props->getValue();

    // In both the Symmetric and Asymmetric translation cases, the `props` field
    // is translatable and this should only change the translation.
    $french_prop = str_replace('hello, world!', 'bonjour, monde!', $original_props_value);
    $translation->get('field_xb_demo')[0]->set('props', $french_prop);
    $translation->save();

    $updated_props_value = str_replace('hello, world!', 'hello, new world!', $original_props_value);
    // In both the Symmetric and Asymmetric cases, the `props` field is
    // translatable and this should only change the original. If the field is
    // not translatable, this should change both the original and the
    // translation.
    $node->get('field_xb_demo')[0]->set('props', $updated_props_value);
    $tree = $node->get('field_xb_demo')[0]->get('tree');
    $this->assertInstanceOf(ComponentTreeStructure::class, $tree);
    $tree_value = $tree->getValue();
    $tree_decoded = json_decode($tree_value, TRUE);
    // Remove the component that shows the thumbnail from the tree.
    // In the asymmetric case, where 'tree' is translatable, this should only
    // affect the untranslated node.
    // In the symmetric case, where 'tree' is not translatable, this should
    // change both the original and the translation.
    $this->assertSame('dynamic-image-static-imageStyle-something7d', $tree_decoded['two-column-uuid']['column_two'][2]['uuid']);
    unset($tree_decoded['two-column-uuid']['column_two'][2]);
    $node->get('field_xb_demo')[0]->set('tree', json_encode($tree_decoded));
    $node->save();
    return $node;
  }

}
