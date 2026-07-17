<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Config;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\PropSource\PropSource;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\CrawlerTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests content templates whose component trees contain adapted prop sources.
 *
 * Covers, end to end: write-time validation of nested AdaptedPropSources in
 * config-owned component trees, rendering through the regular view builder,
 * and config export/import fidelity (identical rendering after a round trip
 * through raw config data).
 *
 * @see \Drupal\canvas\PropSource\AdaptedPropSource
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class ContentTemplateAdaptedPropSourceTest extends CanvasKernelTestBase {

  use ContentTypeCreationTrait;
  use CrawlerTrait;
  use GenerateComponentConfigTrait;
  use NodeCreationTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'field',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installConfig(['node']);
    $this->installConfig(['canvas']);
    $this->createContentType(['type' => 'article']);
    $this->generateComponentConfig();
    $this->setUpCurrentUser(permissions: ['access content']);
  }

  public function testAdaptedPropSourcesInContentTemplate(): void {
    $static_string = fn (string $value): array => [
      'sourceType' => 'static:field_item:string',
      'value' => $value,
      'expression' => 'ℹ︎string␟value',
    ];
    $title_field = [
      'sourceType' => PropSource::EntityField->value,
      'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
    ];
    $my_hero = Component::load('sdc.canvas_test_sdc.my-hero');
    \assert($my_hero instanceof Component);
    $template = ContentTemplate::create([
      'id' => 'node.article.full',
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [
        [
          'uuid' => 'e1f6fbca-e331-4506-9dba-5734194c1e59',
          'component_id' => 'sdc.canvas_test_sdc.my-hero',
          'component_version' => $my_hero->getActiveVersion(),
          'inputs' => [
            // An adapted prop source: conditional then/else on the title, fed
            // by an entity field input and static literal inputs.
            'heading' => [
              'sourceType' => 'adapter:equals',
              'adapterInputs' => [
                'value' => $title_field,
                'comparison' => $static_string('A gratis article'),
                'then' => $static_string('FREE!'),
                'else' => $title_field,
              ],
            ],
            // A chained adapted prop source: created (UNIX timestamp) → date
            // string → relative phrase.
            'subheading' => [
              'sourceType' => 'adapter:format_date',
              'adapterInputs' => [
                'date' => [
                  'sourceType' => 'adapter:unix_to_date',
                  'adapterInputs' => [
                    'unix' => [
                      'sourceType' => PropSource::EntityField->value,
                      'expression' => 'ℹ︎␜entity:node:article␝created␞␟value',
                    ],
                  ],
                ],
                'format' => $static_string('relative'),
              ],
            ],
            // A plain entity field prop source: every content template
            // requires at least one.
            'cta1' => $title_field,
            // TRICKY: `absolute` must be explicit: validation requires inputs
            // in their canonical (optimized) serialization.
            'cta1href' => [
              'sourceType' => PropSource::HostEntityUrl->value,
              'absolute' => FALSE,
            ],
          ],
        ],
      ],
      'status' => TRUE,
    ]);
    // Write-time validation accepts nested adapted prop sources.
    $this->assertEntityIsValid($template);
    $template->save();

    $node = $this->createNode([
      'type' => 'article',
      'title' => 'A gratis article',
      'created' => \Drupal::time()->getRequestTime() - 2 * 86400 - 60,
      'uid' => 1,
    ]);
    \assert($node instanceof NodeInterface);

    $render_template = function (NodeInterface $node): string {
      $view_builder = $this->container->get(EntityTypeManagerInterface::class)->getViewBuilder('node');
      $build = $view_builder->view($node, 'full');
      return $this->crawlerForRenderArray($build)->html();
    };

    $html = $render_template($node);
    // The Equals adapter matched: "FREE!" is displayed instead of the title.
    self::assertStringContainsString('FREE!', $html);
    // The chained Date conversion adapter produced a relative phrase.
    self::assertStringContainsString('2 days ago', $html);

    // Config export/import round trip: re-creating the template from its raw
    // (exportable) config data produces identical rendering.
    $raw_config = $this->container->get('config.storage')->read('canvas.content_template.node.article.full');
    self::assertIsArray($raw_config);
    // The adapted prop sources survive serialization into config verbatim.
    // (Exported component trees are keyed by component instance UUID.)
    $exported_inputs = $raw_config['component_tree']['e1f6fbca-e331-4506-9dba-5734194c1e59']['inputs'];
    self::assertSame('adapter:equals', $exported_inputs['heading']['sourceType']);
    self::assertSame('adapter:unix_to_date', $exported_inputs['subheading']['adapterInputs']['date']['sourceType']);

    $template->delete();
    unset($raw_config['uuid'], $raw_config['_core']);
    $raw_config['component_tree'] = \array_values($raw_config['component_tree']);
    $reimported = ContentTemplate::create($raw_config);
    $this->assertEntityIsValid($reimported);
    $reimported->save();

    $node_id = $node->id();
    self::assertNotNull($node_id);
    $node = $this->container->get(EntityTypeManagerInterface::class)->getStorage('node')->loadUnchanged($node_id);
    \assert($node instanceof NodeInterface);
    $reimported_html = $render_template($node);
    self::assertStringContainsString('FREE!', $reimported_html);
    self::assertStringContainsString('2 days ago', $reimported_html);
  }

}
