<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\Core\Url;
use Drupal\Tests\canvas\Kernel\Traits\CiModulePathTrait;
use Drupal\Tests\canvas\TestSite\CanvasTestSetup;
use Drupal\Tests\canvas\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

/**
 * @coversClass \Drupal\canvas\Form\ComponentInstanceForm
 * @covers \Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase::buildConfigurationForm()
 * @group canvas
 */
final class ComponentInstanceFormTest extends ApiLayoutControllerTestBase {

  use CiModulePathTrait;
  use ContribStrictConfigSchemaTestTrait;
  use NodeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('theme_installer')->install(['stark']);
    $this->container->get('module_installer')->install(['system', 'canvas_test_sdc']);
    $this->container->get('config.factory')->getEditable('system.theme')->set('default', 'stark')->save();

    (new CanvasTestSetup())->setup();
    $this->setUpCurrentUser(permissions: ['edit any article content', 'administer themes']);
  }

  #[DataProvider('providerOptionalImages')]
  public function testOptionalImageAndHeading(string $component, array $values_to_set, array $expected_form_canvas_props): void {
    $actual_form_canvas_props = $this->getFormCanvasPropsForComponent($component);
    foreach (array_keys($actual_form_canvas_props['resolved']) as $sdc_prop_name) {
      if (array_key_exists($sdc_prop_name, $values_to_set)) {
        $actual_form_canvas_props['resolved'][$sdc_prop_name] = $values_to_set[$sdc_prop_name]['resolved'];
        $actual_form_canvas_props['source'][$sdc_prop_name]['value'] = $values_to_set[$sdc_prop_name]['source'];
      }
    }
    self::assertSame($expected_form_canvas_props, $actual_form_canvas_props);

    $component_entity = Component::load($component);
    \assert($component_entity instanceof ComponentInterface);
    $this->getCrawlerForFormRequest('/canvas/api/v0/form/component-instance/node/1', $component_entity, $expected_form_canvas_props);
  }

  public static function providerOptionalImages(): array {
    return [
      'sdc.canvas_test_sdc.image-optional-without-example as in component list' => [
        'sdc.canvas_test_sdc.image-optional-without-example',
        [],
        [
          'resolved' => [
            'image' => [],
          ],
          'source' => [
            'image' => [
              'value' => [],
              'sourceType' => 'static:field_item:entity_reference',
              'expression' => 'ℹ︎entity_reference␟{src↝entity␜␜entity:media:image␝field_media_image␞␟src_with_alternate_widths,alt↝entity␜␜entity:media:image␝field_media_image␞␟alt,width↝entity␜␜entity:media:image␝field_media_image␞␟width,height↝entity␜␜entity:media:image␝field_media_image␞␟height}',
              'sourceTypeSettings' => [
                'storage' => ['target_type' => 'media'],
                'instance' => [
                  'handler' => 'default:media',
                  'handler_settings' => [
                    'target_bundles' => ['image' => 'image'],
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
      'image-optional-with-example-and-additional-prop as in component list' => [
        'sdc.canvas_test_sdc.image-optional-with-example-and-additional-prop',
        [],
        [
          'resolved' => [
            'heading' => [],
            'image' => [
              'src' => self::getCiModulePath() . '/tests/modules/canvas_test_sdc/components/image-optional-with-example-and-additional-prop/gracie.jpg',
              'alt' => 'A good dog',
              'width' => 601,
              'height' => 402,
            ],
          ],
          'source' => [
            'heading' => [
              'value' => [],
              'sourceType' => 'static:field_item:string',
              'expression' => 'ℹ︎string␟value',
            ],
            'image' => [
              'value' => [],
              'sourceType' => 'static:field_item:entity_reference',
              'expression' => 'ℹ︎entity_reference␟{src↝entity␜␜entity:media:image␝field_media_image␞␟src_with_alternate_widths,alt↝entity␜␜entity:media:image␝field_media_image␞␟alt,width↝entity␜␜entity:media:image␝field_media_image␞␟width,height↝entity␜␜entity:media:image␝field_media_image␞␟height}',
              'sourceTypeSettings' => [
                'storage' => ['target_type' => 'media'],
                'instance' => [
                  'handler' => 'default:media',
                  'handler_settings' => [
                    'target_bundles' => ['image' => 'image'],
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
      'image-optional-with-example-and-additional-prop with heading set by user' => [
        'sdc.canvas_test_sdc.image-optional-with-example-and-additional-prop',
        [
          'heading' => [
            'resolved' => 'test',
            'source' => 'test',
          ],
        ],
        [
          'resolved' => [
            'heading' => 'test',
            'image' => [
              'src' => self::getCiModulePath() . '/tests/modules/canvas_test_sdc/components/image-optional-with-example-and-additional-prop/gracie.jpg',
              'alt' => 'A good dog',
              'width' => 601,
              'height' => 402,
            ],
          ],
          'source' => [
            'heading' => [
              'value' => 'test',
              'sourceType' => 'static:field_item:string',
              'expression' => 'ℹ︎string␟value',
            ],
            'image' => [
              'value' => [],
              'sourceType' => 'static:field_item:entity_reference',
              'expression' => 'ℹ︎entity_reference␟{src↝entity␜␜entity:media:image␝field_media_image␞␟src_with_alternate_widths,alt↝entity␜␜entity:media:image␝field_media_image␞␟alt,width↝entity␜␜entity:media:image␝field_media_image␞␟width,height↝entity␜␜entity:media:image␝field_media_image␞␟height}',
              'sourceTypeSettings' => [
                'storage' => ['target_type' => 'media'],
                'instance' => [
                  'handler' => 'default:media',
                  'handler_settings' => [
                    'target_bundles' => ['image' => 'image'],
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
      'image-gallery as in component list' => [
        'sdc.canvas_test_sdc.image-gallery',
        [],
        [
          'resolved' => [
            'caption' => [],
            'images' => [
              0 => [
                'src' => self::getCiModulePath() . '/tests/modules/canvas_test_sdc/components/image-gallery/gracie.jpg',
                'alt' => 'A good dog',
                'width' => 601,
                'height' => 402,
              ],
              1 => [
                'src' => self::getCiModulePath() . '/tests/modules/canvas_test_sdc/components/image-gallery/gracie.jpg',
                'alt' => 'Still a good dog',
                'width' => 601,
                'height' => 402,
              ],
              2 => [
                'src' => self::getCiModulePath() . '/tests/modules/canvas_test_sdc/components/image-gallery/UPPERCASE-GRACIE.JPG',
                'alt' => 'THE BEST DOG!',
                'width' => 601,
                'height' => 402,
              ],
            ],
          ],
          'source' => [
            'caption' => [
              'value' => [],
              'sourceType' => 'static:field_item:string',
              'expression' => 'ℹ︎string␟value',
            ],
            'images' => [
              'value' => [],
              'sourceType' => 'static:field_item:entity_reference',
              'expression' => 'ℹ︎entity_reference␟{src↝entity␜␜entity:media:image␝field_media_image␞␟src_with_alternate_widths,alt↝entity␜␜entity:media:image␝field_media_image␞␟alt,width↝entity␜␜entity:media:image␝field_media_image␞␟width,height↝entity␜␜entity:media:image␝field_media_image␞␟height}',
              'sourceTypeSettings' => [
                'storage' => ['target_type' => 'media'],
                'instance' => [
                  'handler' => 'default:media',
                  'handler_settings' => [
                    'target_bundles' => ['image' => 'image'],
                  ],
                ],
                'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
              ],
            ],
          ],
        ],
      ],
      'image-gallery with caption set by user' => [
        'sdc.canvas_test_sdc.image-gallery',
        [
          'caption' => [
            'resolved' => 'Delightful dogs!',
            'source' => 'Delightful dogs!',
          ],
        ],
        [
          'resolved' => [
            'caption' => 'Delightful dogs!',
            'images' => [
              0 => [
                'src' => self::getCiModulePath() . '/tests/modules/canvas_test_sdc/components/image-gallery/gracie.jpg',
                'alt' => 'A good dog',
                'width' => 601,
                'height' => 402,
              ],
              1 => [
                'src' => self::getCiModulePath() . '/tests/modules/canvas_test_sdc/components/image-gallery/gracie.jpg',
                'alt' => 'Still a good dog',
                'width' => 601,
                'height' => 402,
              ],
              2 => [
                'src' => self::getCiModulePath() . '/tests/modules/canvas_test_sdc/components/image-gallery/UPPERCASE-GRACIE.JPG',
                'alt' => 'THE BEST DOG!',
                'width' => 601,
                'height' => 402,
              ],
            ],
          ],
          'source' => [
            'caption' => [
              'value' => 'Delightful dogs!',
              'sourceType' => 'static:field_item:string',
              'expression' => 'ℹ︎string␟value',
            ],
            'images' => [
              'value' => [],
              'sourceType' => 'static:field_item:entity_reference',
              'expression' => 'ℹ︎entity_reference␟{src↝entity␜␜entity:media:image␝field_media_image␞␟src_with_alternate_widths,alt↝entity␜␜entity:media:image␝field_media_image␞␟alt,width↝entity␜␜entity:media:image␝field_media_image␞␟width,height↝entity␜␜entity:media:image␝field_media_image␞␟height}',
              'sourceTypeSettings' => [
                'storage' => ['target_type' => 'media'],
                'instance' => [
                  'handler' => 'default:media',
                  'handler_settings' => [
                    'target_bundles' => ['image' => 'image'],
                  ],
                ],
                'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
              ],
            ],
          ],
        ],
      ],
    ];
  }

  public function testDynamicProps(): void {
    $node = $this->createNode(['type' => 'article', 'title' => 'Test node']);
    self::assertCount(0, $node->validate());
    $node->save();
    self::assertNull($node->getRevisionLogMessage());
    $template = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [],
    ]);
    $template->save();

    $component_id = 'sdc.canvas_test_sdc.my-hero';
    $this->setUpCurrentUser(permissions: ['administer content templates', 'edit any article content']);
    $fieldSuggestions = self::decodeResponse($this->parentRequest(Request::create("canvas/api/v0/ui/content_template/suggestions/structured-data-for-prop_shapes/node/article/$component_id")));
    $getFieldSuggestionByLabel = function (string $label, string $prop) use ($fieldSuggestions) {
      foreach ($fieldSuggestions[$prop] as $suggestion) {
        if ($suggestion['label'] === $label) {
          return $suggestion;
        }
      }
      throw new \LogicException(sprintf('No suggestion found for prop %s with label %s', $prop, $label));
    };

    $form_canvas_props = $this->getFormCanvasPropsForComponent($component_id);
    $component_entity = Component::load($component_id);
    \assert($component_entity instanceof ComponentInterface);

    // The remaining test requests to
    // 'canvas.api.form.component_instance.content_template' require the
    // canvas_stark theme to be used. This is handled by
    // \Drupal\canvas\Theme\CanvasThemeNegotiator::applies() which checks if the
    // route starts with 'canvas.api.form'. In kernel tests however, this is
    // only triggered for the first request after the container is rebuilt.
    // @see \Drupal\canvas\Theme\CanvasThemeNegotiator::applies()
    $this->container->get('kernel')->rebuildContainer();
    $form_url = Url::fromRoute(
      'canvas.api.form.component_instance.content_template',
      [
        'entity' => $template->id(),
        'preview_entity' => $node->id(),
      ],
    )->toString();

    $crawler = $this->getCrawlerForFormRequest($form_url, $component_entity, $form_canvas_props);
    // Confirm the `heading` and `subheading` props are not yet linked to DynamicPropSources.
    self::assertCount(0, $crawler->filter('.canvas-linked-prop-wrapper[data-drupal-selector*="-heading-"]'));
    self::assertCount(0, $crawler->filter('.canvas-linked-prop-wrapper[data-drupal-selector*="-subheading-"]'));

    // Second request: with a valid expression in DynamicPropSource.
    // 💡 These are the ones provided by the API response at the start of the
    // test (…/suggestions/structured-data-for-prop_shapes/…).
    $form_canvas_props['source']['heading'] = $getFieldSuggestionByLabel('Title', 'heading')['source'];
    $form_canvas_props['source']['subheading'] = $getFieldSuggestionByLabel('Revision log message', 'subheading')['source'];
    $crawler = $this->getCrawlerForFormRequest($form_url, $component_entity, $form_canvas_props);
    // Confirm the linked prop fields are rendered.
    self::assertCount(2, $crawler->filter('.canvas-linked-prop-wrapper[data-drupal-selector*="-heading-"]'));
    self::assertCount(2, $crawler->filter('.canvas-linked-prop-wrapper[data-drupal-selector*="-subheading-"]'));

    // Third request: with an invalid expression in DynamicPropSource.
    // ⚠️ This cannot happen in the UI, but component trees could be manipulated
    // outside the UI. This shows what would happen when editing such
    // out-of-band manipulated component trees in the Canvas UI.
    $invalid_form_canvas_props = $form_canvas_props;
    $invalid_form_canvas_props['source']['subheading']['expression'] = str_replace('article', 'page', $invalid_form_canvas_props['source']['subheading']['expression']);
    try {
      $this->getCrawlerForFormRequest($form_url, $component_entity, $invalid_form_canvas_props);
      $this->fail('Expected DomainException not thrown.');
    }
    catch (\DomainException $e) {
      self::assertSame('`ℹ︎␜entity:node:page␝revision_log␞␟value` is an expression for entity type `node`, bundle(s) `page`, but the provided entity is of the bundle `article`.', $e->getMessage());
    }
  }

  private function getCrawlerForFormRequest(string $form_url, ComponentInterface $component_entity, array $form_canvas_props): Crawler {
    $json = self::decodeResponse($this->request(Request::create($form_url, 'PATCH', [
      'form_canvas_tree' => json_encode([
        'nodeType' => 'component',
        'slots' => [],
        'type' => "{$component_entity->id()}@{$component_entity->getActiveVersion()}",
        'uuid' => '5f18db31-fa2f-4f4e-a377-dc0c6a0b7dc4',
      ], JSON_THROW_ON_ERROR),
      'form_canvas_props' => json_encode($form_canvas_props, JSON_THROW_ON_ERROR),
      'form_canvas_selected' => '5f18db31-fa2f-4f4e-a377-dc0c6a0b7dc4',
    ])));
    self::assertArrayHasKey('html', $json);
    return new Crawler($json['html']);
  }

  protected function getFormCanvasPropsForComponent(string $component_id): array {
    $component_list_response = $this->parentRequest(Request::create('/canvas/api/v0/config/component'))->getContent();
    self::assertIsString($component_list_response);
    // @see RenderSafeComponentContainer::handleComponentException()
    self::assertStringNotContainsString('Component failed to render', $component_list_response, 'Component failed to render');
    self::assertStringNotContainsString('something went wrong', $component_list_response);
    // Fetch the client-side info.
    // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase::getClientSideInfo()
    $client_side_info_prop_sources = json_decode($component_list_response, TRUE)[$component_id]['propSources'];

    // Perform the same transformation the Canvas UI does in JavaScript to construct
    // the `form_canvas_props` request parameter expected by ComponentInstanceForm.
    // @see \Drupal\canvas\Form\ComponentInstanceForm::buildForm()
    // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase::buildConfigurationForm()
    $form_canvas_props = [
      // Used by client to render previews.
      'resolved' => [],
      // Used by client to provider server with metadata on how to construct an
      // input UX.
      'source' => [],
    ];
    foreach ($client_side_info_prop_sources as $sdc_prop_name => $prop_source) {
      $form_canvas_props['resolved'][$sdc_prop_name] = $prop_source['default_values']['resolved'] ?? [];
      $form_canvas_props['source'][$sdc_prop_name]['value'] = $prop_source['default_values']['source'] ?? [];
      $form_canvas_props['source'][$sdc_prop_name] += array_intersect_key($prop_source, array_flip([
        'sourceType',
        'sourceTypeSettings',
        'expression',
      ]));
    }
    return $form_canvas_props;
  }

}

