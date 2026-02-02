<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponent;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent;
use Drupal\canvas\PropExpressions\StructuredData\Evaluator;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\Core\Url;
use Drupal\Tests\canvas\Kernel\Traits\CiModulePathTrait;
use Drupal\Tests\canvas\TestSite\CanvasTestSetup;
use Drupal\Tests\canvas\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
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
    $this->container->get('module_installer')->install(['system', 'canvas_test_sdc', 'canvas_test_block']);
    $this->container->get('config.factory')->getEditable('system.theme')->set('default', 'stark')->save();

    (new CanvasTestSetup())->setup();
    $this->setUpCurrentUser(permissions: ['edit any article content', 'administer themes', Page::EDIT_PERMISSION]);
  }

  public function testDescription(): void {
    $node = $this->createNode(['type' => 'article', 'title' => 'Test node']);
    self::assertCount(0, $node->validate());
    $node->save();

    $component_id = 'sdc.canvas_test_sdc.my-cta';
    $form_canvas_props = $this->getFormCanvasPropsForComponent($component_id);
    $component_entity = Component::load($component_id);
    \assert($component_entity instanceof ComponentInterface);
    $crawler = $this->getCrawlerForFormRequest(
      '/canvas/api/v0/form/component-instance/node/1',
      $component_entity,
      $form_canvas_props
    );
    self::assertStringContainsString('The title for the cta', $crawler->text());
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
              'expression' => 'ℹ︎entity_reference␟entity␜␜entity:media:image␝field_media_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
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
              'expression' => 'ℹ︎entity_reference␟entity␜␜entity:media:image␝field_media_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
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
              'expression' => 'ℹ︎entity_reference␟entity␜␜entity:media:image␝field_media_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
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
              'expression' => 'ℹ︎entity_reference␟entity␜␜entity:media:image␝field_media_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
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
              'expression' => 'ℹ︎entity_reference␟entity␜␜entity:media:image␝field_media_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
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

  private static function findSuggestionByLabel(string|array $label, string $prop, array $suggestions): array {
    $is_final_level = is_string($label) || count($label) === 1;
    $needle = is_array($label) ? reset($label) : $label;
    // When recursing, the $prop key won't exist.
    $haystack = array_key_exists($prop, $suggestions) ? $suggestions[$prop] : $suggestions;
    \assert(array_is_list($haystack));
    foreach ($haystack as $suggestion) {
      if ($suggestion['label'] === $needle) {
        if ($is_final_level) {
          \assert(array_key_exists('id', $suggestion));
          \assert(array_key_exists('source', $suggestion));
          \assert(array_key_exists('label', $suggestion));
          return $suggestion;
        }
        return self::findSuggestionByLabel(array_slice($label, 1), $prop, $suggestion['items']);
      }
    }
    throw new \LogicException(\sprintf('No suggestion found for prop %s with label %s', $prop, $needle));
  }

  public function testDynamicProps(): void {
    $node = $this->createNode(['type' => 'article', 'title' => 'Test node']);
    self::assertCount(0, $node->validate());
    $node->save();
    self::assertEmpty($node->get('media_image_field'));
    $template = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [],
    ]);
    $template->save();

    $component_id = 'sdc.canvas_test_sdc.my-hero';
    $this->setUpCurrentUser(permissions: ['administer content templates', 'edit any article content']);
    $fieldSuggestions = self::decodeResponse($this->parentRequest(Request::create("canvas/api/v0/ui/content_template/suggestions/prop-sources/node/article/$component_id")));

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
    // test (…/suggestions/prop-sources/…).
    $form_canvas_props['source']['heading'] = self::findSuggestionByLabel('Title', 'heading', $fieldSuggestions)['source'];
    $form_canvas_props['source']['subheading'] = self::findSuggestionByLabel(['A Media Image Field', 'Media', 'Name'], 'subheading', $fieldSuggestions)['source'];
    // Confirm:
    // - `heading` can NOT be NULL, and currently evaluates to a not-NULL value
    // - `subheading` CAN be NULL, and currently evaluates to NULL
    // @phpstan-ignore-next-line method.nonObject
    self::assertTrue($node->getFieldDefinition('title')->isRequired());
    self::assertSame('Test node', Evaluator::evaluate($node, expr: StructuredDataPropExpression::fromString($form_canvas_props['source']['heading']['expression']), is_required: FALSE)->value);
    // @phpstan-ignore-next-line method.nonObject
    self::assertFalse($node->getFieldDefinition('media_image_field')->isRequired());
    self::assertNull(Evaluator::evaluate($node, expr: StructuredDataPropExpression::fromString($form_canvas_props['source']['subheading']['expression']), is_required: FALSE)->value);
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
      self::assertSame('`ℹ︎␜entity:node:page␝media_image_field␞␟entity␜␜entity:media␝name␞␟value` is an expression for entity type `node`, bundle(s) `page`, but the provided entity is of the bundle `article`.', $e->getMessage());
    }
  }

  /**
   * Tests additional edge cases of the impact of broken components.
   *
   * @see \Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource\JsComponentTest::testIsBroken())
   */
  #[TestWith([
    'canvas_test_block_input_none',
    '',
    'Previously stored input {"id":"canvas_test_block_input_none","label":"Test block with no settings.","label_display":"0","provider":"canvas_test_block"}',
  ])]
  #[TestWith([
    'canvas_test_block_input_validatable',
    'Name Enter a name to display in the block.',
    'Previously stored input {"id":"canvas_test_block_input_validatable","label":"Test Block with settings","label_display":"0","name":"Canvas","provider":"canvas_test_block"}',
  ])]
  public function testBlockComponentThatHasGoneAway(string $block_plugin_id, string $expected_form_when_not_broken, string $fyi): void {
    $page = Page::create(['title' => $this->randomMachineName()]);
    self::assertSame(SAVED_NEW, $page->save());

    // Loading the component instance form initially should be possible.
    $component = Component::load(BlockComponent::SOURCE_PLUGIN_ID . ".$block_plugin_id");
    self::assertInstanceOf(Component::class, $component);
    self::assertCount(0, $component->getTypedData()->validate());
    $response = $this->getCrawlerForFormRequest('/canvas/api/v0/form/component-instance/canvas_page/' . $page->id(), $component, []);
    self::assertSame($expected_form_when_not_broken, $response->text());

    // Create a component instance with the block plugin's default settings.
    $page->setComponentTree([
      [
        'uuid' => '5f18db31-fa2f-4f4e-a377-dc0c6a0b7dc4',
        'component_id' => $component->id(),
        'inputs' => $component->getSettings()['default_settings'],
      ],
    ]);
    self::assertCount(0, $page->validate());
    self::assertSame(SAVED_UPDATED, $page->save());

    // Simulate the tested block plugin being broken.
    // @see \Drupal\Tests\canvas\Kernel\BrokenBlockManager
    // @see \Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource\BlockComponentTest::triggerBrokenComponent()
    $this->container->get('state')->set('canvas_test_block.remove_definitions', [$block_plugin_id]);
    $this->container->get('plugin.manager.block')->clearCachedDefinitions();

    // Despite the tested block plugin being broken:
    self::assertTrue($component->getComponentSource()->isBroken());
    // - The stored component tree is still valid: it references the Component.
    self::assertCount(0, $page->validate());
    // - The Component became invalid though.
    self::assertGreaterThan(0, $component->getTypedData()->validate()->count());

    $response = $this->getCrawlerForFormRequest('/canvas/api/v0/form/component-instance/canvas_page/' . $page->id(), $component, json_decode('undefined'));
    self::assertSame("Component is missing. Fix the component or copy values to a new component. $fyi", $response->text());
  }

  /**
   * Tests additional edge cases of the impact of broken components.
   *
   * @see \Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource\JsComponentTest::testIsBroken())
   */
  public function testCodeComponentNoPropsThatHasGoneAway(): void {
    $page = Page::create(['title' => $this->randomMachineName()]);
    self::assertSame(SAVED_NEW, $page->save());

    $code_component = JavaScriptComponent::create([
      'machineName' => 'no-props-component',
      'name' => 'No Props Component',
      'status' => TRUE,
      'props' => [],
      'slots' => [],
      'js' => [
        'original' => '',
        'compiled' => '',
      ],
      'css' => [
        'original' => '',
        'compiled' => '',
      ],
      'dataDependencies' => [],
    ]);
    self::assertCount(0, $code_component->getTypedData()->validate());
    $code_component->save();

    $component = Component::load(JsComponent::componentIdFromJavascriptComponentId($code_component->id()));
    self::assertInstanceOf(Component::class, $component);
    $response = $this->getCrawlerForFormRequest('/canvas/api/v0/form/component-instance/canvas_page/' . $page->id(), $component, []);
    self::assertSame('', $response->text());

    // Create an instance of the no-props code component.
    $page->setComponentTree([
      [
        'uuid' => '5f18db31-fa2f-4f4e-a377-dc0c6a0b7dc4',
        'component_id' => $component->id(),
        'inputs' => [],
      ],
    ]);
    self::assertCount(0, $page->validate());
    self::assertSame(SAVED_UPDATED, $page->save());

    // Delete the code component through the config factory to avoid normal
    // dependency cleanup that would also remove the Component entity.
    $this->container->get('config.factory')
      ->getEditable($code_component->getConfigDependencyName())
      ->delete();

    $response = $this->getCrawlerForFormRequest('/canvas/api/v0/form/component-instance/canvas_page/' . $page->id(), $component, json_decode('undefined'));
    self::assertSame('Component is missing. Fix the component or copy values to a new component. Previously stored input []', $response->text());
  }

  private function getCrawlerForFormRequest(string $form_url, ComponentInterface $component_entity, ?array $form_canvas_props): Crawler {
    // `$form_canvas_props` is nullable, so we can simulate the request having
    // `undefined` as value, which happens when the inputs are empty on a
    // missing component.
    // @todo Make it not nullable in https://www.drupal.org/i/3558721
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
