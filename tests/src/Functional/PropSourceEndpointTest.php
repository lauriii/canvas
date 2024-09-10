<?php

declare(strict_types=1);

use Drupal\Component\Serialization\Json;
use Drupal\image\Plugin\Field\FieldType\ImageItem;
use Drupal\node\Entity\Node;
use Drupal\Tests\BrowserTestBase;

/**
 * The functional test equivalent of FieldForComponentSuggesterTest.
 *
 * @covers \Drupal\experience_builder\Controller\SdcController::getComponentsList()
 * @group experience_builder
 * @internal
 */
class PropSourceEndpointTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'experience_builder',
    'sdc_test_all_props',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected $profile = 'standard';

  public function test(): void {
    $page = $this->getSession()->getPage();
    $field_definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', 'article');
    $image_field_sample_value = ImageItem::generateSampleValue($field_definitions['field_hero']);
    $node = Node::create([
      'type' => 'article',
      'title' => 'XB Needs This For The Time Being',
      'field_hero' => $image_field_sample_value,
    ]);
    $node->save();
    $this->drupalLogin($this->rootUser);
    $this->drupalGet('xb-components');

    // Ensure the response is cached by Dynamic Page Cache (because this is a
    // complex response), but not by Page Cache (because it should not be
    // available to anonymous users).
    $this->assertSession()->responseHeaderEquals('X-Drupal-Cache-Tags', 'config:component_list http_response');
    $this->assertSession()->responseHeaderEquals('X-Drupal-Cache-Contexts', 'user.permissions');
    $this->assertSession()->responseHeaderEquals('X-Drupal-Cache-Max-Age', '-1 (Permanent)');
    $this->assertSession()->responseHeaderEquals('X-Drupal-Dynamic-Cache', 'MISS');
    $this->assertSession()->responseHeaderDoesNotExist('X-Drupal-Cache');
    $this->drupalGet('xb-components');
    $this->assertSession()->responseHeaderEquals('X-Drupal-Dynamic-Cache', 'HIT');
    $this->assertSession()->responseHeaderDoesNotExist('X-Drupal-Cache');

    $data = Json::decode($page->getText());
    $data = array_intersect_key(
      $data,
      [
        'experience_builder:image' => TRUE,
        'experience_builder:my-hero' => TRUE,
        'sdc_test_all_props:all-props' => TRUE,
      ],
    );
    $this->assertCount(3, $data);
    $expected = $this->getExpected();
    foreach ($data as $sdc => $prop_sources) {
      $this->assertSame($expected[$sdc]['dynamic_prop_source_candidates'], $data[$sdc]['dynamic_prop_source_candidates']);
    }
  }

  /**
   * For each SDC prop, the expected candidate field instance expressions.
   *
   * @see \Drupal\experience_builder\FieldForComponentSuggester
   *
   * @return array<string, array{id: string, dynamic_prop_source_candidates: array<string, array<string, string>>}>
   */
  public function getExpected(): array {
    return [
      'experience_builder:image' => [
        'id' => 'experience_builder:image',
        'dynamic_prop_source_candidates' => [
          'image' => [
            'This Article\'s Hero' => 'ℹ︎␜entity:node:article␝field_hero␞␟{src↝entity␜␜entity:file␝uri␞␟url,alt↠alt,width↠width,height↠height}',
          ],
        ],
      ],
      'experience_builder:my-hero' => [
        'id' => 'experience_builder:my-hero',
        'dynamic_prop_source_candidates' => [
          'heading' => [
            'This Article\'s Title' => 'ℹ︎␜entity:node:article␝title␞␟value',
          ],
          'subheading' => [
            "This Article's Hero" => 'ℹ︎␜entity:node:article␝field_hero␞␟title',
            "This Article's Image" => 'ℹ︎␜entity:node:article␝field_image␞␟title',
            "This Article's Tags" => 'ℹ︎␜entity:node:article␝field_tags␞␟entity␜␜entity:taxonomy_term␝revision_log_message␞␟value',
            "This Article's Revision log message" => 'ℹ︎␜entity:node:article␝revision_log␞␟value',
            "This Article's Title" => 'ℹ︎␜entity:node:article␝title␞␟value',
          ],
          'cta1' => [
            "This Article's Hero" => 'ℹ︎␜entity:node:article␝field_hero␞␟title',
            "This Article's Image" => 'ℹ︎␜entity:node:article␝field_image␞␟title',
            "This Article's Tags" => 'ℹ︎␜entity:node:article␝field_tags␞␟entity␜␜entity:taxonomy_term␝revision_log_message␞␟value',
            "This Article's Revision log message" => 'ℹ︎␜entity:node:article␝revision_log␞␟value',
            "This Article's Title" => 'ℹ︎␜entity:node:article␝title␞␟value',
          ],
          'cta1href' => [
            "This Article's Hero" => 'ℹ︎␜entity:node:article␝field_hero␞␟entity␜␜entity:file␝uri␞␟value',
          ],
          'cta2' => [
            "This Article's Hero" => 'ℹ︎␜entity:node:article␝field_hero␞␟title',
            "This Article's Image" => 'ℹ︎␜entity:node:article␝field_image␞␟title',
            "This Article's Tags" => 'ℹ︎␜entity:node:article␝field_tags␞␟entity␜␜entity:taxonomy_term␝revision_log_message␞␟value',
            "This Article's Revision log message" => 'ℹ︎␜entity:node:article␝revision_log␞␟value',
            "This Article's Title" => 'ℹ︎␜entity:node:article␝title␞␟value',
          ],
        ],
      ],
      'sdc_test_all_props:all-props' => [
        'id' => 'sdc_test_all_props:all-props',
        'dynamic_prop_source_candidates' => [
          'test_bool' => [
            "This Article's Default translation" => 'ℹ︎␜entity:node:article␝default_langcode␞␟value',
            "This Article's Hero" => 'ℹ︎␜entity:node:article␝field_hero␞␟entity␜␜entity:file␝status␞␟value',
            "This Article's Image" => 'ℹ︎␜entity:node:article␝field_image␞␟entity␜␜entity:file␝status␞␟value',
            "This Article's Tags" => 'ℹ︎␜entity:node:article␝field_tags␞␟entity␜␜entity:taxonomy_term␝status␞␟value',
            "This Article's Promoted to front page" => 'ℹ︎␜entity:node:article␝promote␞␟value',
            "This Article's Default revision" => 'ℹ︎␜entity:node:article␝revision_default␞␟value',
            "This Article's Revision translation affected" => 'ℹ︎␜entity:node:article␝revision_translation_affected␞␟value',
            "This Article's Revision user" => 'ℹ︎␜entity:node:article␝revision_uid␞␟entity␜␜entity:user␝status␞␟value',
            "This Article's Published" => 'ℹ︎␜entity:node:article␝status␞␟value',
            "This Article's Sticky at top of lists" => 'ℹ︎␜entity:node:article␝sticky␞␟value',
            "This Article's Authored by" => 'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝status␞␟value',
          ],
          'test_string' => [
            "This Article's Hero" => 'ℹ︎␜entity:node:article␝field_hero␞␟title',
            "This Article's Image" => 'ℹ︎␜entity:node:article␝field_image␞␟title',
            "This Article's Tags" => 'ℹ︎␜entity:node:article␝field_tags␞␟entity␜␜entity:taxonomy_term␝revision_log_message␞␟value',
            "This Article's Revision log message" => 'ℹ︎␜entity:node:article␝revision_log␞␟value',
            "This Article's Title" => 'ℹ︎␜entity:node:article␝title␞␟value',
          ],
          'test_string_multiline' => [
            "This Article's Tags" => 'ℹ︎␜entity:node:article␝field_tags␞␟entity␜␜entity:taxonomy_term␝revision_log_message␞␟value',
            "This Article's Revision log message" => 'ℹ︎␜entity:node:article␝revision_log␞␟value',
          ],
          'test_REQUIRED_string' => [
            "This Article's Title" => 'ℹ︎␜entity:node:article␝title␞␟value',
          ],
          'test_string_enum' => [],
          'test_string_format_date_time' => [],
          'test_string_format_date' => [],
          'test_string_format_email' => [
            "This Article's Revision user" => 'ℹ︎␜entity:node:article␝revision_uid␞␟entity␜␜entity:user␝mail␞␟value',
            "This Article's Authored by" => 'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝mail␞␟value',
          ],
          'test_string_format_idn_email' => [
            "This Article's Revision user" => 'ℹ︎␜entity:node:article␝revision_uid␞␟entity␜␜entity:user␝mail␞␟value',
            "This Article's Authored by" => 'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝mail␞␟value',
          ],
          'test_string_format_uri' => [
            "This Article's Hero" => 'ℹ︎␜entity:node:article␝field_hero␞␟entity␜␜entity:file␝uri␞␟value',
            "This Article's Image" => 'ℹ︎␜entity:node:article␝field_image␞␟entity␜␜entity:file␝uri␞␟value',
          ],
          'test_string_format_iri' => [
            "This Article's Hero" => 'ℹ︎␜entity:node:article␝field_hero␞␟entity␜␜entity:file␝uri␞␟value',
            "This Article's Image" => 'ℹ︎␜entity:node:article␝field_image␞␟entity␜␜entity:file␝uri␞␟value',
          ],
          'test_integer' => [
            "This Article's Changed" => 'ℹ︎␜entity:node:article␝changed␞␟value',
            "This Article's Comments" => 'ℹ︎␜entity:node:article␝comment␞␟status',
            "This Article's Authored on" => 'ℹ︎␜entity:node:article␝created␞␟value',
            "This Article's Hero" => 'ℹ︎␜entity:node:article␝field_hero␞␟width',
            "This Article's Image" => 'ℹ︎␜entity:node:article␝field_image␞␟width',
            "This Article's Tags" => 'ℹ︎␜entity:node:article␝field_tags␞␟target_id',
            "This Article's ID" => 'ℹ︎␜entity:node:article␝nid␞␟value',
            "This Article's URL alias" => 'ℹ︎␜entity:node:article␝path␞␟pid',
            "This Article's Revision create time" => 'ℹ︎␜entity:node:article␝revision_timestamp␞␟value',
            "This Article's Revision user" => 'ℹ︎␜entity:node:article␝revision_uid␞␟target_id',
            "This Article's Authored by" => 'ℹ︎␜entity:node:article␝uid␞␟target_id',
            "This Article's Revision ID" => 'ℹ︎␜entity:node:article␝vid␞␟value',
          ],
          'test_integer_range_minimum' => [],
          'test_integer_range_minimum_maximum_timestamps' => [
            "This Article's Revision user" => 'ℹ︎␜entity:node:article␝revision_uid␞␟entity␜␜entity:user␝login␞␟value',
            "This Article's Authored by" => 'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝login␞␟value',
          ],
          'test_object_drupal_image' => [
            "This Article's Hero" => 'ℹ︎␜entity:node:article␝field_hero␞␟{src↝entity␜␜entity:file␝uri␞␟url,alt↠alt,width↠width,height↠height}',
          ],
        ],
      ],
    ];
  }

}
