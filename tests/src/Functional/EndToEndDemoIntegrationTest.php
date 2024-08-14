<?php

declare(strict_types=1);

// ⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️
// ⚠️ 🔨🧹  This file will be thrown away. Do not review in detail, ever.   🧹🔨 ⚠️
// ⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️

namespace Drupal\Tests\experience_builder\Functional;

use Drupal\Core\Serialization\Yaml;
use Drupal\experience_builder\Plugin\DataType\ComponentPropsValues;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeHydrated;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\PropSource\AdaptedPropSource;
use Drupal\experience_builder\PropSource\DynamicPropSource;
use Drupal\experience_builder\PropSource\PropSourceBase;
use Drupal\experience_builder\PropSource\StaticPropSource;
use Drupal\file\Entity\File;
use Drupal\image\Entity\ImageStyle;
use Drupal\node\Entity\Node;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\TestFileCreationTrait;

/**
 * @group experience_builder
 */
class EndToEndDemoIntegrationTest extends BrowserTestBase {

  use TestFileCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['experience_builder'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected $profile = 'standard';

  /**
   * Tests that /node/add/article + `Save` is enough to create a XB demo node.
   */
  public function test(): void {
    $page = $this->getSession()->getPage();
    $assert_session = $this->assertSession();

    // The `thumbnail` image style already exists.
    $this->assertInstanceOf(ImageStyle::class, ImageStyle::load('thumbnail'));

    // Node 1 does not exist.
    $this->assertNull(Node::load(1));

    // Navigate to `/node/add/article` and press `Save`, do nothing else.
    $this->drupalLogin($this->rootUser);
    $this->drupalGet('node/add/article');
    $assert_session->statusCodeEquals(200);
    $page->pressButton('Save');
    $this->assertStringEndsWith('node/add/article', $this->getSession()->getCurrentUrl());
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
    $this->assertStringEndsWith('node/add/article', $this->getSession()->getCurrentUrl());

    // Now that a file has been uploaded, we also need to specify `alt`.
    $this->assertSession()->statusMessageContains('Alternative text field is required.');
    $page->fillField('field_hero[0][alt]', 'A random image for testing purposes.');
    $page->pressButton('Save');

    // Success!
    $this->assertStringEndsWith('node/1', $this->getSession()->getCurrentUrl());

    // Assert the node has the expected values.
    $node = Node::load(1);
    // @phpstan-ignore-next-line
    $this->assertInstanceOf(Node::class, $node);
    $this->assertSame([
      [
        'value' => 'The first entity using XB!',
      ],
    ], $node->get('title')->getValue());
    $this->assertSame([
      [
        'target_id' => '1',
        'alt' => 'A random image for testing purposes.',
        'title' => '',
        'width' => '40',
        'height' => '20',
      ],
    ], $node->get('field_hero')->getValue());

    // Also assert that a file was uploaded.
    $this->assertInstanceOf(File::class, File::load(1));
    $this->assertNotNull(File::load(1)->getFileUri());
    $this->assertStringStartsWith('public://', File::load(1)->getFileUri());

    // Assert 5 component instances are placed; they are the default value.
    // @see config/optional/field.field.node.article.field_xb_demo.yml
    $tree = $node->get('field_xb_demo')[0]->get('tree');
    $this->assertInstanceOf(ComponentTreeStructure::class, $tree);
    // First, assert the stored JSON.
    $this->assertEquals([
      ComponentTreeStructure::ROOT_UUID => [
        [
          'uuid' => 'two-column-uuid',
          'component' => 'experience_builder:two_column',
        ],
      ],
      'two-column-uuid' => [
        'column_one' => [
          [
            'uuid' => 'dynamic-image-udf7d',
            'component' => 'experience_builder:image',
          ],
          [
            'uuid' => 'static-static-card1ab',
            'component' => 'experience_builder:my-hero',
          ],
        ],
        'column_two' => [
          [
            'uuid' => 'dynamic-static-card2df',
            'component' => 'experience_builder:my-hero',
          ],
          [
            'uuid' => 'dynamic-dynamic-card3rr',
            'component' => 'experience_builder:my-hero',
          ],
          [
            'uuid' => 'dynamic-image-static-imageStyle-something7d',
            'component' => 'experience_builder:image',
          ],
        ],
      ],
    ], json_decode($tree->getValue(), TRUE));
    // Second, assert the interpreted results.
    $actual_uuids = $tree->getComponentInstanceUuids();
    $this->assertTrue(sort($actual_uuids));
    $this->assertSame([
      'dynamic-dynamic-card3rr',
      'dynamic-image-static-imageStyle-something7d',
      'dynamic-image-udf7d',
      'dynamic-static-card2df',
      'static-static-card1ab',
      'two-column-uuid',
    ], $actual_uuids);

    // Assert each of the 5 component instances have the expected prop sources.
    // @see config/optional/field.field.node.article.field_xb_demo.yml
    $props = $node->get('field_xb_demo')[0]->get('props');
    $this->assertInstanceOf(ComponentPropsValues::class, $props);
    // First, assert the stored JSON.
    $this->assertEquals([
      'dynamic-static-card2df' => [
        'heading' => [
          'sourceType' => 'dynamic',
          'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
        ],
        'cta1href' => [
          'sourceType' => 'static:field_item:uri',
          'value' => 'https://drupal.org',
          'expression' => 'ℹ︎uri␟value',
        ],
      ],
      'static-static-card1ab' => [
        'heading' => [
          'sourceType' => 'static:field_item:string',
          'value' => 'hello, world!',
          'expression' => 'ℹ︎string␟value',
        ],
        'cta1href' => [
          'sourceType' => 'static:field_item:uri',
          'value' => 'https://drupal.org',
          'expression' => 'ℹ︎uri␟value',
        ],
      ],
      'dynamic-dynamic-card3rr' => [
        'heading' => [
          'sourceType' => 'dynamic',
          'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
        ],
        'cta1href' => [
          'sourceType' => 'dynamic',
          'expression' => 'ℹ︎␜entity:node:article␝field_hero␞␟entity␜␜entity:file␝uri␞␟value',
        ],
      ],
      'dynamic-image-udf7d' => [
        'image' => [
          'sourceType' => 'dynamic',
          'expression' => 'ℹ︎␜entity:node:article␝field_hero␞␟{src↝entity␜␜entity:file␝uri␞␟value,alt↠alt,width↠width,height↠height}',
        ],
      ],
      'dynamic-image-static-imageStyle-something7d' => [
        'image' => [
          'sourceType' => 'adapter:image_apply_style',
          'adapterInputs' => [
            'image' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:article␝field_hero␞␟{src↝entity␜␜entity:file␝uri␞0␟value,alt↠alt,width↠width,height↠height}',
            ],
            'imageStyle' => [
              'sourceType' => 'static:field_item:string',
              'value' => 'thumbnail',
              'expression' => 'ℹ︎string␟value',
            ],
          ],
        ],
      ],
      'two-column-uuid' => [
        'width' => [
          'sourceType' => 'static:field_item:list_integer',
          'value' => 50,
          'expression' => 'ℹ︎list_integer␟value',
          'sourceTypeSettings' => [
            'allowed_values' => [
              ['value' => 25, 'label' => '25'],
              ['value' => 33, 'label' => '33'],
              ['value' => 50, 'label' => '50'],
              ['value' => 66, 'label' => '66'],
              ['value' => 75, 'label' => '75'],
            ],
          ],
        ],
      ],
    ], json_decode($props->getValue(), TRUE));
    // Second, assert the interpreted results.
    // More detailed/theoretical tests also exist.
    // @see \Drupal\Tests\experience_builder\Kernel\PropSourceTest
    $make_source_assertable = fn (PropSourceBase $source) : array => [
      'source class' => get_class($source),
      'JSONified' => (string) $source,
      'evaluated' => $source->evaluate($node),
    ];
    // Prop source for component instance with UUID `static-static-card1ab`.
    $this->assertEquals([
      'heading' => [
        // Runtime representation.
        'source class' => StaticPropSource::class,
        // Generated JSON from the runtime representation, should match what is
        // stored.
        'JSONified' => '{"sourceType":"static:field_item:string","value":"hello, world!","expression":"ℹ︎string␟value"}',
        // What this evaluates to, to actually provide a value to an SDC prop.
        'evaluated' => 'hello, world!',
      ],
      'cta1href' => [
        'source class' => StaticPropSource::class,
        'JSONified' => '{"sourceType":"static:field_item:uri","value":"https:\/\/drupal.org","expression":"ℹ︎uri␟value"}',
        'evaluated' => 'https://drupal.org',
      ],
    ], array_map($make_source_assertable, $props->getComponentPropsSources('static-static-card1ab')));
    // Prop source for component instance with UUID `dynamic-static-card2df`.
    $this->assertEquals([
      'heading' => [
        'source class' => DynamicPropSource::class,
        'JSONified' => '{"sourceType":"dynamic","expression":"ℹ︎␜entity:node:article␝title␞␟value"}',
        'evaluated' => $node->getTitle(),
      ],
      'cta1href' => [
        'source class' => StaticPropSource::class,
        'JSONified' => '{"sourceType":"static:field_item:uri","value":"https:\/\/drupal.org","expression":"ℹ︎uri␟value"}',
        'evaluated' => 'https://drupal.org',
      ],
    ], array_map($make_source_assertable, $props->getComponentPropsSources('dynamic-static-card2df')));
    // Prop source for component instance with UUID `dynamic-dynamic-card3rr`.
    $this->assertEquals([
      'heading' => [
        'source class' => DynamicPropSource::class,
        'JSONified' => '{"sourceType":"dynamic","expression":"ℹ︎␜entity:node:article␝title␞␟value"}',
        'evaluated' => $node->getTitle(),
      ],
      'cta1href' => [
        'source class' => DynamicPropSource::class,
        'JSONified' => '{"sourceType":"dynamic","expression":"ℹ︎␜entity:node:article␝field_hero␞␟entity␜␜entity:file␝uri␞␟value"}',
        'evaluated' => File::load(1)->getFileUri(),
      ],
    ], array_map($make_source_assertable, $props->getComponentPropsSources('dynamic-dynamic-card3rr')));
    // Prop source for component instance with UUID `dynamic-image-udf7d`.
    $this->assertEquals([
      'image' => [
        'source class' => DynamicPropSource::class,
        'JSONified' => '{"sourceType":"dynamic","expression":"ℹ︎␜entity:node:article␝field_hero␞␟{src↝entity␜␜entity:file␝uri␞␟value,alt↠alt,width↠width,height↠height}"}',
        'evaluated' => [
          'src' => File::load(1)->getFileUri(),
          'alt' => 'A random image for testing purposes.',
          'width' => 40,
          'height' => 20,
        ],
      ],
    ], array_map($make_source_assertable, $props->getComponentPropsSources('dynamic-image-udf7d')));
    // Prop source for component instance with UUID `dynamic-image-static-imageStyle-something7d`.
    $this->assertEquals([
      'image' => [
        'source class' => AdaptedPropSource::class,
        'JSONified' => '{"sourceType":"adapter:image_apply_style","adapterInputs":{"image":{"sourceType":"dynamic","expression":"ℹ︎␜entity:node:article␝field_hero␞␟{src↝entity␜␜entity:file␝uri␞0␟value,alt↠alt,width↠width,height↠height}"},"imageStyle":{"sourceType":"static:field_item:string","value":"thumbnail","expression":"ℹ︎string␟value"}}}',
        'evaluated' => [
          'src' => ImageStyle::load('thumbnail')->buildUrl(File::load(1)->getFileUri()),
          'alt' => 'A random image for testing purposes.',
          'width' => 40,
          'height' => 20,
        ],
      ],
    ], array_map($make_source_assertable, $props->getComponentPropsSources('dynamic-image-static-imageStyle-something7d')));

    // Assert 5 component instances can be fetched in hydrated fashion.
    // @see config/optional/field.field.node.article.field_xb_demo.yml
    $hydrated = $node->get('field_xb_demo')[0]->get('hydrated');
    $this->assertInstanceOf(ComponentTreeHydrated::class, $hydrated);
    $this->assertEquals([
      ComponentTreeStructure::ROOT_UUID => [
        'two-column-uuid' => [
          'component' => 'experience_builder:two_column',
          'props' => [
            'width' => 50,
          ],
          'slots' => [
            'column_one' => [
              'dynamic-image-udf7d' => [
                'component' => 'experience_builder:image',
                'props' => [
                  'image' => [
                    'src' => File::load(1)->getFileUri(),
                    'alt' => 'A random image for testing purposes.',
                    'width' => 40,
                    'height' => 20,
                  ],
                ],
              ],
              'static-static-card1ab' => [
                'component' => 'experience_builder:my-hero',
                'props' => [
                  'heading' => 'hello, world!',
                  'cta1href' => 'https://drupal.org',
                ],
              ],
            ],
            'column_two' => [
              'dynamic-static-card2df' => [
                'component' => 'experience_builder:my-hero',
                'props' => [
                  'heading' => $node->getTitle(),
                  'cta1href' => 'https://drupal.org',
                ],
              ],
              'dynamic-dynamic-card3rr' => [
                'component' => 'experience_builder:my-hero',
                'props' => [
                  'heading' => $node->getTitle(),
                  'cta1href' => File::load(1)->getFileUri(),
                ],
              ],
              'dynamic-image-static-imageStyle-something7d' => [
                'component' => 'experience_builder:image',
                'props' => [
                  'image' => [
                    'src' => ImageStyle::load('thumbnail')->buildUrl(File::load(1)->getFileUri()),
                    'alt' => 'A random image for testing purposes.',
                    'width' => 40,
                    'height' => 20,
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
      // @phpstan-ignore-next-line
    ], json_decode($hydrated->getValue()->getContent(), TRUE));

    // Assert each component instance is rendered correctly on `/node/1`.
    $hero_components = $page->findAll('css', '[data-component-id="experience_builder:my-hero"]');
    $this->assertCount(3, $hero_components);
    $image_components = $page->findAll('css', 'img[alt="A random image for testing purposes."]');
    $this->assertCount(2, $image_components);
    // Markup for component instance with UUID `static-static-card1ab`.
    $this->assertSame(<<<HTML
<div data-component-id="experience_builder:my-hero" class="my-hero__container">
  <h1 class="my-hero__heading">hello, world!</h1>
  <p class="my-hero__subheading"></p>
  <div class="my-hero__actions">
    <button formaction="https://drupal.org" class="my-hero__cta my-hero__cta--primary">
      </button>
    <button class="my-hero__cta">
      </button>
  </div>
</div>
HTML, $hero_components[0]->getOuterHtml());
    // Markup for component instance with UUID `dynamic-static-card2df`.
    $this->assertSame(sprintf(<<<HTML
<div data-component-id="experience_builder:my-hero" class="my-hero__container">
  <h1 class="my-hero__heading">%s</h1>
  <p class="my-hero__subheading"></p>
  <div class="my-hero__actions">
    <button formaction="https://drupal.org" class="my-hero__cta my-hero__cta--primary">
      </button>
    <button class="my-hero__cta">
      </button>
  </div>
</div>
HTML, $node->getTitle()), $hero_components[1]->getOuterHtml());
    // Markup for component instance with UUID `dynamic-dynamic-card3rr`.
    $this->assertSame(sprintf(<<<HTML
<div data-component-id="experience_builder:my-hero" class="my-hero__container">
  <h1 class="my-hero__heading">%s</h1>
  <p class="my-hero__subheading"></p>
  <div class="my-hero__actions">
    <button formaction="%s" class="my-hero__cta my-hero__cta--primary">
      </button>
    <button class="my-hero__cta">
      </button>
  </div>
</div>
HTML, $node->getTitle(), File::load(1)->getFileUri()), $hero_components[2]->getOuterHtml());
    // Markup for component instance with UUID `dynamic-image-udf7d`.
    $this->assertSame(sprintf(<<<HTML
<img src="%s" alt="A random image for testing purposes." width="%d" height="%d">
HTML, File::load(1)->getFileUri(), 40, 20), $image_components[0]->getOuterHtml());
    // Markup for component instance with UUID `dynamic-image-static-imageStyle-something7d`.
    $this->assertSame(sprintf(<<<HTML
<img src="%s" alt="A random image for testing purposes." width="%d" height="%d">
HTML, ImageStyle::load('thumbnail')->buildUrl(File::load(1)->getFileUri()), 40, 20), $image_components[1]->getOuterHtml());
  }

  /**
   * {@inheritdoc}
   */
  protected function prepareSettings(): void {
    parent::prepareSettings();

    $directory = DRUPAL_ROOT . '/' . $this->siteDirectory;
    // @todo Why does phpstan not know of the Yaml class.
    // @phpstan-ignore-next-line
    $services = Yaml::decode(file_get_contents($directory . '/services.yml'));
    // Opt in to strict config schema checking, even though this is a contrib
    // module.
    $services['services']['testing.config_schema_checker']['arguments'][2] = TRUE;
    // @todo Why does phpstan not know of the Yaml class.
    // @phpstan-ignore-next-line
    file_put_contents($directory . '/services.yml', Yaml::encode($services));
  }

}
