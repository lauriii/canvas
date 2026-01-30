<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional\Config;

use Drupal\Core\Session\AccountInterface;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\canvas\Functional\FunctionalTestBase;

/**
 * @covers \Drupal\canvas\Entity\ContentTemplate::onDependencyRemoval
 *
 * @group canvas
 */
final class ContentTemplateOnDependencyRemovalTest extends FunctionalTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'entity_test',
    'canvas',
    'field_ui',
    'link',
    'node',
    'canvas_test_sdc',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $configSchemaCheckerExclusions = [
    // @todo Core bug: this is missing config schema: `type: field.storage_settings.single_internal_property_test` does not exist! This is being fixed in https://www.drupal.org/project/drupal/issues/3324140.
    'field.storage.node.field_slogan',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Set up a content type with two simple fields.
    $this->drupalCreateContentType(['type' => 'article']);
    $field_storage = FieldStorageConfig::create([
      'entity_type' => 'node',
      // @see \Drupal\entity_test\Plugin\Field\FieldType\SingleInternalPropertyTestFieldItem
      'type' => 'single_internal_property_test',
      'field_name' => 'field_slogan',
      'settings' => [
        'case_sensitive' => TRUE,
      ],
    ]);
    $field_storage->save();
    FieldConfig::create([
      'field_storage' => $field_storage,
      'bundle' => 'article',
      'label' => 'Slogan',
    ])->save();

    $field_storage = FieldStorageConfig::create([
      'entity_type' => 'node',
      'type' => 'string',
      'field_name' => 'field_motto',
    ]);
    $field_storage->save();
    FieldConfig::create([
      'field_storage' => $field_storage,
      'bundle' => 'article',
      'label' => 'Motto',
    ])->save();

    $field_storage = FieldStorageConfig::create([
      'entity_type' => 'node',
      'type' => 'link',
      'field_name' => 'field_more_info',
    ]);
    $field_storage->save();
    FieldConfig::create([
      'field_storage' => $field_storage,
      'bundle' => 'article',
      'label' => 'More info',
    ])->save();

    // Opt the content type into Canvas rendering by adding a component tree field.
    $field_storage = FieldStorageConfig::create([
      'type' => 'component_tree',
      'entity_type' => 'node',
      'field_name' => 'field_canvas_tree',
    ]);
    $field_storage->save();
    FieldConfig::create([
      'field_storage' => $field_storage,
      'bundle' => 'article',
    ])->save();

    // Create a simple template that uses string fields to populate component
    // instances.
    $template = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [
        [
          'uuid' => '02b766f7-0edc-4359-98bb-3f489e878330',
          'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
          'inputs' => [
            'heading' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:article␝field_motto␞␟value',
            ],
          ],
        ],
        [
          'uuid' => '4ca2cb2e-f9ac-40e5-83be-0f2d08b455b3',
          'component_id' => 'sdc.canvas_test_sdc.my-cta',
          'inputs' => [
            'text' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:article␝field_slogan␞␟value',
            ],
            'href' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:article␝field_more_info␞␟uri',
            ],
            'target' => '_blank',
          ],
        ],
      ],
    ]);
    $template->setStatus(TRUE)->save();
    // All fields should be hard dependencies of the template.
    $dependencies = $template->getDependencies();
    $this->assertContains('field.field.node.article.field_slogan', $dependencies['config']);
    $this->assertContains('field.field.node.article.field_motto', $dependencies['config']);
    $this->assertContains('field.field.node.article.field_more_info', $dependencies['config']);
  }

  public function testRemoveFieldUsedByTemplate(): void {
    // Create an article node and confirm that Canvas is rendering it.
    $node = $this->drupalCreateNode([
      'type' => 'article',
      'field_slogan' => 'My slogan',
      'field_motto' => 'My important motto',
      'field_more_info' => 'https://example.com',
    ]);
    $this->drupalGet($node->toUrl());
    $assert_session = $this->assertSession();
    $assert_session->pageTextContains('My slogan');
    // "Press" is the first example value of the my-cta SDC's `text` prop.
    // @see core/modules/system/tests/modules/sdc_test/components/my-cta/my-cta.component.yml
    $assert_session->pageTextNotContains('Press');
    $assert_session->pageTextContains('My important motto');
    $assert_session->linkByHrefExists('https://example.com');

    // Log in with permission to administer fields, and go delete one of the
    // fields in use by the template.
    $account = $this->drupalCreateUser(['administer node fields']);
    \assert($account instanceof AccountInterface);
    $this->drupalLogin($account);
    $this->drupalGet('/admin/structure/types/manage/article/fields/node.article.field_slogan/delete');
    // The template should be among the things being updated, but nothing should
    // be getting deleted.
    $assert_session->pageTextContains('The listed configuration will be updated.');
    $assert_session->pageTextContains('article content items — Full content view');
    $assert_session->pageTextNotContains('The listed configuration will be deleted.');
    $this->getSession()->getPage()->pressButton('Delete');
    $assert_session->statusMessageContains('The field Slogan has been deleted from the article content type.');

    // Revisit the node to ensure it still renders. The missing input should
    // be replaced with an example value.
    $this->drupalGet($node->toUrl());
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextNotContains('My slogan');
    $assert_session->pageTextContains('Press');
    $assert_session->pageTextContains('My important motto');
    $assert_session->linkByHrefExists('https://example.com');
    // There shouldn't be any error message (which we would see if any props
    // were invalid or broken).
    $assert_session->pageTextNotContains('Oops, something went wrong! Site admins have been notified.');

    // Ensure that the missing input was actually replaced by a static prop
    // source.
    $tree = ContentTemplate::load('node.article.full')?->getComponentTree();
    $item = $tree?->get(1);
    \assert($item instanceof ComponentTreeItem);
    $input = $item->getInputs();
    // The stored value is the default specified in the component's metadata.
    // @see core/modules/system/tests/modules/sdc_test/components/my-cta/my-cta.component.yml
    $this->assertSame('Press', $input['text'] ?? NULL);
  }

  public function testRemoveFieldTypeProviderModuleUsedByFieldInTemplate(): void {
    // The template does not depend on the module that is about to be
    // uninstalled, but it does depend on the field instance that depends on it.
    $content_template = ContentTemplate::load('node.article.full');
    \assert($content_template instanceof ContentTemplate);
    $slogan_field = FieldConfig::load('node.article.field_slogan');
    \assert($slogan_field instanceof FieldConfig);
    self::assertNotContains('entity_test', $content_template->getDependencies()['module']);
    self::assertContains($slogan_field->getConfigDependencyName(), $content_template->getDependencies()['config']);
    self::assertContains('entity_test', $slogan_field->getDependencies()['module']);

    // Create an article node and confirm that Canvas is rendering it.
    $node = $this->drupalCreateNode([
      'type' => 'article',
      'field_slogan' => 'My slogan',
      'field_motto' => 'My important motto',
      'field_more_info' => 'https://example.com',
    ]);
    $this->drupalGet($node->toUrl());
    $assert_session = $this->assertSession();
    $assert_session->pageTextContains('My slogan');
    $assert_session->pageTextContains('My important motto');
    $assert_session->linkByHrefExists('https://example.com');

    // Log in with permission to administer modules and observe that the
    // entity_test module (which provides the single_internal_property_test
    // field type) cannot be uninstalled.
    $account = $this->drupalCreateUser(['administer modules']);
    \assert($account instanceof AccountInterface);
    $this->drupalLogin($account);
    $this->drupalGet('/admin/modules/uninstall');
    $assert_session->elementAttributeExists('named', ['field', 'Entity CRUD test module'], 'disabled');
  }

}
