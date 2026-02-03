<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource;

use Drupal\canvas\Plugin\Canvas\ComponentSource\SingleDirectoryComponentDiscovery;
use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\GeneratedUrl;
use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\canvas\Entity\Component;
use Drupal\Core\Plugin\Component as SdcPlugin;
use Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\canvas\Plugin\Canvas\ComponentSource\SingleDirectoryComponent;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\canvas\PropSource\PropSource;
use Drupal\canvas\PropSource\StaticPropSource;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItem;
use Drupal\file\Entity\File;
use Drupal\link\LinkItemInterface;
use Drupal\media\Entity\Media;
use Drupal\media\Entity\MediaType;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\BrokenComponentManager;
use Drupal\Tests\canvas\Kernel\BrokenPluginManagerInterface;
use Drupal\Tests\canvas\Kernel\Traits\CiModulePathTrait;
use Drupal\Tests\canvas\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\canvas\Traits\SingleDirectoryComponentTreeTestTrait;
use Drupal\Tests\canvas\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\canvas\Traits\CrawlerTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\TestFileCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Twig\Error\Error;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

/**
 * @coversDefaultClass \Drupal\canvas\Plugin\Canvas\ComponentSource\SingleDirectoryComponent
 * @covers \Drupal\canvas\Plugin\Canvas\ComponentSource\SingleDirectoryComponentDiscovery
 * @group canvas
 * @group canvas_component_sources
 * @phpstan-import-type ComponentConfigEntityId from \Drupal\canvas\Entity\Component
 * @phpstan-import-type SingleComponentInputArray from \Drupal\canvas\Plugin\DataType\ComponentInputs
 */
final class SingleDirectoryComponentTest extends GeneratedFieldExplicitInputUxComponentSourceBaseTestBase {

  use ConstraintViolationsTestTrait;
  use ContribStrictConfigSchemaTestTrait;
  use SingleDirectoryComponentTreeTestTrait;
  use CiModulePathTrait;
  use CrawlerTrait;
  use MediaTypeCreationTrait;
  use TestFileCreationTrait;
  use ContentTypeCreationTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_test_sdc',
    'image',
    'media',
    'node',
    'path',
    'user',
    'field',
    'text',
  ];

  /**
   * @see ::testRenderSdcWithOptionalObjectShape())
   */
  protected string $componentWithOptionalImageProp = 'sdc.canvas_test_sdc.image-optional-with-example-and-additional-prop';

  /**
   * Setup tests.
   */
  public function setUp(): void {
    parent::setUp();
    // Fixate the private key & hash salt to get predictable `itok`.
    $this->container->get('state')->set('system.private_key', 'dynamic_image_style_private_key');
    $settings_class = new \ReflectionClass(Settings::class);
    $instance_property = $settings_class->getProperty('instance');
    $settings = new Settings([
      'hash_salt' => 'dynamic_image_style_hash_salt',
    ]);
    $instance_property->setValue(NULL, $settings);

    // We need to ensure the public://balloons.png image exists in the test
    // environment for the "Card with stream wrapper image" tests.
    $file_system = \Drupal::service('file_system');
    $extension_path_resolver = \Drupal::service('extension.path.resolver');
    $module_path = $extension_path_resolver->getPath('module', 'canvas_test_sdc');
    $source = $module_path . '/components/card/balloons.png';
    $destination = 'public://balloons.png';
    $directory = 'public://';
    $file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    $file_system->copy($source, $destination, FileExists::Replace);

    $this->container->get('theme_installer')->install(['sdc_theme_test']);
  }

  /**
   * @depends testDiscovery
   */
  public function testGetClientSideInfo(array $component_ids): void {
    $this->installEntitySchema('node');
    $this->installConfig('node');
    $this->createContentType(['type' => 'article']);
    parent::testGetClientSideInfo($component_ids);
  }

  /**
   * All test module SDCs must either have a Component or a reason why not.
   *
   * @covers \Drupal\canvas\Plugin\Canvas\ComponentSource\SingleDirectoryComponentDiscovery::discover()
   * @covers \Drupal\canvas\Plugin\Canvas\ComponentSource\SingleDirectoryComponentDiscovery::checkRequirements()
   */
  public function testDiscovery(): array {
    // Nothing discovered initially.
    self::assertSame([], $this->findIneligibleComponents(SingleDirectoryComponent::SOURCE_PLUGIN_ID, 'canvas_test_sdc'));
    self::assertSame([], $this->findCreatedComponentConfigEntities(SingleDirectoryComponent::SOURCE_PLUGIN_ID, 'canvas_test_sdc'));

    self::assertSame([], $this->findIneligibleComponents(SingleDirectoryComponent::SOURCE_PLUGIN_ID, 'sdc_theme_test'));
    self::assertSame([], $this->findCreatedComponentConfigEntities(SingleDirectoryComponent::SOURCE_PLUGIN_ID, 'sdc_theme_test'));

    // Trigger component generation, as if the test module was just installed.
    // (Kernel tests don't trigger all hooks that are triggered in reality.)
    $this->generateComponentConfig();

    self::assertSame([
      'sdc.canvas_test_sdc.empty-enum' => [
        'Prop "pets" has an empty enum value.',
      ],
      'sdc.canvas_test_sdc.html-invalid-format' => [
        'Invalid value "invalid" for "x-formatting-context". Valid values are "inline" and "block".',
      ],
      'sdc.canvas_test_sdc.image-required-with-invalid-example' => [
        'Prop "image" has invalid example value: [src] The property src is required',
      ],
      'sdc.canvas_test_sdc.image-required-without-example' => [
        'Prop "image" is required, but does not have example value',
      ],
      'sdc.canvas_test_sdc.image-srcset-candidate-template-uri' => [
        // This test SDC does indeed not have a corresponding StaticPropSource,
        // because its purpose is to test the ability for components to opt in
        // to consuming additional computed properties on a field instance.
        // See the `the image-srcset-candidate-template-uri component` test case
        // in PropSourceSuggesterTest.
        // @see \Drupal\Tests\canvas\Kernel\PropSourceSuggesterTest
        'Drupal Canvas does not know of a field type/widget to allow populating the <code>srcSetCandidateTemplate</code> prop, with the shape <code>{"type":"string","format":"uri-template","x-required-variables":["width"]}</code>.',
      ],
      'sdc.canvas_test_sdc.obsolete' => [
        'Component has "obsolete" status',
      ],
      'sdc.canvas_test_sdc.props-invalid-shapes' => [
        'Drupal Canvas does not know of a field type/widget to allow populating the <code>invalid_shape</code> prop, with the shape <code>{"type":"object"}</code>.',
      ],
      'sdc.canvas_test_sdc.props-no-examples' => [
        'Prop "heading" is required, but does not have example value',
      ],
      'sdc.canvas_test_sdc.props-no-title' => [
        'Prop "heading" must have title',
      ],
      'sdc.canvas_test_sdc.shoe_button' => [
        'Drupal Canvas does not know of a field type/widget to allow populating the <code>icon</code> prop, with the shape <code>{"type":"object","$ref":"json-schema-definitions://canvas.module/shoe-icon"}</code>.',
      ],
      'sdc.canvas_test_sdc.shoe_details' => [
        'Drupal Canvas does not know of a field type/widget to allow populating the <code>expand_icon</code> prop, with the shape <code>{"type":"object","$ref":"json-schema-definitions://canvas.module/shoe-icon"}</code>.',
        'Drupal Canvas does not know of a field type/widget to allow populating the <code>collapse_icon</code> prop, with the shape <code>{"type":"object","$ref":"json-schema-definitions://canvas.module/shoe-icon"}</code>.',
      ],
      'sdc.canvas_test_sdc.shoe_icon' => [
        'Prop "size" has an empty enum value.',
        'Prop "color" has an empty enum value.',
      ],
      'sdc.canvas_test_sdc.slots-no-title' => [
        'Slot "the_footer" must have title',
      ],
      'sdc.canvas_test_sdc.sparkline_min_2' => [
        // Drupal core's Field API only supports specifying "required or not",
        // and required means ">=1 value". There's no (native) ability to
        // configure a minimum number of values for a field.
        // @see https://www.drupal.org/project/unlimited_field_settings
        'Drupal Canvas does not know of a field type/widget to allow populating the <code>data</code> prop, with the shape <code>{"type":"array","items":{"type":"integer","minimum":-100,"maximum":100},"maxItems":100,"minItems":2}</code>.',
      ],
    ], $this->findIneligibleComponents(SingleDirectoryComponent::SOURCE_PLUGIN_ID, 'canvas_test_sdc'));
    self::assertSame([
      'sdc.sdc_theme_test.css-load-order' => ['Prop "dummy" must have title'],
      'sdc.sdc_theme_test.foo' => ['Prop "prop1" must have title'],
    ], $this->findIneligibleComponents(SingleDirectoryComponent::SOURCE_PLUGIN_ID, 'sdc_theme_test'));
    $auto_created_components = [
      ...$this->findCreatedComponentConfigEntities(SingleDirectoryComponent::SOURCE_PLUGIN_ID, 'canvas_test_sdc'),
      ...$this->findCreatedComponentConfigEntities(SingleDirectoryComponent::SOURCE_PLUGIN_ID, 'sdc_theme_test'),
    ];
    self::assertSame([
      'sdc.canvas_test_sdc.attributes',
      'sdc.canvas_test_sdc.banner',
      'sdc.canvas_test_sdc.card',
      'sdc.canvas_test_sdc.card-with-local-image',
      'sdc.canvas_test_sdc.card-with-remote-image',
      'sdc.canvas_test_sdc.card-with-stream-wrapper-image',
      'sdc.canvas_test_sdc.columns',
      'sdc.canvas_test_sdc.component-mismatch-meta-enum',
      'sdc.canvas_test_sdc.component-no-meta-enum',
      'sdc.canvas_test_sdc.crash',
      'sdc.canvas_test_sdc.date',
      'sdc.canvas_test_sdc.deprecated',
      'sdc.canvas_test_sdc.druplicon',
      'sdc.canvas_test_sdc.experimental',
      'sdc.canvas_test_sdc.grid-container',
      'sdc.canvas_test_sdc.heading',
      'sdc.canvas_test_sdc.image',
      'sdc.canvas_test_sdc.image-gallery',
      'sdc.canvas_test_sdc.image-optional-with-example',
      'sdc.canvas_test_sdc.image-optional-with-example-and-additional-prop',
      'sdc.canvas_test_sdc.image-optional-without-example',
      'sdc.canvas_test_sdc.image-required-with-example',
      'sdc.canvas_test_sdc.image-without-ref',
      'sdc.canvas_test_sdc.my-cta',
      'sdc.canvas_test_sdc.my-hero',
      'sdc.canvas_test_sdc.my-section',
      'sdc.canvas_test_sdc.one_column',
      'sdc.canvas_test_sdc.props-no-slots',
      'sdc.canvas_test_sdc.props-slots',
      'sdc.canvas_test_sdc.shoe_badge',
      'sdc.canvas_test_sdc.shoe_tab',
      'sdc.canvas_test_sdc.shoe_tab_group',
      'sdc.canvas_test_sdc.shoe_tab_panel',
      'sdc.canvas_test_sdc.sparkline',
      'sdc.canvas_test_sdc.tags',
      'sdc.canvas_test_sdc.two_column',
      'sdc.canvas_test_sdc.video',
      'sdc.sdc_theme_test.bar',
      'sdc.sdc_theme_test.lib-overrides',
      'sdc.sdc_theme_test.my-card',
      'sdc.sdc_theme_test_base.my-card-no-schema',
    ], $auto_created_components);

    return array_combine($auto_created_components, $auto_created_components);
  }

  /**
   * Tests the shape-matched `prop_field_definitions` for the eligible SDCs.
   *
   * @depends testDiscovery
   */
  public function testSettings(array $component_ids): void {
    $settings = $this->getAllSettings($component_ids);
    self::assertSame(self::getExpectedSettings(), $settings);

    // Slightly more scrutiny for ComponentSources with a generated field-based
    // input UX: verifying this results in working `StaticPropSource`s is
    // sufficient, everything beyond that is covered by PropShapeRepositoryTest.
    // @see \Drupal\Tests\canvas\Kernel\PropShapeRepositoryTest::testPropShapesYieldWorkingStaticPropSources()
    // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase
    $components = $this->componentStorage->loadMultiple($component_ids);
    foreach ($components as $component_id => $component) {
      // Use reflection to test the private ::getDefaultStaticPropSource() method.
      \assert($component instanceof Component);
      $source = $component->getComponentSource();
      $private_method = new \ReflectionMethod($source, 'getDefaultStaticPropSource');
      $private_method->setAccessible(TRUE);
      foreach (array_keys($settings[$component_id]['prop_field_definitions']) as $prop) {
        $static_prop_source = $private_method->invoke($source, $prop, TRUE);
        $this->assertInstanceOf(StaticPropSource::class, $static_prop_source);
      }
    }
  }

  /**
   * Tests that all SDCs use the SdcPlugin class.
   *
   * @param array<ComponentConfigEntityId> $component_ids
   *
   * @covers ::getReferencedPluginClass()
   * @depends testDiscovery
   */
  public function testGetReferencedPluginClass(array $component_ids): void {
    self::assertSame(
      // All SDCs use the same plugin class!
      array_fill_keys($component_ids, SdcPlugin::class),
      $this->getReferencedPluginClasses($component_ids)
    );
  }

  /**
   * Tests rendering an SDC component.
   *
   * @param array<ComponentConfigEntityId> $component_ids
   *
   * @covers ::renderComponent()
   * @depends testDiscovery
   */
  public function testRenderComponentLive(array $component_ids): void {
    $this->installEntitySchema('node');
    $this->installConfig('node');
    $this->createContentType(['type' => 'article']);
    $this->assertNotEmpty($component_ids);

    $rendered = $this->renderComponentsLive(
      $component_ids,
      get_default_input: [__CLASS__, 'getDefaultInputForGeneratedInputUx'],
    );

    $default_render_cache_contexts = [
      'languages:language_interface',
      'theme',
      'user.permissions',
    ];
    $default_cacheability = (new CacheableMetadata())
      ->setCacheContexts($default_render_cache_contexts);
    $this->assertEquals([
      'sdc.canvas_test_sdc.attributes' => [
        'html' => <<<HTML
<div data-component-id="canvas_test_sdc:attributes" class="sdc-standardized-attributes">
  <div class="additional-attributes-for-this-sdc">
    The not-attributes SDC prop!
  </div>
</div>

HTML,
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.canvas_test_sdc--attributes',
            'core/components.canvas_test_sdc--attributes',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.banner' => [
        'html' => <<<HTML
<article class="banner">
  <header>
    <h2>My banner title</h2>
  </header>
  <div class="container">
          <div class="image">
        <img
   class="banner--image"
   src="::CANVAS_MODULE_PATH::/tests/modules/canvas_test_sdc/components/banner/balloons.png"
           alt="Hot air balloons"
           width="640"
           height="427"
      loading="lazy"
    data-testid="banner-component-image" data-component-id="canvas:image"
/>
      </div>
        <div class="content">
      <p><p>In a curious work, published in <em>Paris</em> in 1863 by <strong>Delaville Dedreux</strong>, there is a suggestion for reaching the North Pole by an aerostat.</p></p>
    </div>
  </div>
</article>

HTML,
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.canvas_test_sdc--banner',
            'core/components.canvas--image',
            'core/components.canvas_test_sdc--banner',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.card' => [
        'html' => <<<HTML
<article class="card">
  <header>
    <h2>Card</h2>
      </header>

  <img
   class="card--image"
   src="::CANVAS_MODULE_PATH::/tests/modules/canvas_test_sdc/components/card/balloons.png"
           alt="Hot air balloons"
           width="640"
           height="427"
      loading="eager"
    data-testid="card-component-image" data-component-id="canvas:image"
/>

  <div class="content">
    <p>In a curious work, published in Paris in 1863 by Delaville Dedreux, there is a suggestion for reaching the North Pole by an aerostat.</p>
  </div>
  <footer>I have a footer!</footer>
</article>

HTML,
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.canvas_test_sdc--card',
            'core/components.canvas--image',
            'core/components.canvas_test_sdc--card',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.card-with-local-image' => [
        'html' => <<<HTML
<article class="card--with-local-image">
  <header>
    <h2>Card with local image</h2>
      </header>

  <img
   class="card--image"
   src="/core/misc/druplicon.png"
           alt="A classic druplicon"
           width="88"
           height="100"
      loading="lazy"
    data-testid="card-component-image" data-component-id="canvas:image"
/>

  <div class="content">
    <p>In a curious work, published in Paris in 1863 by Delaville Dedreux, there is a suggestion for reaching the North Pole by an aerostat.</p>
  </div>
  <footer>I have a footer!</footer>
</article>

HTML,
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.canvas_test_sdc--card-with-local-image',
            'core/components.canvas--image',
            'core/components.canvas_test_sdc--card',
            'core/components.canvas_test_sdc--card-with-local-image',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.card-with-remote-image' => [
        'html' => <<<HTML
<article class="card--with-remote-image">
  <header>
    <h2>Card with remote image</h2>
      </header>

  <img
   class="card--image"
   src="https://mdn.github.io/shared-assets/images/examples/balloons.jpg"
           alt="Hot air balloons"
           width="640"
           height="427"
      loading="lazy"
    data-testid="card-component-image" data-component-id="canvas:image"
/>

  <div class="content">
    <p>In a curious work, published in Paris in 1863 by Delaville Dedreux, there is a suggestion for reaching the North Pole by an aerostat.</p>
  </div>
  <footer>I have a footer!</footer>
</article>

HTML,
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.canvas_test_sdc--card-with-remote-image',
            'core/components.canvas--image',
            'core/components.canvas_test_sdc--card',
            'core/components.canvas_test_sdc--card-with-remote-image',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.card-with-stream-wrapper-image' => [
        'html' => <<<HTML
<article class="card--with-stream-wrapper-image">
  <header>
    <h2>Card with stream wrapper</h2>
  </header>

  <img
   class="card--image"
   src="::SITE_DIR_BASE_URL::/files/balloons.png"
        srcset="::SITE_DIR_BASE_URL::/files/styles/canvas_parametrized_width--16/public/balloons.png.avif?itok=Oa4IMo7_ 16w, ::SITE_DIR_BASE_URL::/files/styles/canvas_parametrized_width--32/public/balloons.png.avif?itok=Oa4IMo7_ 32w, ::SITE_DIR_BASE_URL::/files/styles/canvas_parametrized_width--48/public/balloons.png.avif?itok=Oa4IMo7_ 48w, ::SITE_DIR_BASE_URL::/files/styles/canvas_parametrized_width--64/public/balloons.png.avif?itok=Oa4IMo7_ 64w, ::SITE_DIR_BASE_URL::/files/styles/canvas_parametrized_width--96/public/balloons.png.avif?itok=Oa4IMo7_ 96w, ::SITE_DIR_BASE_URL::/files/styles/canvas_parametrized_width--128/public/balloons.png.avif?itok=Oa4IMo7_ 128w, ::SITE_DIR_BASE_URL::/files/styles/canvas_parametrized_width--256/public/balloons.png.avif?itok=Oa4IMo7_ 256w, ::SITE_DIR_BASE_URL::/files/styles/canvas_parametrized_width--384/public/balloons.png.avif?itok=Oa4IMo7_ 384w, ::SITE_DIR_BASE_URL::/files/styles/canvas_parametrized_width--640/public/balloons.png.avif?itok=Oa4IMo7_ 640w"
     sizes="auto 100vw"
           alt="Hot air balloons"
           width="640"
           height="427"
      loading="lazy"
    data-testid="card-component-image" data-component-id="canvas:image"
/>

  <div class="content">
    <p>In a curious work, published in Paris in 1863 by Delaville Dedreux, there is a suggestion for reaching the North Pole by an aerostat.</p>
  </div>
  <footer>I have a footer!</footer>
</article>

HTML,
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.canvas_test_sdc--card-with-stream-wrapper-image',
            'core/components.canvas--image',
            'core/components.canvas_test_sdc--card-with-stream-wrapper-image',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.deprecated' => [
        'html' => <<<HTML
<div  data-component-id="canvas_test_sdc:deprecated">
  <h1>Deprecated SDC component</h1>
  <div>A text field</div>
</div>

HTML,
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.canvas_test_sdc--deprecated',
            'core/components.canvas_test_sdc--deprecated',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.experimental' => [
        'html' => <<<HTML
<div  data-component-id="canvas_test_sdc:experimental">
  <h1>Experimental SDC component</h1>
  <div>A text field</div>
</div>

HTML,
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.canvas_test_sdc--experimental',
            'core/components.canvas_test_sdc--experimental',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.grid-container' => [
        'html' => '
<div  data-component-id="canvas_test_sdc:grid-container" class="grid-container direction-horizontal">

  </div>
',
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.canvas_test_sdc--grid-container',
            'core/components.canvas_test_sdc--grid-container',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.image-gallery' => [
        'html' => '<figure>
      <img src="::CANVAS_MODULE_PATH::/tests/modules/canvas_test_sdc/components/image-gallery/gracie.jpg" alt="A good dog" />
      <img src="::CANVAS_MODULE_PATH::/tests/modules/canvas_test_sdc/components/image-gallery/gracie.jpg" alt="Still a good dog" />
      <img src="::CANVAS_MODULE_PATH::/tests/modules/canvas_test_sdc/components/image-gallery/UPPERCASE-GRACIE.JPG" alt="THE BEST DOG!" />
    </figure>
',
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.canvas_test_sdc--image-gallery',
            'core/components.canvas_test_sdc--image-gallery',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.image-optional-with-example' => [
        'html' => '<img src="https://example.com/cat.jpg" alt="Boring placeholder" />',
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.canvas_test_sdc--image-optional-with-example',
            'core/components.canvas_test_sdc--image-optional-with-example',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.image-optional-with-example-and-additional-prop' => [
        'html' => '<img src="::CANVAS_MODULE_PATH::/tests/modules/canvas_test_sdc/components/image-optional-with-example-and-additional-prop/gracie.jpg" alt="A good dog" width="601" height="402"></img>',
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.canvas_test_sdc--image-optional-with-example-and-additional-prop',
            'core/components.canvas_test_sdc--image-optional-with-example-and-additional-prop',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.image-optional-without-example' => [
        'html' => '',
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.canvas_test_sdc--image-optional-without-example',
            'core/components.canvas_test_sdc--image-optional-without-example',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.image-required-with-example' => [
        'html' => '<img src="https://example.com/cat.jpg" alt="Boring placeholder" />',
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.canvas_test_sdc--image-required-with-example',
            'core/components.canvas_test_sdc--image-required-with-example',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.props-no-slots' => [
        'html' => <<<HTML
<div  data-component-id="canvas_test_sdc:props-no-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;">There goes my hero</h1>
</div>

HTML,
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.canvas_test_sdc--props-no-slots',
            'core/components.canvas_test_sdc--props-no-slots',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.props-slots' => [
        'html' => '<div  data-component-id="canvas_test_sdc:props-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;">There goes my hero</h1>
  <div class="component--props-slots--body">
          </div>
  <div class="component--props-slots--footer">
          </div>
  <div class="component--props-slots--colophon">
          </div>
</div>
',
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.canvas_test_sdc--props-slots',
            'core/components.canvas_test_sdc--props-slots',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.crash' => [
        'html' => '<h1>test</h1>

',
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.canvas_test_sdc--crash',
            'core/components.canvas_test_sdc--crash',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.date' => [
        'html' => '<figure class="date">
    <time datetime=""></time>
      <figcaption>Birthday</figcaption>
  </figure>
',
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.canvas_test_sdc--date',
            'core/components.canvas_test_sdc--date',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.sparkline' => [
        'html' => '

<div class="sparkline-container" style="width: 100px; height: 20px;">
  <svg width="100" height="20" viewBox="0 0 100 20" preserveAspectRatio="none">

            <polygon points="0,20 0,7.5 12.5,5 25,2.5 37.5,0 50,17.5 62.5,20 75,6.25 87.5,5.75 100,5.25 100,20" fill="rgba(26, 115, 232, 0.2)" />

            <polyline points="0,7.5 12.5,5 25,2.5 37.5,0 50,17.5 62.5,20 75,6.25 87.5,5.75 100,5.25" fill="none" stroke="#1a73e8" stroke-width="1" />
      </svg>
</div>
',
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.canvas_test_sdc--sparkline',
            'core/components.canvas_test_sdc--sparkline',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.tags' => [
        'html' => '  <div class="tag-list">
          <span class="tag">foo</span>
          <span class="tag">bar</span>
          <span class="tag">baz</span>
      </div>
',
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.canvas_test_sdc--tags',
            'core/components.canvas_test_sdc--tags',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.my-cta' => [
        'cacheability' => $default_cacheability,
        'html' => '<a  data-component-id="canvas_test_sdc:my-cta" href="https://www.drupal.org">
  Press
</a>
',
        'attachments' => [
          'library' => [
            'core/components.canvas_test_sdc--my-cta',
            'core/components.canvas_test_sdc--my-cta',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.columns' => [
        'cacheability' => $default_cacheability,
        'html' => '<div  data-component-id="canvas_test_sdc:columns"></div>
',
        'attachments' => [
          'library' => [
            'core/components.canvas_test_sdc--columns',
            'core/components.canvas_test_sdc--columns',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.component-mismatch-meta-enum' => [
        'cacheability' => $default_cacheability,
        'html' => 'small!
',
        'attachments' => [
          'library' => [
            'core/components.canvas_test_sdc--component-mismatch-meta-enum',
            'core/components.canvas_test_sdc--component-mismatch-meta-enum',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.component-no-meta-enum' => [
        'cacheability' => $default_cacheability,
        'html' => '<span  data-component-id="canvas_test_sdc:component-no-meta-enum">
It\'s me, and I\'m small!
</span>
',
        'attachments' => [
          'library' => [
            'core/components.canvas_test_sdc--component-no-meta-enum',
            'core/components.canvas_test_sdc--component-no-meta-enum',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.druplicon' => [
        'html' => <<<HTML
 <!-- sourced from https://www.drupal.org/about/media-kit/logos -->
 <svg enable-background="new 0 0 755 826" viewBox="0 0 755 826" xmlns="http://www.w3.org/2000/svg"><title>Druplicon</title><path d="m539.2 167.8c-39.8-24.8-77.2-34.5-114.8-59.2-23.2-15.8-55.5-53.2-82.5-85.5-5.2 51.8-21 72.8-39 87.8-38.2 30-62.2 39-95.2 57-27.8 14.2-178.5 104.2-178.5 297.7s162.8 336 343.5 336 337.5-131.2 337.5-330-147-288.8-171-303.8z" fill="#00598e"/><path d="m478.2 633.5c12 0 24.8.8 33.8 6.8s14.2 19.5 17.2 27 0 12-6 15c-5.2 3-6 1.5-11.2-8.2s-9.8-19.5-36-19.5-34.5 9-47.2 19.5-17.2 14.2-21.8 8.2-3-12 5.2-19.5 21.8-19.5 34.5-24.8 19.5-4.5 31.5-4.5z" fill="#fff"/><path d="m353.8 719c15 12 37.5 21.8 85.5 21.8s81.7-13.6 96.7-24.8c6.8-5.2 9.8-.8 10.5 2.2s2.2 7.5-3 12.8c-3.8 3.8-38.2 27.8-78.8 31.5s-95.2 6-128.2-24c-5.2-5.2-3.8-12.8 0-15.8s6.8-5.2 11.2-5.2 3.8 0 6.1 1.5z" fill="#fff"/><path d="m170 662c57-.8 67.5-10.5 117.8-33 271.5-121.5 321.8-232.5 331.5-258s24-66.8 9-112.5c-2.9-8.8-5-15.9-6.5-21.6-36.1-40.3-71.9-62.4-82.7-69.1-39-24.8-77.3-34.5-114.8-59.2-23.2-15-55.5-53.2-82.5-85.5-5.2 51.8-20.2 73.5-39 87.8-38.2 30-62.2 39-95.2 57-27.8 14.9-178.6 104.1-178.6 297.6 0 61.8 16.6 118.4 45.1 166.8l7.4-.3c15.7 14.2 40.5 30.8 88.5 30z" fill="#0073ba"/><path d="m539 167.8c-39-24.8-77.2-34.5-114.8-59.2-23.2-15-55.5-53.2-82.5-85.5-5.2 51.8-20.2 73.5-39 87.8-38.2 30-62.2 39-95.2 57-27.7 14.9-178.5 104.1-178.5 297.6 0 61.8 16.6 118.4 45.1 166.8 60.7 103.2 175.4 169.2 298.4 169.2 180.8 0 337.5-131.2 337.5-330 0-109.1-44.3-185.5-88.3-234.6-36.1-40.4-71.9-62.4-82.7-69.1zm91.2 87.7c49.2 61.6 74.2 134.2 74.2 216 0 47.4-9 92.2-26.8 133.2-16.9 38.8-41.2 73.2-72.3 102.3-61.5 57.4-144.1 89-232.7 89-43.8 0-86.8-8.4-127.8-24.9-40.3-16.2-76.5-39.4-107.8-69-66.1-62.4-102.4-146.4-102.4-236.6 0-80.3 26.1-151.7 77.5-212.2 39.3-46.2 81.7-71.8 98-80.6 8-4.3 15.4-8.2 22.6-11.9 22.6-11.6 44-22.6 73.4-45.6 15.7-11.9 32.4-30.8 39.5-78.7 24.8 29.5 53.5 62.6 75.5 76.8 19.5 12.9 39.5 21.9 58.8 30.6 18.3 8.2 37.2 16.8 55.9 28.7 0 0 .7.4.7.4 54.9 34.1 84.1 70.6 93.7 82.5z" fill="#004975"/><path d="m345.5 38c10.5 30.8 9 46.5 9 53.2s-3.8 24.8-15.8 33.8c-5.2 3.8-6.8 6.8-6.8 7.5 0 3 6.8 5.2 6.8 12 0 8.2-3.8 24.8-43.5 64.5s-96.8 75-141 96.8-65.2 20.2-71.2 9.7 2.2-33.8 30-64.5 115.5-75 115.5-75l109.5-76.5 6-29.2" fill="#93c5e4"/><path d="m345.5 37.2c-6.8 49.5-21.8 64.5-42 80.2-33.8 25.5-66.8 41.2-74.2 45-19.5 9.8-90 48.8-126.8 105-11.2 17.2 0 24 2.2 25.5s27.8 4.5 82.5-28.5 78.8-52.4 109.6-84.6c16.5-17.2 18.8-27 18.8-31.5 0-5.2-3.8-7.5-9.8-9-3-.8-3.8-2.2 0-4.5s19.4-9.8 23.2-12.8 21.8-15 22.5-34.5-.7-33-6-50.3z" fill="#fff"/><path d="m176.8 582.5c.8-58.5 55.5-113.2 124.5-114 87.8-.8 148.5 87 192.8 86.2 37.5-.8 109.5-74.2 144.8-74.2 37.5 0 48 39 48 62.2s-7.5 65.2-25.5 91.5-29.2 36-50.2 34.5c-27-2.2-81-86.2-115.5-87.8-43.5-1.5-138 90.8-212.2 90.8-45 0-58.5-6.8-73.5-16.5-22.8-15.7-34-39.7-33.2-72.7z" fill="#fff"/><path d="m628.2 258.5c15 45.8.8 87-9 112.5s-60 136.5-331.5 258c-50.2 22.5-60.8 32.2-117.8 33-48 .8-72.8-15.8-88.5-30l-7.4.3c60.7 103.2 175.4 169.2 298.4 169.2 180.8 0 337.5-131.2 337.5-330 0-109.1-44.3-185.5-88.3-234.6 1.6 5.7 3.8 12.8 6.6 21.6z" fill="none"/></svg>\n
 HTML,
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.canvas_test_sdc--druplicon',
            'core/components.canvas_test_sdc--druplicon',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.heading' => [
        'html' => <<<HTML
<h1  data-component-id="canvas_test_sdc:heading" class="primary">A heading element</h1>\n
HTML,
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.canvas_test_sdc--heading',
            'core/components.canvas_test_sdc--heading',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.image' => [
        'html' => '
<img
  class="image"
  src="::CANVAS_MODULE_PATH::/tests/modules/canvas_test_sdc/components/image/600x400.png"
    alt="Boring placeholder"
  width="600"
  height="400"
  loading="lazy"
/>
',
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.canvas_test_sdc--image',
            'core/components.canvas_test_sdc--image',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.my-hero' => [
        'html' => <<<HTML
<div  data-component-id="canvas_test_sdc:my-hero" class="my-hero__container">
  <h1 class="my-hero__heading">There goes my hero</h1>
  <p class="my-hero__subheading">Watch him as he goes!</p>
  <div class="my-hero__actions">
    <a href="https://example.com" class="my-hero__cta my-hero__cta--primary">View</a>
    <button class="my-hero__cta">Click</button>
  </div>
</div>

HTML,
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.canvas_test_sdc--my-hero',
            'core/components.canvas_test_sdc--my-hero',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.my-section' => [
        'html' => <<<HTML
<h2 class="my-section__h2">Our Mission</h2>
<div class="my-section__wrapper">
  <div class="my-section__content-wrapper">
    <p class="my-section__paragraph">
      Our mission is to deliver the best products and services to our customers. We strive to exceed expectations and continuously improve our offerings.
    </p>
    <p class="my-section__paragraph">
      Join us on our journey to innovation and excellence. Your satisfaction is our priority.
    </p>
  </div>
  <div class="my-section__image-wrapper">
    <img alt="Placeholder Image" class="my-section__img" width="500" height="500" src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAfQAAAH0CAYAAADL1t+KAAAAAXNSR0IArs4c6QAAIABJREFUeF7t3QnT9ETVBuBBXHAF3HBDFsFdBP3//8AN3ABRBAUUV1BxRb46T9VQeZuemczMCUfOd6WKKotn5nT66pZ7knSS277zne+8sbMRIECAAAEC72iB2wT6O3r87DwBAgQIELgREOgmAgECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4QIECAAIEGAgK9wSDqAgECBAgQEOjmAAECBAgQaCAg0BsMoi4QIECAAAGBbg4Q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">
  </div>
</div>

HTML,
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.canvas_test_sdc--my-section',
            'core/components.canvas_test_sdc--my-section',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.one_column' => [
        'html' => <<<HTML
<div  data-component-id="canvas_test_sdc:one_column" class="width-full">
      The contents of the example block
  </div>

HTML,
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.canvas_test_sdc--one_column',
            'core/components.canvas_test_sdc--one_column',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.two_column' => [
        'html' => <<<HTML
<div  data-component-id="canvas_test_sdc:two_column">
          <div class="column-one width-25">
        The contents of the one column.

      </div>
              <div class="column-two width-75">
        The contents of the two column.

      </div>
    </div>

HTML,
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.canvas_test_sdc--two_column',
            'core/components.canvas_test_sdc--two_column',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.shoe_badge' => [
        'html' => '<sl-badge
   data-component-id="canvas_test_sdc:shoe_badge" data-component-variant="primary"
  variant="primary"
   pulse    pill >
      The contents of the example block
  </sl-badge>
',
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.canvas_test_sdc--shoe_badge',
            'core/components.canvas_test_sdc--shoe_badge',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.shoe_tab' => [
        'html' => '<sl-tab  data-component-id="canvas_test_sdc:shoe_tab"
panel="tab_1">
  Tab 1
</sl-tab>
',
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.canvas_test_sdc--shoe_tab',
            'core/components.canvas_test_sdc--shoe_tab',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.shoe_tab_group' => [
        'html' => '<sl-tab-group  data-component-id="canvas_test_sdc:shoe_tab_group"
placement="top"
activation="auto">
  <div slot="nav">
          </div>


</sl-tab-group>
',
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.canvas_test_sdc--shoe_tab_group',
            'core/components.canvas_test_sdc--shoe_tab_group',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.shoe_tab_panel' => [
        'html' => '<sl-tab-panel  data-component-id="canvas_test_sdc:shoe_tab_panel"
  name="tab_name"  >  </sl-tab-panel>
',
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.canvas_test_sdc--shoe_tab_panel',
            'core/components.canvas_test_sdc--shoe_tab_panel',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.video' => [
        'html' => '<video controls poster="https://example.com/600x400.png" width="">
  <source src="https://media.istockphoto.com/id/1340051874/video/aerial-top-down-view-of-a-container-cargo-ship.mp4?s=mp4-640x640-is&amp;k=20&amp;c=5qPpYI7TOJiOYzKq9V2myBvUno6Fq2XM3ITPGFE8Cd8=">
</video>
',
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.canvas_test_sdc--video',
            'core/components.canvas_test_sdc--video',
          ],
        ],
      ],
      'sdc.sdc_theme_test.bar' => [
        'html' => <<<'HTML'
<h1>Bar</h1>

HTML,
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.sdc_theme_test--bar',
            'core/components.sdc_theme_test--bar',
          ],
        ],
      ],
      'sdc.sdc_theme_test.lib-overrides' => [
        'html' => 'It works
',
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.sdc_theme_test--lib-overrides',
            'core/components.sdc_theme_test--lib-overrides',
          ],
        ],
      ],
      'sdc.sdc_theme_test.my-card' => [
        'html' => <<<'HTML'
<div  data-component-id="sdc_theme_test:my-card">
  <h2 class="component--my-card__header">I am a header!</h2>
  <div class="component--my-card__body">
          Default contents for a card
      </div>
</div>

HTML
        ,
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.sdc_theme_test--my-card',
            'core/components.sdc_theme_test--my-card',
          ],
        ],
      ],
      'sdc.sdc_theme_test_base.my-card-no-schema' => [
        'html' => <<<'HTML'
<div  data-component-id="sdc_theme_test_base:my-card-no-schema">
  <h2 class="component--my-card-no-schema__header"></h2>
  <div class="component--my-card-no-schema__body">
          Default contents for a card
      </div>
</div>

HTML
        ,
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.sdc_theme_test_base--my-card-no-schema',
            'core/components.sdc_theme_test_base--my-card-no-schema',
          ],
        ],
      ],

      'sdc.canvas_test_sdc.image-without-ref' => [
        'html' => '<div class="inline-image-test">
  <img src="https://example.com/image.png"
       alt="Alternative text"
       width="800"
       height="600">
</div>
',
        'cacheability' => $default_cacheability,
        'attachments' => [
          'library' => [
            'core/components.canvas_test_sdc--image-without-ref',
            'core/components.canvas_test_sdc--image-without-ref',
          ],
        ],
      ],
    ], $rendered);
  }

  /**
   * Tests that relative file URLs are rewritten to reference the correct file path.
   */
  public function testRewriteExampleUrl(): void {
    $this->generateComponentConfig();
    $component = Component::load(SingleDirectoryComponentDiscovery::getComponentConfigEntityId('canvas_test_sdc:image'));
    self::assertNotNull($component);
    $source = $component->getComponentSource();
    self::assertInstanceOf(SingleDirectoryComponent::class, $source);
    // Assert that existing files are rewritten to include the module path.
    $canvas_test_sdc_module_path = \Drupal::service(ModuleExtensionList::class)->getPath('canvas_test_sdc');

    $assert_cacheability = function (GeneratedUrl $g, $cache_tags = []) {
      self::assertEqualsCanonicalizing($cache_tags, $g->getCacheTags());
      self::assertEqualsCanonicalizing([], $g->getCacheContexts());
      self::assertSame(Cache::PERMANENT, $g->getCacheMaxAge());
    };

    // Assert that relative URL to a file inside the SDC DOES get the
    // `component_plugins` cache tag.
    $cases = [
      '600x400.png' => $canvas_test_sdc_module_path . '/components/image/600x400.png',
      '/600x400.png' => $canvas_test_sdc_module_path . '/components/image/600x400.png',
    ];
    foreach ($cases as $case => $expectation) {
      $generated_url = $source->rewriteExampleUrl($case);
      self::assertStringEndsWith($expectation, $generated_url->getGeneratedUrl());
      $assert_cacheability($generated_url, cache_tags: ['component_plugins']);
    }

    // Assert that relative URL to a file outside the SDC does NOT get the
    // `component_plugins` cache tag.
    $generated_url = $source->rewriteExampleUrl('../../tests/fixtures/600x400.png');
    self::assertStringEndsWith('/tests/fixtures/600x400.png', $generated_url->getGeneratedUrl());
    $assert_cacheability($generated_url);

    // Assert that non-existing links have a leading slash but do not include the module nor SDC path.
    $generated_url = $source->rewriteExampleUrl('test/path');
    $url = $generated_url->getGeneratedUrl();
    self::assertStringEndsWith('/test/path', $url);
    self::assertStringNotContainsString($canvas_test_sdc_module_path, $url);
    self::assertStringNotContainsString('components', $url);
    $assert_cacheability($generated_url);

    // Assert that non-existing links with a leading slash are not doubled.
    $generated_url = $source->rewriteExampleUrl('/test/path');
    $url = $generated_url->getGeneratedUrl();
    self::assertStringEndsWith('/test/path', $url);
    self::assertStringNotContainsString('//', $url);
    $assert_cacheability($generated_url);

    // Assert that full URLs are left alone.
    $generated_url = $source->rewriteExampleUrl('https://www.example.com/');
    self::assertSame('https://www.example.com/', $generated_url->getGeneratedUrl());
    $assert_cacheability($generated_url);
  }

  /**
   * @covers ::getExplicitInput()
   * @dataProvider providerComponentResolving
   */
  public function testGetExplicitInput(array $component_item_value, array $expected_props_for_uuids, ?array $permissions = NULL): void {
    $this->generateComponentConfig();
    $this->installEntitySchema('node');
    $this->container->get('module_installer')->install(['canvas_test_config_node_article']);
    $node = Node::create([
      'title' => 'Test node',
      'type' => 'article',
      'field_canvas_test' => $component_item_value,
    ]);
    $canvas_field_item = $node->field_canvas_test[0];
    if ($permissions !== NULL) {
      // If we are setting permissions to check access, we need to save the node,
      // but we cannot use $permissions for the user saving the node because we
      // may be testing insufficient permissions. So we temporarily set a user with
      // 'access content' permission to save the node, then use the permissions
      // we are testing with.
      $this->setUpCurrentUser(permissions: ['access content']);
      $node->save();
      $this->setUpCurrentUser(permissions: $permissions);
    }

    $this->assertInstanceOf(ComponentTreeItem::class, $canvas_field_item);
    $actual_props = array_combine(
      array_keys($expected_props_for_uuids),
      array_map(
        fn (string $uuid) => $canvas_field_item->getComponent()?->getComponentSource()->getExplicitInput($uuid, $canvas_field_item)['resolved'],
        array_keys($expected_props_for_uuids)
      )
    );
    self::assertEquals($expected_props_for_uuids, $actual_props);
  }

  public static function providerComponentResolving(): array {
    $test_cases = static::getValidTreeTestCases();
    $invalid_test_cases = static::getInvalidTreeTestCases();
    // Only 1 invalid case will allow to call
    // \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem::resolveComponentProps()
    // without an exception.
    $test_cases['invalid UUID, missing component_id key'] = $invalid_test_cases['invalid UUID, missing component_id key'];
    $test_cases['invalid UUID, missing component_id key'][] = [];
    $test_cases['valid values using static inputs'][] = [
      'dynamic-static-card2df' => [
        'heading' => new EvaluationResult('They say I am static, but I want to believe I can change!'),
      ],
    ];
    $test_cases['valid values for propless component'][] = [
      'propless-component-uuid' => [],
    ];
    $test_cases['valid value for optional explicit input using an URL prop shape, with default value'][] = [
      'optional-url-with-default-value' => [
        'heading' => new EvaluationResult('Gracie says hi!'),
        'image' => new EvaluationResult(
          [
            'src' => self::getCiModulePath() . '/tests/modules/canvas_test_sdc/components/image-optional-with-example-and-additional-prop/gracie.jpg',
            'alt' => 'A good dog',
            'width' => 601,
            'height' => 402,
          ],
          (new CacheableMetadata())->setCacheTags(['component_plugins']),
        ),
      ],
    ];
    $hero_with_dynamic_sources = [
      'uuid' => 'partly-dynamic-hero',
      'component_id' => 'sdc.canvas_test_sdc.my-hero',
      'component_version' => '888412021fbcc837',
      'inputs' => [
        'heading' => 'hello, world!',
        'subheading' => [
          'sourceType' => 'dynamic',
          'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
        ],
        'cta1href' => ['uri' => 'https://drupal.org'],
        'cta1' => [
          'sourceType' => 'dynamic',
          'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
        ],
      ],
    ];
    $test_cases['values values, dynamic sources WITH access'] = [
      [
        $hero_with_dynamic_sources,
      ],
      [
        'partly-dynamic-hero' => [
          // Permanent cacheability because populated by StaticPropSource
          // without references.
          'heading' => new EvaluationResult('hello, world!'),
          // Node 1 and access-dependent cacheability because DynamicPropSource.
          'subheading' => new EvaluationResult(
            'Test node',
            (new CacheableMetadata())
              ->setCacheTags(['node:1'])
              ->setCacheContexts(['user.permissions'])
          ),
          // Permanent cacheability because populated by StaticPropSource
          // without references.
          'cta1href' => new EvaluationResult('https://drupal.org'),
          // Node 1 and access-dependent cacheability because DynamicPropSource.
          'cta1' => new EvaluationResult(
            'Test node',
            (new CacheableMetadata())
              ->setCacheTags(['node:1'])
              ->setCacheContexts(['user.permissions'])
          ),
        ],
      ],
      ['access content'],
    ];
    $test_cases['values values, dynamic sources WITHOUT access'] = [
      [
        $hero_with_dynamic_sources,
      ],
      [
        'partly-dynamic-hero' => [
          'heading' => new EvaluationResult('hello, world!'),
          // Node access-dependent cacheability because DynamicPropSource.
          'subheading' => new EvaluationResult(
            NULL,
            (new CacheableMetadata())->setCacheContexts(['user.permissions'])
          ),
          'cta1href' => new EvaluationResult('https://drupal.org'),
          // Node access-dependent cacheability because DynamicPropSource.
          'cta1' => new EvaluationResult(
            NULL,
            (new CacheableMetadata())->setCacheContexts(['user.permissions'])
          ),
        ],
      ],
      [],
    ];
    return $test_cases;
  }

  protected function generateCrashTestDummyComponentTree(string $component_id, array $inputs, bool $assertCount = TRUE): ComponentTreeItemList {
    if (str_starts_with($component_id, 'sdc.canvas_broken_sdcs.')) {
      // This component needs an extra module.
      $this->assertCount(0, $this->componentStorage->loadMultiple());
      \Drupal::service(ModuleInstallerInterface::class)->install(['canvas_broken_sdcs']);

      // Now call the parent, but don't assert the count of components, as we've
      // done it here, and installing a module will generate component config.
      return parent::generateCrashTestDummyComponentTree($component_id, $inputs, assertCount: FALSE);
    }
    return parent::generateCrashTestDummyComponentTree($component_id, $inputs);
  }

  protected function alterEnvironmentForCrashTestDummyComponentTree(string $component_id, array $inputs): void {
    // Register the private file stream.
    $this->setSetting('file_private_path', 'private');
    // Setup file entity.
    $this->installEntitySchema('file');
    $this->installSchema('file', 'file_usage');
    $user = $this->setUpCurrentUser(permissions: ['access content', 'view media']);
    // Create a private file.
    /** @var \Drupal\Core\File\FileSystemInterface $fileSystem */
    $fileSystem = \Drupal::service(FileSystemInterface::class);
    $directory = 'private://test';
    self::assertTrue($fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS));
    $fileSystem->copy(\Drupal::root() . '/core/tests/fixtures/files/image-1.png', 'private://test/image.png');
    $private_file = File::create([
      'uid' => $user->id(),
      'fid' => 3000,
      'status' => 0,
      'filename' => 'image.png',
      'uri' => 'private://test/image.png',
      'filesize' => \filesize('private://test/image.png'),
      'filemime' => 'image/png',
    ]);
    $private_file->enforceIsNew();
    $private_file->setPermanent();
    $private_file->save();

    // And a public file.
    $directory = 'public://test';
    self::assertTrue($fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS));
    $fileSystem->copy(\Drupal::root() . '/core/tests/fixtures/files/image-1.png', 'public://test/image.png');
    $public_file = File::create([
      'uid' => $user->id(),
      'fid' => 3001,
      'status' => 0,
      'filename' => 'image.png',
      'uri' => 'public://test/image.png',
      'filesize' => \filesize('public://test/image.png'),
      'filemime' => 'image/png',
    ]);
    $public_file->enforceIsNew();
    $public_file->setPermanent();
    $public_file->save();
  }

  public static function providerRenderComponentFailure(): \Generator {
    yield "SDC with valid props, without exception" => [
      'component_id' => 'sdc.canvas_test_sdc.crash',
      'inputs' => [
        'crash' => FALSE,
      ],
      'expected_validation_errors' => [],
      'expected_exception' => NULL,
      'expected_output_selector' => 'h1:contains("test")',
    ];

    yield "SDC with valid props, with exception" => [
      'component_id' => 'sdc.canvas_test_sdc.crash',
      'inputs' => [
        'crash' => TRUE,
      ],
      'expected_validation_errors' => [],
      'expected_exception' => [
        'class' => Error::class,
        'message' => 'Intentional test exception in "canvas_test_sdc:crash" at line 2.',
      ],
      'expected_output_selector' => NULL,
    ];

    yield "SDC with invalid prop type is cast by typed data, raises exception" => [
      'component_id' => 'sdc.canvas_test_sdc.crash',
      'inputs' => [
        'crash' => 'this is is not a boolean prop but gets cast to TRUE by \Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase::validateComponentInput',
      ],
      'expected_validation_errors' => [],
      'expected_exception' => [
        'class' => Error::class,
        'message' => 'Intentional test exception in "canvas_test_sdc:crash" at line 2.',
      ],
      'expected_output_selector' => NULL,
    ];

    // @see \Drupal\canvas\Validation\JsonSchema\UriSchemeAwareFormatConstraint
    yield "SDC with invalid value for `type: string, format: uri, x-allowed-schemes: ['public']`-shaped prop " => [
      'component_id' => 'sdc.canvas_test_sdc.card-with-stream-wrapper-image',
      'inputs' => [
        'alt' => 'Majestic creature',
        // Use a private file, which isn't allowed here.
        'src' => ['target_id' => 3000],
      ],
      'expected_validation_errors' => [
        \sprintf('2.inputs.%s.src', self::UUID_CRASH_TEST_DUMMY) => 'The "private" URI scheme is not allowed. The provided value is: "private://test/image.png".',
      ],
      'expected_exception' => [
        'class' => RuntimeError::class,
        'message' => 'An exception has been thrown during the rendering of a template ("[canvas_test_sdc:card-with-stream-wrapper-image/src] The "private" URI scheme is not allowed. The provided value is: "private://test/image.png".") in "canvas_test_sdc:card-with-stream-wrapper-image" at line 1.',
      ],
      'expected_output_selector' => NULL,
    ];

    yield "SDC with missing prop, with exception" => [
      'component_id' => 'sdc.canvas_test_sdc.crash',
      'inputs' => [],
      'expected_validation_errors' => [
        \sprintf('2.inputs.%s.crash', self::UUID_CRASH_TEST_DUMMY) => 'The property crash is required.',
      ],
      'expected_exception' => [
        'class' => RuntimeError::class,
        'message' => 'An exception has been thrown during the rendering of a template ("[canvas_test_sdc:crash/crash] The property crash is required.") in "canvas_test_sdc:crash" at line 1.',
      ],
      'expected_output_selector' => NULL,
    ];

    yield "SDC with invalid Twig (due to filter)" => [
      'component_id' => 'sdc.canvas_broken_sdcs.invalid-filter',
      'inputs' => [
        'name' => 'Gaia',
      ],
      'expected_validation_errors' => [],
      'expected_exception' => [
        'class' => SyntaxError::class,
        'message' => 'Unknown "invalidFilter" filter',
      ],
      'expected_output_selector' => NULL,
    ];

    yield "SDC with valid prop, but invalid Twig (due to printing an object-shaped prop)" => [
      'component_id' => 'sdc.canvas_broken_sdcs.malformed-image',
      'inputs' => [
        'image' => ['target_id' => 3001],
      ],
      // Note there's no validation error - the file with fid 3001 is a valid
      // public file.
      'expected_validation_errors' => [],
      'expected_exception' => [
        'class' => RuntimeError::class,
        'message' => 'An exception has been thrown during the rendering of a template (""src" is an invalid render array key. Value should be an array but got a string.") in "canvas_broken_sdcs:malformed-image" at line 2.',
      ],
      'expected_output_selector' => NULL,
    ];
  }

  public static function getExpectedSettings(): array {
    return [
      'sdc.canvas_test_sdc.attributes' => [
        'prop_field_definitions' => [
          'not_attributes' => [
            'required' => TRUE,
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => [0 => ['value' => 'The not-attributes SDC prop!']],
            'expression' => 'ℹ︎string␟value',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.banner' => [
        'prop_field_definitions' => [
          'heading' => [
            'required' => TRUE,
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => [
              0 => [
                'value' => 'My banner title',
              ],
            ],
            'expression' => 'ℹ︎string␟value',
          ],
          'text' => [
            'required' => FALSE,
            'field_type' => 'text_long',
            'field_storage_settings' => [],
            'field_instance_settings' => [
              'allowed_formats' => [
                'canvas_html_block',
              ],
            ],
            'field_widget' => 'text_textarea',
            'default_value' => [
              0 => [
                'value' => '<p>In a curious work, published in <em>Paris</em> in 1863 by <strong>Delaville Dedreux</strong>, there is a suggestion for reaching the North Pole by an aerostat.</p>',
                'format' => 'canvas_html_block',
              ],
            ],
            'expression' => 'ℹ︎text_long␟processed',
          ],
          'image' => [
            'required' => FALSE,
            'field_type' => 'image',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'image_image',
            'default_value' => [],
            'expression' => 'ℹ︎image␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.card' => [
        'prop_field_definitions' => [
          'heading' => [
            'required' => FALSE,
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => [
              0 => [
                'value' => 'Card',
              ],
            ],
            'expression' => 'ℹ︎string␟value',
          ],
          'content' => [
            'required' => FALSE,
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => [
              0 => [
                'value' => 'In a curious work, published in Paris in 1863 by Delaville Dedreux, there is a suggestion for reaching the North Pole by an aerostat.',
              ],
            ],
            'expression' => 'ℹ︎string␟value',
          ],
          'footer' => [
            'required' => FALSE,
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => [
              0 => [
                'value' => 'I have a footer!',
              ],
            ],
            'expression' => 'ℹ︎string␟value',
          ],
          'date' => [
            'required' => FALSE,
            'field_type' => 'datetime',
            'field_storage_settings' => [
              'datetime_type' => 'date',
            ],
            'field_instance_settings' => [],
            'field_widget' => 'datetime_default',
            'default_value' => NULL,
            'expression' => 'ℹ︎datetime␟value',
          ],
          'image' => [
            'required' => TRUE,
            'field_type' => 'image',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'image_image',
            'default_value' => [],
            'expression' => 'ℹ︎image␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
          ],
          'sizes' => [
            'required' => FALSE,
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => [
              0 => [
                'value' => 'auto 50vw',
              ],
            ],
            'expression' => 'ℹ︎string␟value',
          ],
          'loading' => [
            'required' => TRUE,
            'field_type' => 'list_string',
            'field_storage_settings' => [
              'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
            ],
            'field_instance_settings' => [],
            'field_widget' => 'options_select',
            'default_value' => [
              [
                'value' => 'eager',
              ],
            ],
            'expression' => 'ℹ︎list_string␟value',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.card-with-local-image' => [
        'prop_field_definitions' => [
          'heading' => [
            'required' => FALSE,
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => [
              0 => [
                'value' => 'Card with local image',
              ],
            ],
            'expression' => 'ℹ︎string␟value',
          ],
          'content' => [
            'required' => FALSE,
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => [
              0 => [
                'value' => 'In a curious work, published in Paris in 1863 by Delaville Dedreux, there is a suggestion for reaching the North Pole by an aerostat.',
              ],
            ],
            'expression' => 'ℹ︎string␟value',
          ],
          'footer' => [
            'required' => FALSE,
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => [
              0 => [
                'value' => 'I have a footer!',
              ],
            ],
            'expression' => 'ℹ︎string␟value',
          ],
          'src' => [
            'required' => TRUE,
            'field_type' => 'image',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'image_image',
            'default_value' => [],
            'expression' => 'ℹ︎image␟src_with_alternate_widths',
          ],
          'alt' => [
            'required' => TRUE,
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => [
              0 => [
                'value' => 'A classic druplicon',
              ],
            ],
            'expression' => 'ℹ︎string␟value',
          ],
          'loading' => [
            'required' => TRUE,
            'field_type' => 'list_string',
            'field_storage_settings' => [
              'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
            ],
            'field_instance_settings' => [],
            'field_widget' => 'options_select',
            'default_value' => [
              [
                'value' => 'lazy',
              ],
            ],
            'expression' => 'ℹ︎list_string␟value',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.card-with-remote-image' => [
        'prop_field_definitions' => [
          'heading' => [
            'required' => FALSE,
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => [
              0 => [
                'value' => 'Card with remote image',
              ],
            ],
            'expression' => 'ℹ︎string␟value',
          ],
          'content' => [
            'required' => FALSE,
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => [
              0 => [
                'value' => 'In a curious work, published in Paris in 1863 by Delaville Dedreux, there is a suggestion for reaching the North Pole by an aerostat.',
              ],
            ],
            'expression' => 'ℹ︎string␟value',
          ],
          'footer' => [
            'required' => FALSE,
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => [
              0 => [
                'value' => 'I have a footer!',
              ],
            ],
            'expression' => 'ℹ︎string␟value',
          ],
          'src' => [
            'required' => TRUE,
            'field_type' => 'image',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'image_image',
            'default_value' => [],
            'expression' => 'ℹ︎image␟src_with_alternate_widths',
          ],
          'alt' => [
            'required' => TRUE,
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => [
              0 => [
                'value' => 'Hot air balloons',
              ],
            ],
            'expression' => 'ℹ︎string␟value',
          ],
          'width' => [
            'required' => TRUE,
            'field_type' => 'integer',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'number',
            'default_value' => [
              0 => [
                'value' => 640,
              ],
            ],
            'expression' => 'ℹ︎integer␟value',
          ],
          'height' => [
            'required' => TRUE,
            'field_type' => 'integer',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'number',
            'default_value' => [
              0 => [
                'value' => 427,
              ],
            ],
            'expression' => 'ℹ︎integer␟value',
          ],
          'loading' => [
            'required' => FALSE,
            'field_type' => 'list_string',
            'field_storage_settings' => [
              'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
            ],
            'field_instance_settings' => [],
            'field_widget' => 'options_select',
            'default_value' => [
              [
                'value' => 'lazy',
              ],
            ],
            'expression' => 'ℹ︎list_string␟value',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.card-with-stream-wrapper-image' => [
        'prop_field_definitions' => [
          'heading' => [
            'required' => FALSE,
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => [
              0 => [
                'value' => 'Card with stream wrapper',
              ],
            ],
            'expression' => 'ℹ︎string␟value',
          ],
          'content' => [
            'required' => FALSE,
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => [
              0 => [
                'value' => 'In a curious work, published in Paris in 1863 by Delaville Dedreux, there is a suggestion for reaching the North Pole by an aerostat.',
              ],
            ],
            'expression' => 'ℹ︎string␟value',
          ],
          'footer' => [
            'required' => FALSE,
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => [
              0 => [
                'value' => 'I have a footer!',
              ],
            ],
            'expression' => 'ℹ︎string␟value',
          ],
          'src' => [
            'required' => TRUE,
            'field_type' => 'image',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'image_image',
            'default_value' => [],
            'expression' => 'ℹ︎image␟entity␜␜entity:file␝uri␞␟value',
          ],
          'alt' => [
            'required' => TRUE,
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => [
              0 => [
                'value' => 'Hot air balloons',
              ],
            ],
            'expression' => 'ℹ︎string␟value',
          ],
          'loading' => [
            'required' => FALSE,
            'field_type' => 'list_string',
            'field_storage_settings' => [
              'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
            ],
            'field_instance_settings' => [],
            'field_widget' => 'options_select',
            'default_value' => [
              [
                'value' => 'lazy',
              ],
            ],
            'expression' => 'ℹ︎list_string␟value',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.columns' => [
        'prop_field_definitions' => [
          'columns' => [
            'required' => TRUE,
            'field_type' => 'list_integer',
            'field_storage_settings' => [
              'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
            ],
            'field_instance_settings' => [],
            'field_widget' => 'options_select',
            'default_value' => [0 => ['value' => 2]],
            'expression' => 'ℹ︎list_integer␟value',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.component-mismatch-meta-enum' => [
        'prop_field_definitions' => [
          'style' => [
            'required' => FALSE,
            'field_type' => 'list_string',
            'field_storage_settings' => [
              'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
            ],
            'field_instance_settings' => [],
            'field_widget' => 'options_select',
            'default_value' => [0 => ['value' => 'small']],
            'expression' => 'ℹ︎list_string␟value',
          ],
          'numbers' => [
            'required' => FALSE,
            'field_type' => 'list_string',
            'field_storage_settings' => [
              'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
            ],
            'field_instance_settings' => [],
            'field_widget' => 'options_select',
            'default_value' => [0 => ['value' => '3.14']],
            'expression' => 'ℹ︎list_string␟value',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.component-no-meta-enum' => [
        'prop_field_definitions' => [
          'style' => [
            'required' => FALSE,
            'field_type' => 'list_string',
            'field_storage_settings' => [
              'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
            ],
            'field_instance_settings' => [],
            'field_widget' => 'options_select',
            'default_value' => [0 => ['value' => 'small']],
            'expression' => 'ℹ︎list_string␟value',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.crash' => [
        'prop_field_definitions' => [
          'crash' => [
            'required' => TRUE,
            'field_type' => 'boolean',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'boolean_checkbox',
            'default_value' => [0 => ['value' => FALSE]],
            'expression' => 'ℹ︎boolean␟value',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.date' => [
        'prop_field_definitions' => [
          'date' => [
            'required' => FALSE,
            'field_type' => 'datetime',
            'field_storage_settings' => [
              'datetime_type' => DateTimeItem::DATETIME_TYPE_DATE,
            ],
            'field_instance_settings' => [],
            'field_widget' => 'datetime_default',
            'default_value' => NULL,
            'expression' => 'ℹ︎datetime␟value',
          ],
          'caption' => [
            'required' => FALSE,
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => [0 => ['value' => 'Birthday']],
            'expression' => 'ℹ︎string␟value',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.deprecated' => [
        'prop_field_definitions' => [
          'text' => [
            'required' => FALSE,
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => [0 => ['value' => 'A text field']],
            'expression' => 'ℹ︎string␟value',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.druplicon' => [
        'prop_field_definitions' => [],
      ],
      'sdc.canvas_test_sdc.experimental' => [
        'prop_field_definitions' => [
          'text' => [
            'required' => FALSE,
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => [0 => ['value' => 'A text field']],
            'expression' => 'ℹ︎string␟value',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.grid-container' => [
        'prop_field_definitions' => [
          'direction' => [
            'required' => TRUE,
            'field_type' => 'list_string',
            'field_storage_settings' => [
              'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
            ],
            'field_instance_settings' => [],
            'field_widget' => 'options_select',
            'default_value' => [0 => ['value' => 'horizontal']],
            'expression' => 'ℹ︎list_string␟value',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.heading' => [
        'prop_field_definitions' => [
          'text' => [
            'required' => TRUE,
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => [
              0 => [
                'value' => 'A heading element',
              ],
            ],
            'expression' => 'ℹ︎string␟value',
          ],
          'style' => [
            'required' => FALSE,
            'field_type' => 'list_string',
            'field_storage_settings' => [
              'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
            ],
            'field_instance_settings' => [],
            'field_widget' => 'options_select',
            'default_value' => [
              0 => [
                'value' => 'primary',
              ],
            ],
            'expression' => 'ℹ︎list_string␟value',
          ],
          'element' => [
            'required' => TRUE,
            'field_type' => 'list_string',
            'field_storage_settings' => [
              'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
            ],
            'field_instance_settings' => [],
            'field_widget' => 'options_select',
            'default_value' => [
              0 => [
                'value' => 'h1',
              ],
            ],
            'expression' => 'ℹ︎list_string␟value',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.image' => [
        'prop_field_definitions' => [
          'image' => [
            'required' => TRUE,
            'field_type' => 'image',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'image_image',
            'default_value' => [],
            'expression' => 'ℹ︎image␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.image-gallery' => [
        'prop_field_definitions' => [
          'caption' => [
            'required' => FALSE,
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => NULL,
            'expression' => 'ℹ︎string␟value',
          ],
          'images' => [
            'required' => TRUE,
            'field_type' => 'image',
            'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'image_image',
            // ⚠️ Empty default value.
            // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase::exampleValueRequiresEntity()
            'default_value' => [],
            'expression' => 'ℹ︎image␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.image-optional-with-example' => [
        'prop_field_definitions' => [
          'image' => [
            'required' => FALSE,
            'field_type' => 'image',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'image_image',
            // ⚠️ Empty default value.
            // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase::exampleValueRequiresEntity()
            'default_value' => [],
            'expression' => 'ℹ︎image␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.image-optional-with-example-and-additional-prop' => [
        'prop_field_definitions' => [
          'heading' => [
            'required' => FALSE,
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => NULL,
            'expression' => 'ℹ︎string␟value',
          ],
          'image' => [
            'required' => FALSE,
            'field_type' => 'image',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'image_image',
            // ⚠️ Empty default value.
            // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase::exampleValueRequiresEntity()
            'default_value' => [],
            'expression' => 'ℹ︎image␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.image-optional-without-example' => [
        'prop_field_definitions' => [
          'image' => [
            'required' => FALSE,
            'field_type' => 'image',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'image_image',
            // ⚠️ Empty default value.
            // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase::exampleValueRequiresEntity()
            'default_value' => [],
            'expression' => 'ℹ︎image␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.image-required-with-example' => [
        'prop_field_definitions' => [
          'image' => [
            'required' => TRUE,
            'field_type' => 'image',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'image_image',
            // ⚠️ Empty default value.
            // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase::exampleValueRequiresEntity()
            'default_value' => [],
            'expression' => 'ℹ︎image␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.image-without-ref' => [
        'prop_field_definitions' => [
          'image' => [
            'required' => TRUE,
            'field_type' => 'image',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'image_image',
            'default_value' => [],
            'expression' => 'ℹ︎image␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.my-cta' => [
        'prop_field_definitions' => [
          'text' => [
            'required' => TRUE,
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => [
              0 => [
                'value' => 'Press',
              ],
            ],
            'expression' => 'ℹ︎string␟value',
          ],
          'href' => [
            'required' => TRUE,
            'field_type' => 'link',
            'field_storage_settings' => [],
            'field_instance_settings' => [
              'title' => 0,
              'link_type' => LinkItemInterface::LINK_EXTERNAL,
            ],
            'field_widget' => 'link_default',
            'default_value' => [
              0 => [
                'uri' => 'https://www.drupal.org',
                'options' => [],
              ],
            ],
            'expression' => 'ℹ︎link␟url',
          ],
          'target' => [
            'required' => FALSE,
            'field_type' => 'list_string',
            'field_storage_settings' => [
              'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
            ],
            'field_instance_settings' => [],
            'field_widget' => 'options_select',
            'default_value' => NULL,
            'expression' => 'ℹ︎list_string␟value',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.my-hero' => [
        'prop_field_definitions' => [
          'heading' => [
            'required' => TRUE,
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => [
              0 => [
                'value' => 'There goes my hero',
              ],
            ],
            'expression' => 'ℹ︎string␟value',
          ],
          'subheading' => [
            'required' => FALSE,
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => [
              0 => [
                'value' => 'Watch him as he goes!',
              ],
            ],
            'expression' => 'ℹ︎string␟value',
          ],
          'cta1' => [
            'required' => FALSE,
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => [
              0 => [
                'value' => 'View',
              ],
            ],
            'expression' => 'ℹ︎string␟value',
          ],
          'cta1href' => [
            'required' => TRUE,
            'field_type' => 'link',
            'field_storage_settings' => [],
            'field_instance_settings' => [
              'title' => 0,
              'link_type' => LinkItemInterface::LINK_GENERIC,
            ],
            'field_widget' => 'link_default',
            'default_value' => [
              0 => [
                'uri' => 'https://example.com',
                'options' => [],
              ],
            ],
            'expression' => 'ℹ︎link␟url',
          ],
          'cta2' => [
            'required' => FALSE,
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => [
              0 => [
                'value' => 'Click',
              ],
            ],
            'expression' => 'ℹ︎string␟value',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.my-section' => [
        'prop_field_definitions' => [
          'text' => [
            'required' => TRUE,
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => [
              0 => [
                'value' => 'Our mission is to deliver the best products and services to our customers. We strive to exceed expectations and continuously improve our offerings.',
              ],
            ],
            'expression' => 'ℹ︎string␟value',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.one_column' => [
        'prop_field_definitions' => [
          'width' => [
            'required' => TRUE,
            'field_type' => 'list_string',
            'field_storage_settings' => [
              'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
            ],
            'field_instance_settings' => [],
            'field_widget' => 'options_select',
            'default_value' => [
              0 => [
                'value' => 'full',
              ],
            ],
            'expression' => 'ℹ︎list_string␟value',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.props-no-slots' => [
        'prop_field_definitions' => [
          'heading' => [
            'required' => TRUE,
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => [0 => ['value' => 'There goes my hero']],
            'expression' => 'ℹ︎string␟value',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.props-slots' => [
        'prop_field_definitions' => [
          'heading' => [
            'required' => TRUE,
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => [0 => ['value' => 'There goes my hero']],
            'expression' => 'ℹ︎string␟value',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.shoe_badge' => [
        'prop_field_definitions' => [
          'variant' => [
            'required' => TRUE,
            'field_type' => 'list_string',
            'field_storage_settings' => [
              'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
            ],
            'field_instance_settings' => [],
            'field_widget' => 'options_select',
            'default_value' => [
              0 => [
                'value' => 'primary',
              ],
            ],
            'expression' => 'ℹ︎list_string␟value',
          ],
          'pill' => [
            'required' => FALSE,
            'field_type' => 'boolean',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'boolean_checkbox',
            'default_value' => [
              0 => [
                'value' => TRUE,
              ],
            ],
            'expression' => 'ℹ︎boolean␟value',
          ],
          'pulse' => [
            'required' => FALSE,
            'field_type' => 'boolean',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'boolean_checkbox',
            'default_value' => [
              0 => [
                'value' => TRUE,
              ],
            ],
            'expression' => 'ℹ︎boolean␟value',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.shoe_tab' => [
        'prop_field_definitions' => [
          'label' => [
            'required' => TRUE,
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => [
              0 => [
                'value' => 'Tab 1',
              ],
            ],
            'expression' => 'ℹ︎string␟value',
          ],
          'panel' => [
            'required' => TRUE,
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => [
              0 => [
                'value' => 'tab_1',
              ],
            ],
            'expression' => 'ℹ︎string␟value',
          ],
          'active' => [
            'required' => FALSE,
            'field_type' => 'boolean',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'boolean_checkbox',
            'default_value' => NULL,
            'expression' => 'ℹ︎boolean␟value',
          ],
          'closable' => [
            'required' => FALSE,
            'field_type' => 'boolean',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'boolean_checkbox',
            'default_value' => NULL,
            'expression' => 'ℹ︎boolean␟value',
          ],
          'disabled' => [
            'required' => FALSE,
            'field_type' => 'boolean',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'boolean_checkbox',
            'default_value' => NULL,
            'expression' => 'ℹ︎boolean␟value',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.shoe_tab_group' => [
        'prop_field_definitions' => [
          'placement' => [
            'required' => TRUE,
            'field_type' => 'list_string',
            'field_storage_settings' => [
              'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
            ],
            'field_instance_settings' => [],
            'field_widget' => 'options_select',
            'default_value' => [
              0 => [
                'value' => 'top',
              ],
            ],
            'expression' => 'ℹ︎list_string␟value',
          ],
          'activation' => [
            'required' => FALSE,
            'field_type' => 'list_string',
            'field_storage_settings' => [
              'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
            ],
            'field_instance_settings' => [],
            'field_widget' => 'options_select',
            'default_value' => [
              0 => [
                'value' => 'auto',
              ],
            ],
            'expression' => 'ℹ︎list_string␟value',
          ],
          'no_scroll' => [
            'required' => FALSE,
            'field_type' => 'boolean',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'boolean_checkbox',
            'default_value' => [
              0 => [
                'value' => FALSE,
              ],
            ],
            'expression' => 'ℹ︎boolean␟value',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.shoe_tab_panel' => [
        'prop_field_definitions' => [
          'name' => [
            'required' => TRUE,
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => [
              0 => [
                'value' => 'tab_name',
              ],
            ],
            'expression' => 'ℹ︎string␟value',
          ],
          'active' => [
            'required' => FALSE,
            'field_type' => 'boolean',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'boolean_checkbox',
            'default_value' => NULL,
            'expression' => 'ℹ︎boolean␟value',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.sparkline' => [
        'prop_field_definitions' => [
          'data' => [
            'required' => TRUE,
            'field_type' => 'integer',
            'cardinality' => 100,
            'field_storage_settings' => [],
            'field_instance_settings' => [
              'min' => -100,
              'max' => 100,
            ],
            'field_widget' => 'number',
            'default_value' => [
              0 => ['value' => 0],
              1 => ['value' => 10],
              2 => ['value' => 20],
              3 => ['value' => 30],
              4 => ['value' => -40],
              5 => ['value' => -50],
              6 => ['value' => 5],
              7 => ['value' => 7],
              8 => ['value' => 9],
            ],
            'expression' => 'ℹ︎integer␟value',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.tags' => [
        'prop_field_definitions' => [
          'tags' => [
            'required' => FALSE,
            'field_type' => 'string',
            'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => [
              0 => ['value' => 'foo'],
              1 => ['value' => 'bar'],
              2 => ['value' => 'baz'],
            ],
            'expression' => 'ℹ︎string␟value',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.two_column' => [
        'prop_field_definitions' => [
          'width' => [
            'required' => TRUE,
            'field_type' => 'list_integer',
            'field_storage_settings' => [
              'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
            ],
            'field_instance_settings' => [],
            'field_widget' => 'options_select',
            'default_value' => [
              0 => [
                'value' => 25,
              ],
            ],
            'expression' => 'ℹ︎list_integer␟value',
          ],
        ],
      ],
      'sdc.canvas_test_sdc.video' => [
        'prop_field_definitions' => [
          'video' => [
            'required' => TRUE,
            'field_type' => 'file',
            'field_storage_settings' => [],
            'field_instance_settings' => [
              'file_extensions' => 'mp4',
            ],
            'field_widget' => 'file_generic',
            'default_value' => [],
            'expression' => 'ℹ︎file␟{src↝entity␜␜entity:file␝uri␞␟url}',
          ],
          'display_width' => [
            'required' => FALSE,
            'field_type' => 'integer',
            'field_storage_settings' => [],
            'field_instance_settings' => [
              'min' => 1,
              'max' => NULL,
            ],
            'field_widget' => 'number',
            'default_value' => NULL,
            'expression' => 'ℹ︎integer␟value',
          ],
        ],
      ],
      'sdc.sdc_theme_test.bar' => [
        'prop_field_definitions' => [],
      ],
      'sdc.sdc_theme_test.lib-overrides' => [
        'prop_field_definitions' => [],
      ],
      'sdc.sdc_theme_test.my-card' => [
        'prop_field_definitions' => [
          'header' => [
            'required' => TRUE,
            'field_type' => 'string',
            'field_storage_settings' => [],
            'field_instance_settings' => [],
            'field_widget' => 'string_textfield',
            'default_value' => [
              0 => [
                'value' => 'I am a header!',
              ],
            ],
            'expression' => 'ℹ︎string␟value',
          ],
        ],
      ],
      'sdc.sdc_theme_test_base.my-card-no-schema' => [
        'prop_field_definitions' => [],
      ],
    ];
  }

  /**
   * @covers ::calculateDependencies()
   * @depends testDiscovery
   */
  public function testCalculateDependencies(array $component_ids): void {
    self::assertSame([
      'sdc.canvas_test_sdc.attributes' => [
        'module' => [
          'core',
          'canvas_test_sdc',
        ],
      ],
      'sdc.canvas_test_sdc.banner' => [
        'config' => [
          'filter.format.canvas_html_block',
          'image.style.canvas_parametrized_width',
        ],
        'module' => [
          'core',
          'file',
          'image',
          'text',
          'canvas_test_sdc',
        ],
      ],
      'sdc.canvas_test_sdc.card' => [
        'config' => [
          'image.style.canvas_parametrized_width',
        ],
        'module' => [
          'core',
          'datetime',
          'file',
          'image',
          'options',
          'canvas_test_sdc',
        ],
      ],
      'sdc.canvas_test_sdc.card-with-local-image' => [
        'config' => [
          0 => 'image.style.canvas_parametrized_width',
        ],
        'module' => [
          'core',
          'file',
          'image',
          'options',
          'canvas_test_sdc',
        ],
      ],
      'sdc.canvas_test_sdc.card-with-remote-image' => [
        'config' => [
          0 => 'image.style.canvas_parametrized_width',
        ],
        'module' => [
          'core',
          'file',
          'image',
          'options',
          'canvas_test_sdc',
        ],
      ],
      'sdc.canvas_test_sdc.card-with-stream-wrapper-image' => [
        'config' => [
          0 => 'image.style.canvas_parametrized_width',
        ],
        'content' => [],
        'module' => [
          'core',
          'file',
          'image',
          'options',
          'canvas_test_sdc',
        ],
      ],
      'sdc.canvas_test_sdc.columns' => [
        'module' => [
          'core',
          'options',
          'canvas_test_sdc',
        ],
      ],
      'sdc.canvas_test_sdc.component-mismatch-meta-enum' => [
        'module' => [
          'core',
          'options',
          'canvas_test_sdc',
        ],
      ],
      'sdc.canvas_test_sdc.component-no-meta-enum' => [
        'module' => [
          'core',
          'options',
          'canvas_test_sdc',
        ],
      ],
      'sdc.canvas_test_sdc.crash' => [
        'module' => [
          'core',
          'canvas_test_sdc',
        ],
      ],
      'sdc.canvas_test_sdc.date' => [
        'module' => [
          'core',
          'datetime',
          'canvas_test_sdc',
        ],
      ],
      'sdc.canvas_test_sdc.deprecated' => [
        'module' => [
          'core',
          'canvas_test_sdc',
        ],
      ],
      'sdc.canvas_test_sdc.druplicon' => [
        'module' => [
          'canvas_test_sdc',
        ],
      ],
      'sdc.canvas_test_sdc.experimental' => [
        'module' => [
          'core',
          'canvas_test_sdc',
        ],
      ],
      'sdc.canvas_test_sdc.grid-container' => [
        'module' => [
          'core',
          'options',
          'canvas_test_sdc',
        ],
      ],
      'sdc.canvas_test_sdc.heading' => [
        'module' => [
          'core',
          'options',
          'canvas_test_sdc',
        ],
      ],
      'sdc.canvas_test_sdc.image' => [
        'config' => [
          0 => 'image.style.canvas_parametrized_width',
        ],
        'module' => [
          'file',
          'image',
          'canvas_test_sdc',
        ],
      ],
      'sdc.canvas_test_sdc.image-gallery' => [
        'config' => [
          'image.style.canvas_parametrized_width',
        ],
        'module' => [
          'core',
          'file',
          'image',
          'canvas_test_sdc',
        ],
      ],
      'sdc.canvas_test_sdc.image-optional-with-example' => [
        'config' => [
          'image.style.canvas_parametrized_width',
        ],
        'module' => [
          'file',
          'image',
          'canvas_test_sdc',
        ],
      ],
      'sdc.canvas_test_sdc.image-optional-with-example-and-additional-prop' => [
        'config' => [
          'image.style.canvas_parametrized_width',
        ],
        'module' => [
          'core',
          'file',
          'image',
          'canvas_test_sdc',
        ],
      ],
      'sdc.canvas_test_sdc.image-optional-without-example' => [
        'config' => [
          'image.style.canvas_parametrized_width',
        ],
        'module' => [
          'file',
          'image',
          'canvas_test_sdc',
        ],
      ],
      'sdc.canvas_test_sdc.image-required-with-example' => [
        'config' => [
          'image.style.canvas_parametrized_width',
        ],
        'module' => [
          'file',
          'image',
          'canvas_test_sdc',
        ],
      ],
      'sdc.canvas_test_sdc.image-without-ref' => [
        'config' => [
          'image.style.canvas_parametrized_width',
        ],
        'module' => [
          'file',
          'image',
          'canvas_test_sdc',
        ],
      ],
      'sdc.canvas_test_sdc.my-cta' => [
        'module' => [
          'core',
          'link',
          'options',
          'canvas_test_sdc',
        ],
      ],
      'sdc.canvas_test_sdc.my-hero' => [
        'module' => [
          'core',
          'link',
          'canvas_test_sdc',
        ],
      ],
      'sdc.canvas_test_sdc.my-section' => [
        'module' => [
          'core',
          'canvas_test_sdc',
        ],
      ],
      'sdc.canvas_test_sdc.one_column' => [
        'module' => [
          'core',
          'options',
          'canvas_test_sdc',
        ],
      ],
      'sdc.canvas_test_sdc.props-no-slots' => [
        'module' => [
          'core',
          'canvas_test_sdc',
        ],
      ],
      'sdc.canvas_test_sdc.props-slots' => [
        'module' => [
          'core',
          'canvas_test_sdc',
        ],
      ],
      'sdc.canvas_test_sdc.shoe_badge' => [
        'module' => [
          'core',
          'options',
          'canvas_test_sdc',
        ],
      ],
      'sdc.canvas_test_sdc.shoe_tab' => [
        'module' => [
          'core',
          'canvas_test_sdc',
        ],
      ],
      'sdc.canvas_test_sdc.shoe_tab_group' => [
        'module' => [
          'core',
          'options',
          'canvas_test_sdc',
        ],
      ],
      'sdc.canvas_test_sdc.shoe_tab_panel' => [
        'module' => [
          'core',
          'canvas_test_sdc',
        ],
      ],
      'sdc.canvas_test_sdc.sparkline' => [
        'module' => [
          'core',
          'canvas_test_sdc',
        ],
      ],
      'sdc.canvas_test_sdc.tags' => [
        'module' => [
          'core',
          'canvas_test_sdc',
        ],
      ],
      'sdc.canvas_test_sdc.two_column' => [
        'module' => [
          'core',
          'options',
          'canvas_test_sdc',
        ],
      ],
      'sdc.canvas_test_sdc.video' => [
        'content' => [],
        'module' => [
          0 => 'core',
          1 => 'file',
          2 => 'canvas_test_sdc',
        ],
      ],
      'sdc.sdc_theme_test.bar' => [
        'theme' => ['sdc_theme_test'],
      ],
      'sdc.sdc_theme_test.lib-overrides' => [
        'theme' => ['sdc_theme_test'],
      ],
      'sdc.sdc_theme_test.my-card' => [
        'module' => ['core'],
        'theme' => ['sdc_theme_test'],
      ],
      'sdc.sdc_theme_test_base.my-card-no-schema' => [
        'theme' => ['sdc_theme_test_base'],
      ],
    ], $this->callSourceMethodForEach('calculateDependencies', $component_ids));
  }

  /**
   * {@inheritdoc}
   */
  public static function getExpectedClientSideInfo(): array {
    return [
      'sdc.canvas_test_sdc.attributes' => [
        'expected_output_selectors' => [
          'div>div:contains("The not-attributes SDC prop!")',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'not_attributes' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => ['value' => 'The not-attributes SDC prop!'],
              ],
              'resolved' => 'The not-attributes SDC prop!',
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.banner' => [
        'expected_output_selectors' => [
          'article.banner',
          'article.banner img.banner--image',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'heading' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'My banner title',
                ],
              ],
              'resolved' => 'My banner title',
            ],
          ],
          'text' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
              'contentMediaType' => 'text/html',
              'x-formatting-context' => 'block',
            ],
            'sourceType' => 'static:field_item:text_long',
            'expression' => 'ℹ︎text_long␟processed',
            'sourceTypeSettings' => [
              'instance' => [
                'allowed_formats' => [
                  'canvas_html_block',
                ],
              ],
            ],
            'default_values' => [
              'source' => [
                0 => [
                  'value' => '<p>In a curious work, published in <em>Paris</em> in 1863 by <strong>Delaville Dedreux</strong>, there is a suggestion for reaching the North Pole by an aerostat.</p>',
                  'format' => 'canvas_html_block',
                ],
              ],
              'resolved' => '<p>In a curious work, published in <em>Paris</em> in 1863 by <strong>Delaville Dedreux</strong>, there is a suggestion for reaching the North Pole by an aerostat.</p>',
            ],
          ],
          'image' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'object',
              'title' => 'image',
              'required' => [
                0 => 'src',
              ],
              'properties' => [
                'src' => [
                  'title' => 'Image URL',
                  'type' => 'string',
                  'format' => 'uri-reference',
                  'contentMediaType' => 'image/*',
                  'x-allowed-schemes' => ['http', 'https'],
                  'id' => 'json-schema-definitions://canvas.module/image-uri',
                ],
                'alt' => [
                  'title' => 'Alternative text',
                  'type' => 'string',
                ],
                'width' => [
                  'title' => 'Image width',
                  'type' => 'integer',
                ],
                'height' => [
                  'title' => 'Image height',
                  'type' => 'integer',
                ],
              ],
              'id' => 'json-schema-definitions://canvas.module/image',
            ],
            'sourceType' => 'static:field_item:image',
            'expression' => 'ℹ︎image␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
            'default_values' => [
              'source' => [],
              'resolved' => [
                'src' => '/' . \Drupal::service(ExtensionPathResolver::class)->getPath('module', 'canvas_test_sdc') . '/components/banner/balloons.png',
                'alt' => 'Hot air balloons',
                'width' => 640,
                'height' => 427,
              ],
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.card' => [
        'expected_output_selectors' => [
          'article.card',
          'article.card img.card--image',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'heading' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'Card',
                ],
              ],
              'resolved' => 'Card',
            ],
          ],
          'content' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'In a curious work, published in Paris in 1863 by Delaville Dedreux, there is a suggestion for reaching the North Pole by an aerostat.',
                ],
              ],
              'resolved' => 'In a curious work, published in Paris in 1863 by Delaville Dedreux, there is a suggestion for reaching the North Pole by an aerostat.',
            ],
          ],
          'footer' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'I have a footer!',
                ],
              ],
              'resolved' => 'I have a footer!',
            ],
          ],
          'date' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
              'format' => 'date',
            ],
            'sourceType' => 'static:field_item:datetime',
            'expression' => 'ℹ︎datetime␟value',
            'sourceTypeSettings' => [
              'storage' => [
                'datetime_type' => 'date',
              ],
            ],
          ],
          'image' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'object',
              'title' => 'image',
              'required' => [
                0 => 'src',
              ],
              'properties' => [
                'src' => [
                  'title' => 'Image URL',
                  'type' => 'string',
                  'format' => 'uri-reference',
                  'contentMediaType' => 'image/*',
                  'x-allowed-schemes' => ['http', 'https'],
                  'id' => 'json-schema-definitions://canvas.module/image-uri',
                ],
                'alt' => [
                  'title' => 'Alternative text',
                  'type' => 'string',
                ],
                'width' => [
                  'title' => 'Image width',
                  'type' => 'integer',
                ],
                'height' => [
                  'title' => 'Image height',
                  'type' => 'integer',
                ],
              ],
              'id' => 'json-schema-definitions://canvas.module/image',
            ],
            'sourceType' => 'static:field_item:image',
            'expression' => 'ℹ︎image␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
            'default_values' => [
              'source' => [],
              'resolved' => [
                'src' => '/' . \Drupal::service(ExtensionPathResolver::class)->getPath('module', 'canvas_test_sdc') . '/components/card/balloons.png',
                'alt' => 'Hot air balloons',
                'width' => 640,
                'height' => 427,
              ],
            ],
          ],
          'sizes' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'auto 50vw',
                ],
              ],
              'resolved' => 'auto 50vw',
            ],
          ],
          'loading' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
              'enum' => [
                0 => 'lazy',
                1 => 'eager',
              ],
            ],
            'sourceType' => 'static:field_item:list_string',
            'expression' => 'ℹ︎list_string␟value',
            'sourceTypeSettings' => [
              'storage' => [
                'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
              ],
            ],
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'eager',
                ],
              ],
              'resolved' => 'eager',
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.card-with-local-image' => [
        'expected_output_selectors' => [
          'article.card--with-local-image',
          'article.card--with-local-image img.card--image',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'heading' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'Card with local image',
                ],
              ],
              'resolved' => 'Card with local image',
            ],
          ],
          'content' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'In a curious work, published in Paris in 1863 by Delaville Dedreux, there is a suggestion for reaching the North Pole by an aerostat.',
                ],
              ],
              'resolved' => 'In a curious work, published in Paris in 1863 by Delaville Dedreux, there is a suggestion for reaching the North Pole by an aerostat.',
            ],
          ],
          'footer' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'I have a footer!',
                ],
              ],
              'resolved' => 'I have a footer!',
            ],
          ],
          'src' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
              'title' => 'Image URL',
              'format' => 'uri-reference',
              'contentMediaType' => 'image/*',
              'x-allowed-schemes' => ['http', 'https'],
              'id' => 'json-schema-definitions://canvas.module/image-uri',
            ],
            'sourceType' => 'static:field_item:image',
            'expression' => 'ℹ︎image␟src_with_alternate_widths',
            'default_values' => [
              'source' => [],
              'resolved' => '/core/misc/druplicon.png',
            ],
          ],
          'alt' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                [
                  'value' => 'A classic druplicon',
                ],
              ],
              'resolved' => 'A classic druplicon',
            ],
          ],
          'loading' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
              'enum' => [
                0 => 'lazy',
                1 => 'eager',
              ],
            ],
            'sourceType' => 'static:field_item:list_string',
            'expression' => 'ℹ︎list_string␟value',
            'sourceTypeSettings' => [
              'storage' => [
                'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
              ],
            ],
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'lazy',
                ],
              ],
              'resolved' => 'lazy',
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.card-with-remote-image' => [
        'expected_output_selectors' => [
          'article.card--with-remote-image',
          'article.card--with-remote-image img.card--image',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'heading' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'Card with remote image',
                ],
              ],
              'resolved' => 'Card with remote image',
            ],
          ],
          'content' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'In a curious work, published in Paris in 1863 by Delaville Dedreux, there is a suggestion for reaching the North Pole by an aerostat.',
                ],
              ],
              'resolved' => 'In a curious work, published in Paris in 1863 by Delaville Dedreux, there is a suggestion for reaching the North Pole by an aerostat.',
            ],
          ],
          'footer' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'I have a footer!',
                ],
              ],
              'resolved' => 'I have a footer!',
            ],
          ],
          'src' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
              'title' => 'Image URL',
              'format' => 'uri-reference',
              'contentMediaType' => 'image/*',
              'x-allowed-schemes' => ['http', 'https'],
              'id' => 'json-schema-definitions://canvas.module/image-uri',
            ],
            'sourceType' => 'static:field_item:image',
            'expression' => 'ℹ︎image␟src_with_alternate_widths',
            'default_values' => [
              'source' => [],
              'resolved' => 'https://mdn.github.io/shared-assets/images/examples/balloons.jpg',
            ],
          ],
          'alt' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                [
                  'value' => 'Hot air balloons',
                ],
              ],
              'resolved' => 'Hot air balloons',
            ],
          ],
          'width' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'integer',
            ],
            'sourceType' => 'static:field_item:integer',
            'expression' => 'ℹ︎integer␟value',
            'default_values' => [
              'source' => [
                [
                  'value' => 640,
                ],
              ],
              'resolved' => 640,
            ],
          ],
          'height' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'integer',
            ],
            'sourceType' => 'static:field_item:integer',
            'expression' => 'ℹ︎integer␟value',
            'default_values' => [
              'source' => [
                [
                  'value' => 427,
                ],
              ],
              'resolved' => 427,
            ],
          ],
          'loading' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
              'enum' => [
                0 => 'lazy',
                1 => 'eager',
              ],
            ],
            'sourceType' => 'static:field_item:list_string',
            'expression' => 'ℹ︎list_string␟value',
            'sourceTypeSettings' => [
              'storage' => [
                'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
              ],
            ],
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'lazy',
                ],
              ],
              'resolved' => 'lazy',
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.card-with-stream-wrapper-image' => [
        'expected_output_selectors' => [
          'article.card--with-stream-wrapper-image',
          'article.card--with-stream-wrapper-image img.card--image',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'heading' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'Card with stream wrapper',
                ],
              ],
              'resolved' => 'Card with stream wrapper',
            ],
          ],
          'content' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'In a curious work, published in Paris in 1863 by Delaville Dedreux, there is a suggestion for reaching the North Pole by an aerostat.',
                ],
              ],
              'resolved' => 'In a curious work, published in Paris in 1863 by Delaville Dedreux, there is a suggestion for reaching the North Pole by an aerostat.',
            ],
          ],
          'footer' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'I have a footer!',
                ],
              ],
              'resolved' => 'I have a footer!',
            ],
          ],
          'src' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
              'title' => 'Stream wrapper image URI',
              'format' => 'uri',
              'contentMediaType' => 'image/*',
              'x-allowed-schemes' => ['public'],
              'id' => 'json-schema-definitions://canvas.module/stream-wrapper-image-uri',
            ],
            'sourceType' => 'static:field_item:image',
            'expression' => 'ℹ︎image␟entity␜␜entity:file␝uri␞␟value',
            'default_values' => [
              'source' => [],
              'resolved' => 'public://balloons.png',
            ],
          ],
          'alt' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                [
                  'value' => 'Hot air balloons',
                ],
              ],
              'resolved' => 'Hot air balloons',
            ],
          ],
          'loading' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
              'enum' => [
                0 => 'lazy',
                1 => 'eager',
              ],
            ],
            'sourceType' => 'static:field_item:list_string',
            'expression' => 'ℹ︎list_string␟value',
            'sourceTypeSettings' => [
              'storage' => [
                'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
              ],
            ],
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'lazy',
                ],
              ],
              'resolved' => 'lazy',
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.columns' => [
        'expected_output_selectors' => [
          // TRICKY: there's no content to assert, because the preview by
          // default doe snot use slot values.
          'div[data-component-id="canvas_test_sdc:columns"]',
        ],
        'source' => 'Module component',
        'metadata' => [
          'slots' => [
            'column_1' => [
              'title' => 'Column One',
              'description' => 'The contents of the first column.',
              'examples' => ['<p>This is column 1 content</p>'],
            ],
            'column_2' => [
              'title' => 'Column Two',
              'description' => 'The contents of the second column.',
              'examples' => ['<p>This is column 2 content</p>'],
            ],
            'column_3' => [
              'title' => 'Column Three',
              'description' => 'The contents of the third column.',
              'examples' => ['<p>This is column 3 content</p>'],
            ],
            'column_4' => [
              'title' => 'Column Four',
              'description' => 'The contents of the fourth column.',
              'examples' => ['<p>This is column 4 content</p>'],
            ],
            'column_5' => [
              'title' => 'Column Five',
              'description' => 'The contents of the fifth column.',
              'examples' => ['<p>This is column 5 content</p>'],
            ],
            'column_6' => [
              'title' => 'Column Six',
              'description' => 'The contents of the sixth column.',
              'examples' => ['<p>This is column 6 content</p>'],
            ],
          ],
        ],
        'propSources' => [
          'columns' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'integer',
              'enum' => [1, 2, 3, 4, 5, 6],
            ],
            'sourceType' => 'static:field_item:list_integer',
            'expression' => 'ℹ︎list_integer␟value',
            'sourceTypeSettings' => [
              'storage' => [
                'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
              ],
            ],
            'default_values' => [
              'source' => [
                0 => ['value' => 2],
              ],
              'resolved' => 2,
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.component-mismatch-meta-enum' => [
        'expected_output_selectors' => [
          ':contains("small")',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'style' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
              'enum' => [
                'small',
                'big',
                'huge',
                // @see \Drupal\Tests\canvas\Kernel\Config\ComponentValidationTest::testUnmatchedEnumAndMetaEnum()
                'contains.dots',
              ],
            ],
            'sourceType' => 'static:field_item:list_string',
            'expression' => 'ℹ︎list_string␟value',
            'sourceTypeSettings' => [
              'storage' => [
                'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
              ],
            ],
            'default_values' => [
              'source' => [
                0 => ['value' => 'small'],
              ],
              'resolved' => 'small',
            ],
          ],
          'numbers' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
              'enum' => [
                '7',
                '3.14',
              ],
            ],
            'sourceType' => 'static:field_item:list_string',
            'expression' => 'ℹ︎list_string␟value',
            'sourceTypeSettings' => [
              'storage' => [
                'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
              ],
            ],
            'default_values' => [
              'source' => [
                0 => ['value' => '3.14'],
              ],
              'resolved' => '3.14',
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.component-no-meta-enum' => [
        'expected_output_selectors' => [
          'span:contains("me")',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'style' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
              'enum' => [
                'small',
                'big',
                'huge',
              ],
            ],
            'sourceType' => 'static:field_item:list_string',
            'expression' => 'ℹ︎list_string␟value',
            'sourceTypeSettings' => [
              'storage' => [
                'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
              ],
            ],
            'default_values' => [
              'source' => [
                0 => ['value' => 'small'],
              ],
              'resolved' => 'small',
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.crash' => [
        'expected_output_selectors' => [
          'h1:contains("test")',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'crash' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'boolean',
            ],
            'sourceType' => 'static:field_item:boolean',
            'expression' => 'ℹ︎boolean␟value',
            'default_values' => [
              'source' => [
                0 => ['value' => FALSE],
              ],
              'resolved' => FALSE,
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.date' => [
        'expected_output_selectors' => [
          'figure.date',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'date' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
              'format' => 'date',
            ],
            'sourceType' => 'static:field_item:datetime',
            'expression' => 'ℹ︎datetime␟value',
            'sourceTypeSettings' => [
              'storage' => [
                'datetime_type' => DateTimeItem::DATETIME_TYPE_DATE,
              ],
            ],
          ],
          'caption' => [
            'required' => FALSE,
            'jsonSchema' => ['type' => 'string'],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => ['value' => 'Birthday'],
              ],
              'resolved' => 'Birthday',
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.deprecated' => [
        'expected_output_selectors' => [
          'h1:contains("Deprecated SDC component")',
          'div[data-component-id="canvas_test_sdc:deprecated"]',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'text' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => ['value' => 'A text field'],
              ],
              'resolved' => 'A text field',
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.druplicon' => [
        'expected_output_selectors' => [
          'svg',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.experimental' => [
        'expected_output_selectors' => [
          'h1:contains("Experimental SDC component")',
          'div[data-component-id="canvas_test_sdc:experimental"]',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'text' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => ['value' => 'A text field'],
              ],
              'resolved' => 'A text field',
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.grid-container' => [
        'expected_output_selectors' => [
          'div[data-component-id="canvas_test_sdc:grid-container"]',
        ],
        'source' => 'Module component',
        'metadata' => [
          'slots' => [
            'content' => [
              'title' => 'Content',
              'description' => 'The contents of the grid container.',
              'examples' => [
                '<div class="empty-slot">Empty grid slot</div>',
              ],
            ],
          ],
        ],
        'propSources' => [
          'direction' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
              'enum' => [
                0 => 'horizontal',
                1 => 'vertical',
              ],
            ],
            'sourceType' => 'static:field_item:list_string',
            'expression' => 'ℹ︎list_string␟value',
            'sourceTypeSettings' => [
              'storage' => [
                'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
              ],
            ],
            'default_values' => [
              'source' => [
                0 => ['value' => 'horizontal'],
              ],
              'resolved' => 'horizontal',
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.heading' => [
        'expected_output_selectors' => [
          'h1:contains("A heading element")',
        ],
        'source' => 'Module component',
        'metadata' => [
          'slots' => [],
        ],
        'propSources' => [
          'text' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'A heading element',
                ],
              ],
              'resolved' => 'A heading element',
            ],
          ],
          'style' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
              'enum' => [
                0 => 'primary',
                1 => 'secondary',
              ],
            ],
            'sourceType' => 'static:field_item:list_string',
            'expression' => 'ℹ︎list_string␟value',
            'sourceTypeSettings' => [
              'storage' => [
                'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
              ],
            ],
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'primary',
                ],
              ],
              'resolved' => 'primary',
            ],
          ],
          'element' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
              'title' => 'Heading element',
              'enum' => [
                0 => 'div',
                1 => 'h1',
                2 => 'h2',
                3 => 'h3',
                4 => 'h4',
                5 => 'h5',
                6 => 'h6',
              ],
              'id' => 'json-schema-definitions://canvas.module/heading-element',
            ],
            'sourceType' => 'static:field_item:list_string',
            'expression' => 'ℹ︎list_string␟value',
            'sourceTypeSettings' => [
              'storage' => [
                'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
              ],
            ],
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'h1',
                ],
              ],
              'resolved' => 'h1',
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.image' => [
        'expected_output_selectors' => [
          'img',
        ],
        'source' => 'Module component',
        'metadata' => [
          'slots' => [],
        ],
        'propSources' => [
          'image' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'object',
              'title' => 'image',
              'required' => [
                0 => 'src',
              ],
              'properties' => [
                'src' => [
                  'title' => 'Image URL',
                  'type' => 'string',
                  'format' => 'uri-reference',
                  'contentMediaType' => 'image/*',
                  'x-allowed-schemes' => ['http', 'https'],
                  'id' => 'json-schema-definitions://canvas.module/image-uri',
                ],
                'alt' => [
                  'title' => 'Alternative text',
                  'type' => 'string',
                ],
                'width' => [
                  'title' => 'Image width',
                  'type' => 'integer',
                ],
                'height' => [
                  'title' => 'Image height',
                  'type' => 'integer',
                ],
              ],
              'id' => 'json-schema-definitions://canvas.module/image',
            ],
            'sourceType' => 'static:field_item:image',
            'expression' => 'ℹ︎image␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
            'default_values' => [
              'source' => [],
              'resolved' => [
                'src' => self::getCiModulePath() . '/tests/modules/canvas_test_sdc/components/image/600x400.png',
                'alt' => 'Boring placeholder',
                'width' => 600,
                'height' => 400,
              ],
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.image-gallery' => [
        'expected_output_selectors' => [
          'figure > img[src="' . self::getCiModulePath() . '/tests/modules/canvas_test_sdc/components/image-gallery/gracie.jpg"][alt="A good dog"]',
          'figure > img[src="' . self::getCiModulePath() . '/tests/modules/canvas_test_sdc/components/image-gallery/gracie.jpg"][alt="Still a good dog"]',
          'figure > img[src="' . self::getCiModulePath() . '/tests/modules/canvas_test_sdc/components/image-gallery/UPPERCASE-GRACIE.JPG"][alt="THE BEST DOG!"]',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'caption' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
          ],
          'images' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'array',
              'items' => [
                'type' => 'object',
                'title' => 'image',
                'required' => [
                  'src',
                ],
                'properties' => [
                  'src' => [
                    'title' => 'Image URL',
                    'type' => 'string',
                    'format' => 'uri-reference',
                    'contentMediaType' => 'image/*',
                    'x-allowed-schemes' => ['http', 'https'],
                    'id' => 'json-schema-definitions://canvas.module/image-uri',
                  ],
                  'alt' => [
                    'title' => 'Alternative text',
                    'type' => 'string',
                  ],
                  'width' => [
                    'title' => 'Image width',
                    'type' => 'integer',
                  ],
                  'height' => [
                    'title' => 'Image height',
                    'type' => 'integer',
                  ],
                ],
                'id' => 'json-schema-definitions://canvas.module/image',
              ],
            ],
            'sourceType' => 'static:field_item:image',
            'expression' => 'ℹ︎image␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
            'sourceTypeSettings' => [
              'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
            ],
            'default_values' => [
              'source' => [],
              'resolved' => [
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
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.image-optional-with-example' => [
        'expected_output_selectors' => [
          'img[src="https://example.com/cat.jpg"]',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'image' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'object',
              'title' => 'image',
              'required' => [
                'src',
              ],
              'properties' => [
                'src' => [
                  'title' => 'Image URL',
                  'type' => 'string',
                  'format' => 'uri-reference',
                  'contentMediaType' => 'image/*',
                  'x-allowed-schemes' => ['http', 'https'],
                  'id' => 'json-schema-definitions://canvas.module/image-uri',
                ],
                'alt' => [
                  'title' => 'Alternative text',
                  'type' => 'string',
                ],
                'width' => [
                  'title' => 'Image width',
                  'type' => 'integer',
                ],
                'height' => [
                  'title' => 'Image height',
                  'type' => 'integer',
                ],
              ],
              'id' => 'json-schema-definitions://canvas.module/image',
            ],
            'sourceType' => 'static:field_item:image',
            'expression' => 'ℹ︎image␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
            'default_values' => [
              'source' => [],
              'resolved' => [
                'src' => 'https://example.com/cat.jpg',
                'alt' => 'Boring placeholder',
                'width' => 600,
                'height' => 400,
              ],
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.image-optional-with-example-and-additional-prop' => [
        'expected_output_selectors' => [
          'img[src="' . self::getCiModulePath() . '/tests/modules/canvas_test_sdc/components/image-optional-with-example-and-additional-prop/gracie.jpg"]',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'heading' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
          ],
          'image' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'object',
              'title' => 'image',
              'required' => [
                'src',
              ],
              'properties' => [
                'src' => [
                  'title' => 'Image URL',
                  'type' => 'string',
                  'format' => 'uri-reference',
                  'contentMediaType' => 'image/*',
                  'x-allowed-schemes' => ['http', 'https'],
                  'id' => 'json-schema-definitions://canvas.module/image-uri',
                ],
                'alt' => [
                  'title' => 'Alternative text',
                  'type' => 'string',
                ],
                'width' => [
                  'title' => 'Image width',
                  'type' => 'integer',
                ],
                'height' => [
                  'title' => 'Image height',
                  'type' => 'integer',
                ],
              ],
              'id' => 'json-schema-definitions://canvas.module/image',
            ],
            'sourceType' => 'static:field_item:image',
            'expression' => 'ℹ︎image␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
            'default_values' => [
              'source' => [],
              'resolved' => [
                'src' => self::getCiModulePath() . '/tests/modules/canvas_test_sdc/components/image-optional-with-example-and-additional-prop/gracie.jpg',
                'alt' => 'A good dog',
                'width' => 601,
                'height' => 402,
              ],
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.image-optional-without-example' => [
        'expected_output_selectors' => [],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'image' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'object',
              'title' => 'image',
              'required' => [
                'src',
              ],
              'properties' => [
                'src' => [
                  'title' => 'Image URL',
                  'type' => 'string',
                  'format' => 'uri-reference',
                  'contentMediaType' => 'image/*',
                  'x-allowed-schemes' => ['http', 'https'],
                  'id' => 'json-schema-definitions://canvas.module/image-uri',
                ],
                'alt' => [
                  'title' => 'Alternative text',
                  'type' => 'string',
                ],
                'width' => [
                  'title' => 'Image width',
                  'type' => 'integer',
                ],
                'height' => [
                  'title' => 'Image height',
                  'type' => 'integer',
                ],
              ],
              'id' => 'json-schema-definitions://canvas.module/image',
            ],
            'sourceType' => 'static:field_item:image',
            'expression' => 'ℹ︎image␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.image-required-with-example' => [
        'expected_output_selectors' => [
          'img[src="https://example.com/cat.jpg"]',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'image' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'object',
              'title' => 'image',
              'required' => [
                'src',
              ],
              'properties' => [
                'src' => [
                  'title' => 'Image URL',
                  'type' => 'string',
                  'format' => 'uri-reference',
                  'contentMediaType' => 'image/*',
                  'x-allowed-schemes' => ['http', 'https'],
                  'id' => 'json-schema-definitions://canvas.module/image-uri',
                ],
                'alt' => [
                  'title' => 'Alternative text',
                  'type' => 'string',
                ],
                'width' => [
                  'title' => 'Image width',
                  'type' => 'integer',
                ],
                'height' => [
                  'title' => 'Image height',
                  'type' => 'integer',
                ],
              ],
              'id' => 'json-schema-definitions://canvas.module/image',
            ],
            'sourceType' => 'static:field_item:image',
            'expression' => 'ℹ︎image␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
            'default_values' => [
              'source' => [],
              'resolved' => [
                'src' => 'https://example.com/cat.jpg',
                'alt' => 'Boring placeholder',
                'width' => 600,
                'height' => 400,
              ],
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.image-without-ref' => [
        'expected_output_selectors' => [
          'div.inline-image-test',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'image' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'object',
              'title' => 'image',
              'required' => [
                'src',
              ],
              'properties' => [
                'src' => [
                  'title' => 'Image URL',
                  'type' => 'string',
                  'format' => 'uri-reference',
                  'contentMediaType' => 'image/*',
                  'x-allowed-schemes' => ['http', 'https'],
                  'id' => 'json-schema-definitions://canvas.module/image-uri',
                ],
                'alt' => [
                  'title' => 'Alternative text',
                  'type' => 'string',
                ],
                'width' => [
                  'title' => 'Image width',
                  'type' => 'integer',
                ],
                'height' => [
                  'title' => 'Image height',
                  'type' => 'integer',
                ],
              ],
              'id' => 'json-schema-definitions://canvas.module/image',
            ],
            'sourceType' => 'static:field_item:image',
            'expression' => 'ℹ︎image␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
            'default_values' => [
              'source' => [],
              'resolved' => [
                'src' => 'https://example.com/image.png',
                'alt' => 'Alternative text',
                'width' => 800,
                'height' => 600,
              ],
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.my-cta' => [
        'expected_output_selectors' => [
          'a:contains("Press")',
          'a[data-component-id="canvas_test_sdc:my-cta"]',
        ],
        'source' => 'Module component',
        'metadata' => [
          'slots' => [],
        ],
        'propSources' => [
          'text' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'Press',
                ],
              ],
              'resolved' => 'Press',
            ],
          ],
          'href' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
              'format' => 'uri',
            ],
            'sourceType' => 'static:field_item:link',
            'expression' => 'ℹ︎link␟url',
            'sourceTypeSettings' => [
              'instance' => [
                'title' => 0,
                'link_type' => LinkItemInterface::LINK_EXTERNAL,
              ],
            ],
            'default_values' => [
              'source' => [
                0 => [
                  'uri' => 'https://www.drupal.org',
                  'options' => [],
                ],
              ],
              'resolved' => 'https://www.drupal.org',
            ],
          ],
          'target' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
              'enum' => [
                0 => '_self',
                1 => '_blank',
              ],
            ],
            'sourceType' => 'static:field_item:list_string',
            'expression' => 'ℹ︎list_string␟value',
            'sourceTypeSettings' => [
              'storage' => [
                'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
              ],
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.my-hero' => [
        'expected_output_selectors' => [
          'h1:contains("There goes my hero")',
          'p:contains("Watch him as he goes!")',
          'div[data-component-id="canvas_test_sdc:my-hero"]',
        ],
        'source' => 'Module component',
        'metadata' => [
          'slots' => [],
        ],
        'propSources' => [
          'heading' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'There goes my hero',
                ],
              ],
              'resolved' => 'There goes my hero',
            ],
          ],
          'subheading' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'Watch him as he goes!',
                ],
              ],
              'resolved' => 'Watch him as he goes!',
            ],
          ],
          'cta1' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'View',
                ],
              ],
              'resolved' => 'View',
            ],
          ],
          'cta1href' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
              'format' => 'uri-reference',
            ],
            'sourceType' => 'static:field_item:link',
            'expression' => 'ℹ︎link␟url',
            'sourceTypeSettings' => [
              'instance' => [
                'title' => 0,
                'link_type' => LinkItemInterface::LINK_GENERIC,
              ],
            ],
            'default_values' => [
              'source' => [
                0 => [
                  'uri' => 'https://example.com',
                  'options' => [],
                ],
              ],
              'resolved' => 'https://example.com',
            ],
          ],
          'cta2' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'Click',
                ],
              ],
              'resolved' => 'Click',
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.my-section' => [
        'expected_output_selectors' => [
          'h2:contains("Our Mission")',
        ],
        'source' => 'Module component',
        'metadata' => [
          'slots' => [],
        ],
        'propSources' => [
          'text' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
              'minLength' => 2,
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'Our mission is to deliver the best products and services to our customers. We strive to exceed expectations and continuously improve our offerings.',
                ],
              ],
              'resolved' => 'Our mission is to deliver the best products and services to our customers. We strive to exceed expectations and continuously improve our offerings.',
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.one_column' => [
        'expected_output_selectors' => [
          'div[data-component-id="canvas_test_sdc:one_column"]',
        ],
        'source' => 'Module component',
        'metadata' => [
          'slots' => [
            'content' => [
              'title' => 'Content',
              'description' => 'The contents of the column.',
            ],
          ],
        ],
        'propSources' => [
          'width' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
              'enum' => [
                0 => 'full',
                1 => 'wide',
                2 => 'normal',
                3 => 'narrow',
              ],
            ],
            'sourceType' => 'static:field_item:list_string',
            'expression' => 'ℹ︎list_string␟value',
            'sourceTypeSettings' => [
              'storage' => [
                'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
              ],
            ],
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'full',
                ],
              ],
              'resolved' => 'full',
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.props-no-slots' => [
        'expected_output_selectors' => [
          'h1:contains("There goes my hero")',
          'div[data-component-id="canvas_test_sdc:props-no-slots"]',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'heading' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
              'minLength' => 2,
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => ['value' => 'There goes my hero'],
              ],
              'resolved' => 'There goes my hero',
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.props-slots' => [
        'expected_output_selectors' => [
          'div[data-component-id="canvas_test_sdc:props-slots"]',
          'h1:contains("There goes my hero")',
          'div.component--props-slots--body',
          'div.component--props-slots--footer',
          'div.component--props-slots--colophon',
        ],
        'source' => 'Module component',
        'metadata' => [
          'slots' => [
            'the_body' => [
              'title' => 'The Body',
              'description' => 'The contents of the body.',
              'examples' => [
                '<p>Example value for <strong>the_body</strong> slot in <strong>prop-slots</strong> component.</p>',
              ],
            ],
            'the_footer' => [
              'title' => 'The Footer',
              'description' => 'The contents of the footer.',
              'examples' => [
                'Example value for <strong>the_footer</strong>.',
              ],
            ],
            'the_colophon' => [
              'title' => 'The Colophon',
              'description' => 'The contents of the colophon.',
              'examples' => [],
            ],
          ],
        ],
        'propSources' => [
          'heading' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
              'minLength' => 2,
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => ['value' => 'There goes my hero'],
              ],
              'resolved' => 'There goes my hero',
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.shoe_badge' => [
        'expected_output_selectors' => [
          'sl-badge[data-component-variant="primary"]',
        ],
        'source' => 'Module component',
        'metadata' => [
          'slots' => [
            'content' => [
              'title' => 'Content',
              'description' => 'The contents of the badge.',
              'examples' => [
                0 => 'Badge',
              ],
            ],
          ],
        ],
        'propSources' => [
          'variant' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
              'enum' => [
                0 => 'primary',
                1 => 'success',
                2 => 'neutral',
                3 => 'warning',
                4 => 'danger',
              ],
            ],
            'sourceType' => 'static:field_item:list_string',
            'expression' => 'ℹ︎list_string␟value',
            'sourceTypeSettings' => [
              'storage' => [
                'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
              ],
            ],
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'primary',
                ],
              ],
              'resolved' => 'primary',
            ],
          ],
          'pill' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'boolean',
            ],
            'sourceType' => 'static:field_item:boolean',
            'expression' => 'ℹ︎boolean␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => TRUE,
                ],
              ],
              'resolved' => TRUE,
            ],
          ],
          'pulse' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'boolean',
            ],
            'sourceType' => 'static:field_item:boolean',
            'expression' => 'ℹ︎boolean␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => TRUE,
                ],
              ],
              'resolved' => TRUE,
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.shoe_tab' => [
        'expected_output_selectors' => [
          'sl-tab[data-component-id="canvas_test_sdc:shoe_tab"]',
        ],
        'source' => 'Module component',
        'metadata' => [
          'slots' => [],
        ],
        'propSources' => [
          'label' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'Tab 1',
                ],
              ],
              'resolved' => 'Tab 1',
            ],
          ],
          'panel' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'tab_1',
                ],
              ],
              'resolved' => 'tab_1',
            ],
          ],
          'active' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'boolean',
            ],
            'sourceType' => 'static:field_item:boolean',
            'expression' => 'ℹ︎boolean␟value',
          ],
          'closable' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'boolean',
            ],
            'sourceType' => 'static:field_item:boolean',
            'expression' => 'ℹ︎boolean␟value',
          ],
          'disabled' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'boolean',
            ],
            'sourceType' => 'static:field_item:boolean',
            'expression' => 'ℹ︎boolean␟value',
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.shoe_tab_group' => [
        'expected_output_selectors' => [
          'sl-tab-group[data-component-id="canvas_test_sdc:shoe_tab_group"]',
        ],
        'source' => 'Module component',
        'metadata' => [
          'slots' => [
            'tabs' => [
              'title' => 'Tab Nav',
              'description' => 'The tabs.',
            ],
            'tab_panels' => [
              'title' => 'Tab Panels',
              'description' => 'The tab panels.',
            ],
          ],
        ],
        'propSources' => [
          'placement' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
              'enum' => [
                0 => 'top',
                1 => 'bottom',
                2 => 'start',
                3 => 'end',
              ],
            ],
            'sourceType' => 'static:field_item:list_string',
            'expression' => 'ℹ︎list_string␟value',
            'sourceTypeSettings' => [
              'storage' => [
                'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
              ],
            ],
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'top',
                ],
              ],
              'resolved' => 'top',
            ],
          ],
          'activation' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
              'enum' => [
                0 => 'auto',
                1 => 'manual',
              ],
            ],
            'sourceType' => 'static:field_item:list_string',
            'expression' => 'ℹ︎list_string␟value',
            'sourceTypeSettings' => [
              'storage' => [
                'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
              ],
            ],
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'auto',
                ],
              ],
              'resolved' => 'auto',
            ],
          ],
          'no_scroll' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'boolean',
            ],
            'sourceType' => 'static:field_item:boolean',
            'expression' => 'ℹ︎boolean␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => FALSE,
                ],
              ],
              'resolved' => FALSE,
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.shoe_tab_panel' => [
        'expected_output_selectors' => [
          'sl-tab-panel[data-component-id="canvas_test_sdc:shoe_tab_panel"]',
        ],
        'source' => 'Module component',
        'metadata' => [
          'slots' => [
            'content' => [
              'title' => 'Tab Content',
              'description' => 'The contents of the tab.',
              'examples' => [
                0 => '<p>This is tab content</p>',
              ],
            ],
          ],
        ],
        'propSources' => [
          'name' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'tab_name',
                ],
              ],
              'resolved' => 'tab_name',
            ],
          ],
          'active' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'boolean',
            ],
            'sourceType' => 'static:field_item:boolean',
            'expression' => 'ℹ︎boolean␟value',
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.sparkline' => [
        'expected_output_selectors' => [
          'div.sparkline-container > svg > polygon[points="0,20 0,7.5 12.5,5 25,2.5 37.5,0 50,17.5 62.5,20 75,6.25 87.5,5.75 100,5.25 100,20"]',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'data' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'array',
              'items' => [
                'type' => 'integer',
                'minimum' => -100,
                'maximum' => 100,
              ],
              'maxItems' => 100,
            ],
            'sourceType' => 'static:field_item:integer',
            'expression' => 'ℹ︎integer␟value',
            'sourceTypeSettings' => [
              'instance' => ['min' => -100, 'max' => 100],
              'cardinality' => 100,
            ],
            'default_values' => [
              'source' => [
                0 => ['value' => 0],
                1 => ['value' => 10],
                2 => ['value' => 20],
                3 => ['value' => 30],
                4 => ['value' => -40],
                5 => ['value' => -50],
                6 => ['value' => 5],
                7 => ['value' => 7],
                8 => ['value' => 9],
              ],
              'resolved' => [
                0, 10, 20, 30, -40, -50, 5, 7, 9,
              ],
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.tags' => [
        'expected_output_selectors' => [
          'div.tag-list > span.tag:nth-child(3)',
        ],
        'source' => 'Module component',
        'metadata' => [
          'slots' => [],
        ],
        'propSources' => [
          'tags' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'array',
              'items' => [
                'type' => 'string',
              ],
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'sourceTypeSettings' => [
              'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
            ],
            'default_values' => [
              'source' => [
                0 => ['value' => 'foo'],
                1 => ['value' => 'bar'],
                2 => ['value' => 'baz'],
              ],
              'resolved' => ['foo', 'bar', 'baz'],
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.two_column' => [
        'expected_output_selectors' => [
          'div[data-component-id="canvas_test_sdc:two_column"]',
        ],
        'source' => 'Module component',
        'metadata' => [
          'slots' => [
            'column_one' => [
              'title' => 'Column One',
              'description' => 'The contents of the first column.',
              'examples' => [
                0 => '<p>This is column 1 content</p>',
              ],
            ],
            'column_two' => [
              'title' => 'Column Two',
              'description' => 'The contents of the second column.',
              'examples' => [
                0 => '<p>This is column 2 content</p>',
              ],
            ],
          ],
        ],
        'propSources' => [
          'width' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'integer',
              'title' => 'Column Width',
              'enum' => [
                0 => 25,
                1 => 33,
                2 => 50,
                3 => 66,
                4 => 75,
              ],
              'id' => 'json-schema-definitions://canvas.module/column-width',
            ],
            'sourceType' => 'static:field_item:list_integer',
            'expression' => 'ℹ︎list_integer␟value',
            'sourceTypeSettings' => [
              'storage' => [
                'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
              ],
            ],
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 25,
                ],
              ],
              'resolved' => 25,
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.video' => [
        'expected_output_selectors' => [
          'video[poster="https://example.com/600x400.png"]',
          'video > source[src="https://media.istockphoto.com/id/1340051874/video/aerial-top-down-view-of-a-container-cargo-ship.mp4?s=mp4-640x640-is&k=20&c=5qPpYI7TOJiOYzKq9V2myBvUno6Fq2XM3ITPGFE8Cd8="]',
        ],
        'source' => 'Module component',
        'metadata' => [
          'slots' => [],
        ],
        'propSources' => [
          'video' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'object',
              'title' => 'video',
              'required' => [
                0 => 'src',
              ],
              'properties' => [
                'src' => [
                  'title' => 'Video URL',
                  'type' => 'string',
                  'format' => 'uri-reference',
                  'contentMediaType' => 'video/*',
                  'x-allowed-schemes' => ['http', 'https'],
                ],
                'poster' => [
                  'title' => 'Poster image URL',
                  'type' => 'string',
                  'format' => 'uri-reference',
                  'contentMediaType' => 'image/*',
                  'x-allowed-schemes' => ['http', 'https'],
                  'id' => 'json-schema-definitions://canvas.module/image-uri',
                ],
              ],
              'id' => 'json-schema-definitions://canvas.module/video',
            ],
            'sourceType' => 'static:field_item:file',
            'expression' => 'ℹ︎file␟{src↝entity␜␜entity:file␝uri␞␟url}',
            'sourceTypeSettings' => [
              'instance' => [
                'file_extensions' => 'mp4',
              ],
            ],
            'default_values' => [
              'source' => [],
              'resolved' => [
                'src' => 'https://media.istockphoto.com/id/1340051874/video/aerial-top-down-view-of-a-container-cargo-ship.mp4?s=mp4-640x640-is&k=20&c=5qPpYI7TOJiOYzKq9V2myBvUno6Fq2XM3ITPGFE8Cd8=',
                'poster' => 'https://example.com/600x400.png',
              ],
            ],
          ],
          'display_width' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'integer',
              'minimum' => 1,
            ],
            'sourceType' => 'static:field_item:integer',
            'expression' => 'ℹ︎integer␟value',
            'sourceTypeSettings' => [
              'instance' => [
                'min' => 1,
                'max' => NULL,
              ],
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.sdc_theme_test.bar' => [
        'expected_output_selectors' => [
          'h1',
        ],
        'source' => 'Theme component',
        'metadata' => ['slots' => []],
        'propSources' => [],
        'transforms' => [],
      ],
      'sdc.sdc_theme_test.lib-overrides' => [
        'expected_output_selectors' => [
          ':root ',
        ],
        'source' => 'Theme component',
        'metadata' => ['slots' => []],
        'propSources' => [],
        'transforms' => [],
      ],
      'sdc.sdc_theme_test.my-card' => [
        'expected_output_selectors' => [
          '[data-component-id="sdc_theme_test:my-card"]',
        ],
        'source' => 'Theme component',
        'metadata' => [
          'slots' => [
            'card_body' => [
              'title' => 'Body',
              'description' => 'The contents of the card.',
              'examples' => [
                '<p>Foo is <strong>NOT</strong> bar.</p>',
              ],
            ],
          ],
        ],
        'propSources' => [
          'header' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => ['value' => 'I am a header!'],
              ],
              'resolved' => 'I am a header!',
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.sdc_theme_test_base.my-card-no-schema' => [
        'expected_output_selectors' => [
          '[data-component-id="sdc_theme_test_base:my-card-no-schema"]',
        ],
        'source' => 'Theme component',
        'metadata' => ['slots' => []],
        'propSources' => [],
        'transforms' => [],
      ],
    ];
  }

  /**
   * @covers ::inputToClientModel()
   * @dataProvider explicitsInputsProvider
   */
  public function testInputToClientModel(string $component_id, array $explicit_input, array $expected_client_model):void {
    $this->generateComponentConfig();

    // Ensure the explicit input is valid; helps catch incorrect test cases that
    // could lead to misleading test results.
    foreach ($explicit_input['source'] as $prop_name => $prop_source_array) {
      $resolved_source = PropSource::parse($prop_source_array)->evaluate(NULL, is_required: TRUE);
      self::assertEquals($resolved_source, $explicit_input['resolved'][$prop_name]);
    }

    $component = Component::load($component_id);
    \assert($component instanceof Component);
    $component_source = $component->getComponentSource();
    // It can only be Code components or SDC.
    \assert($component_source instanceof GeneratedFieldExplicitInputUxComponentSourceBase);
    $actual_model_client = $component_source->inputToClientModel($explicit_input);
    $this->assertEquals($expected_client_model, $actual_model_client);
  }

  /**
   * @param array{source: SingleComponentInputArray, resolved: array<string, mixed>} $clientModel
   * @param ?array $nodeValues
   * @param ?array $expectedInput
   * @param ?class-string<\Throwable> $expectedExceptionClass
   * @param ?string $expectedExceptionMessage
   *
   * @covers ::clientModelToInput
   * @dataProvider providerClientModelToInput
   */
  public function testClientModelToInput(array $clientModel, ?array $nodeValues, ?array $expectedInput, ?string $expectedExceptionClass, ?string $expectedExceptionMessage): void {
    $this->generateComponentConfig();
    $component = Component::load('sdc.canvas_test_sdc.my-hero');
    self::assertInstanceOf(Component::class, $component);

    $this->installEntitySchema('node');
    $this->installSchema('node', 'node_access');
    NodeType::create([
      'type' => 'page',
      'name' => 'Page',
    ])->save();
    if ($nodeValues !== NULL) {
      $hostEntity = Node::create($nodeValues);
      self::assertCount(0, $hostEntity->validate());
      $hostEntity->save();
    }
    else {
      $hostEntity = NULL;
    }

    // Ensure the permissions required to evaluate dynamic expressions are not
    // needed.
    // @see \Drupal\canvas\PropExpressions\StructuredData\Evaluator::validateAccess()
    $this->setUpCurrentUser();

    if ($expectedExceptionClass !== NULL) {
      $this->expectException($expectedExceptionClass);
      self::assertNotNull($expectedExceptionMessage);
      $this->expectExceptionMessage($expectedExceptionMessage);
      self::assertNull($expectedInput);
    }
    else {
      self::assertNull($expectedExceptionMessage);
      self::assertNotNull($expectedInput);
    }
    $input = $component->getComponentSource()->clientModelToInput('a-uuid-for-testing', $component, $clientModel, $hostEntity);
    self::assertSame($expectedInput, $input);
  }

  public static function providerClientModelToInput(): \Generator {
    $clientModel = [
      'source' => [
        'heading' => [
          'sourceType' => 'dynamic',
          'expression' => 'ℹ︎␜entity:node:page␝title␞␟value',
          'value' => 'Some value, will be ignored by server',
        ],
        'cta1' => [
          'sourceType' => 'static:field_item:string',
          'expression' => 'ℹ︎string␟value',
          'value' => 'Witty test value',
        ],
        'cta1href' => [
          'sourceType' => 'static:field_item:link',
          'value' => ['uri' => 'https://example.com', 'options' => []],
          'expression' => 'ℹ︎link␟url',
          'sourceTypeSettings' => [
            'instance' => [
              'title' => 0,
              'link_type' => LinkItemInterface::LINK_GENERIC,
            ],
          ],
        ],
        'cta2' => [
          'sourceType' => 'static:field_item:string',
          'expression' => 'ℹ︎string␟value',
          'value' => 'Inside developer joke',
        ],
        'subheading' => [
          'sourceType' => 'dynamic',
          'expression' => 'ℹ︎␜entity:node:page␝revision_log␞␟value',
          'value' => NULL,
        ],
      ],
      'resolved' => [
        'heading' => 'Does not have to match',
        'cta1' => 'Is what server previously sent',
        'cta1href' => ['uri' => 'https://example.com', 'options' => []],
        'cta2' => 'Click, or don\'t',
        'subheading' => NULL,
      ],
    ];
    $expectedInput = [
      'heading' => [
        'sourceType' => 'dynamic',
        'expression' => 'ℹ︎␜entity:node:page␝title␞␟value',
      ],
      'cta1' => 'Witty test value',
      'cta1href' => [
        'uri' => 'https://example.com',
        'options' => [],
      ],
      'cta2' => 'Inside developer joke',
      'subheading' => [
        'sourceType' => 'dynamic',
        'expression' => 'ℹ︎␜entity:node:page␝revision_log␞␟value',
      ],
    ];
    $nodeValues = ['type' => 'page', 'title' => 'Test page for inputToClientModel'];
    // Explicit failure when no host entity is provided.
    yield "Invalid: DynamicPropSource without host entity" => [
      'clientModel' => $clientModel,
      'nodeValues' => NULL,
      'expectedInput' => NULL,
      'expectedExceptionClass' => \InvalidArgumentException::class,
      'expectedExceptionMessage' => 'A host entity is required to set dynamic prop sources.',
    ];
    // Expected (server-side) component instance input for the given client model when provided with a host entity.
    // The non-required property "subheading" is linked to an empty field, revision_log.
    yield "Valid: DynamicPropSource with host entity, empty non-required property" => [
      'clientModel' => $clientModel,
      'nodeValues' => $nodeValues,
      'expectedInput' => $expectedInput,
      'expectedExceptionClass' => NULL,
      'expectedExceptionMessage' => NULL,
    ];
    // Expected (server-side) component instance input for the given client model when provided with a host entity.
    // The non-required property "subheading" is linked to a non-empty field, revision_log.
    $nodeValues['revision_log'] = 'This is the revision log.';
    yield "Valid: DynamicPropSource with host entity, not empty non-required property" => [
      'clientModel' => $clientModel,
      'nodeValues' => $nodeValues,
      'expectedInput' => $expectedInput,
      'expectedExceptionClass' => NULL,
      'expectedExceptionMessage' => NULL,
    ];
    // Modifying the client model to use an expression requiring a different bundle triggers an exception.
    $clientModel['source']['heading']['expression'] = 'ℹ︎␜entity:node:article␝title␞␟value';
    yield "Invalid: DynamicPropSource, expression with non-matching bundle" => [
      'clientModel' => $clientModel,
      'nodeValues' => $nodeValues,
      'expectedInput' => NULL,
      'expectedExceptionClass' => \DomainException::class,
      'expectedExceptionMessage' => '`ℹ︎␜entity:node:article␝title␞␟value` is an expression for entity type `node`, bundle(s) `article`, but the provided entity is of the bundle `page`.',
    ];
  }

  public static function explicitsInputsProvider(): \Generator {
    yield 'image' => [
      'sdc.canvas_test_sdc.image',
      [
        'source' => [
          'image' => [
            'sourceType' => 'default-relative-url',
            'value' => [
              'src' => '/600x400.png',
              'alt' => 'Boring placeholder',
              'width' => 600,
              'height' => 400,
            ],
            'jsonSchema' => [
              'type' => 'object',
              'properties' => [
                'src' => [
                  'type' => 'string',
                  'contentMediaType' => 'image/*',
                  'format' => 'uri-reference',
                  'x-allowed-schemes' => ['http', 'https'],
                ],
                'alt' => [
                  'type' => 'string',
                ],
                'width' => [
                  'type' => 'integer',
                ],
                'height' => [
                  'type' => 'integer',
                ],
              ],
              'required' => [
                'src',
              ],
            ],
            'componentId' => 'sdc.canvas_test_sdc.image',
          ],
        ],
        'resolved' => [
          'image' => new EvaluationResult(
            [
              'src' => static::getCiModulePath() . '/tests/modules/canvas_test_sdc/components/image/600x400.png',
              'alt' => 'Boring placeholder',
              'width' => 600,
              'height' => 400,
            ],
            (new CacheableMetadata())->setCacheTags(['component_plugins']),
          ),
        ],
      ],
      [
        'source' => [
          'image' => [
            'sourceType' => 'static:field_item:image',
            'expression' => 'ℹ︎image␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
            'value' => NULL,
          ],
        ],
        'resolved' => [
          'image' => [
            'src' => static::getCiModulePath() . '/tests/modules/canvas_test_sdc/components/image/600x400.png',
            'alt' => 'Boring placeholder',
            'width' => 600,
            'height' => 400,
          ],
        ],
      ],
    ];
  }

  protected function createAndSaveInUseComponentForFallbackTesting(): ComponentInterface {
    // Media library depends on the views module and media depends on field
    // config.
    $this->enableModules(['media', 'media_library', 'views', 'field']);
    $this->installEntitySchema('file');
    $this->installSchema('file', 'file_usage');
    $this->installEntitySchema('media');
    $this->createMediaType('image', ['id' => 'image', 'label' => 'Image']);

    // @todo Simplify this in https://www.drupal.org/project/canvas/issues/3547579 — that issue should make that happen automatically? If not that, then it should probably expand the below test assertions at the very least.
    /** @var \Drupal\canvas\Entity\ComponentInterface */
    $component = Component::load('sdc.canvas_test_sdc.image');
    self::assertSame([
      'config' => [
        'image.style.canvas_parametrized_width',
      ],
      'module' => [
        'canvas_test_sdc',
        'file',
        'image',
      ],
    ], $component->getDependencies());
    self::assertCount(1, $component->getVersions());
    $this->generateComponentConfig();
    $component = Component::load('sdc.canvas_test_sdc.image');
    self::assertInstanceOf(ComponentInterface::class, $component);
    self::assertSame([
      'config' => [
        'field.field.media.image.field_media_image',
        'image.style.canvas_parametrized_width',
        'media.type.image',
      ],
      'module' => [
        'canvas_test_sdc',
        'file',
        'image',
        'media',
        'media_library',
      ],
    ], $component->getDependencies());
    self::assertCount(2, $component->getVersions());
    return $component;
  }

  protected function createAndSaveUnusedComponentForFallbackTesting(): ComponentInterface {
    /** @var \Drupal\canvas\Entity\ComponentInterface */
    return Component::load('sdc.canvas_test_sdc.image-optional-without-example');
  }

  protected function deleteConfigAndTriggerComponentFallback(ComponentInterface $used_component, ComponentInterface $unused_component): void {
    $type = MediaType::load('image');
    \assert($type instanceof MediaType);
    $type->delete();
  }

  protected static function getPropsForComponentFallbackTesting(): array {
    /** @var \Drupal\Core\File\FileSystemInterface $file_system */
    $file_system = \Drupal::service('file_system');
    $file_uri = 'public://image-2.jpg';
    if (!\file_exists($file_uri)) {
      $file_system->copy(\Drupal::root() . '/core/tests/fixtures/files/image-2.jpg', PublicStream::basePath(), FileExists::Replace);
    }
    $file = File::create([
      'uri' => $file_uri,
      'status' => 1,
    ]);
    $file->save();
    $image = Media::create([
      'bundle' => 'image',
      'name' => 'Amazing image',
      'field_media_image' => [
        [
          'target_id' => $file->id(),
          'alt' => 'An image so amazing that to gaze upon it would melt your face',
          'title' => 'This is an amazing image, just look at it and you will be amazed',
        ],
      ],
    ]);
    $image->save();
    return [
      'image' => [
        'sourceType' => 'static:field_item:entity_reference',
        'value' => ['target_id' => $image->id()],
        // This expression resolves `src` to the image's public URL.
        // @see \Drupal\canvas\Hook\ShapeMatchingHooks::mediaLibraryStorablePropShapeAlter()
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
    ];
  }

  protected function recoverComponentFallback(ComponentInterface $component): void {
    $this->createMediaType('image', ['id' => 'image', 'label' => 'Image']);
    $this->generateComponentConfig();
  }

  protected function createAndSaveInUseComponentForUninstallValidationTesting(): ComponentInterface {
    $this->enableModules(['sdc_test']);
    $this->generateComponentConfig();
    /** @var \Drupal\canvas\Entity\ComponentInterface */
    return Component::load('sdc.sdc_test.no-props');
  }

  protected function createAndSaveUnusedComponentForUninstallValidationTesting(): ComponentInterface {
    /** @var \Drupal\canvas\Entity\ComponentInterface */
    return Component::load('sdc.canvas_test_sdc.props-slots');
  }

  protected function getNotAllowedModuleForUninstallValidatorTesting(): string {
    return 'sdc_test';
  }

  protected function getAllowedModuleForUninstallValidatorTesting(): string {
    return 'canvas_test_sdc';
  }

  protected function triggerBrokenComponent(ComponentInterface $component): BrokenPluginManagerInterface {
    /** @var \Drupal\Tests\canvas\Kernel\BrokenPluginManagerInterface */
    return \Drupal::service('plugin.manager.sdc');
  }

  /**
   * {@inheritdoc}
   */
  public static function providerComponentForValidateInputRejectsUnexpectedProps(): array {
    return [
      'SDC with props and slots' => [
        'source_id' => 'sdc',
        'source_specific_id' => 'canvas_test_sdc:props-slots',
        'valid_prop_name' => 'heading',
        'valid_prop_input' => [
          'sourceType' => 'static:field_item:string',
          'value' => [['value' => 'Valid heading']],
          'expression' => 'ℹ︎string␟value',
        ],
      ],
    ];
  }

  public function alter(ContainerBuilder $container): void {
    // Swap in the broken version of this class.
    // @see ::triggerBrokenComponent()
    // @see ::testIsBroken()
    $container->getDefinition('plugin.manager.sdc')->setClass(BrokenComponentManager::class);
  }

  protected function getExpectedVerboseErrorMessage(): string {
    // The test simulates the SDC's Twig template having been deleted, so it fails to load.
    // @see ::triggerBrokenComponent()
    return 'Twig\Error\LoaderError occurred during rendering of component';
  }

}
