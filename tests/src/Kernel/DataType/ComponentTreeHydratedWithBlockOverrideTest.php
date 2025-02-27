<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\DataType;

// cspell:ignore ttxgk xpzur

use Drupal\Core\Render\Element;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\Component\Utility\NestedArray;

/**
 * @coversDefaultClass \Drupal\experience_builder\Plugin\DataType\ComponentTreeHydrated
 * @covers \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\BlockComponent::renderComponent()
 * @group experience_builder
 */
class ComponentTreeHydratedWithBlockOverrideTest extends ComponentTreeHydratedTest {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['xb_dev_js_blocks'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig('xb_dev_js_blocks');
    // Block overriding-code components only take effect if they're enabled.
    // @phpstan-ignore-next-line
    JavaScriptComponent::load('site_branding')->enable()->save();
  }

  /**
   * @dataProvider provider
   */
  public function test(array $tree, array $inputs, array $expected_value, array $expected_renderable, string $expected_html, array $expected_cache_tags): void {
    self::loadEntitiesIntoRenderable($expected_renderable);
    parent::test($tree, $inputs, $expected_value, $expected_renderable, $expected_html, $expected_cache_tags);
  }

  /**
   * Loads entities, because PHPUnit data providers can't access those.
   */
  private static function loadEntitiesIntoRenderable(array &$renderable): void {
    if (isset($renderable['#js_component'])) {
      $renderable['#js_component'] = JavaScriptComponent::load($renderable['#js_component'][JavaScriptComponent::class]);
    }
    if (isset($renderable['#slots'])) {
      self::loadEntitiesIntoRenderable($renderable['#slots']);
    }
    foreach (Element::children($renderable) as $child) {
      self::loadEntitiesIntoRenderable($renderable[$child]);
    }
  }

  /**
   * Updates base expectations for the JavaScriptComponent block overrides.
   */
  public static function provider(): \Generator {
    foreach (parent::provider() as $case_label => $original_test_case) {
      $expected_changes_vs_original = match ($case_label) {
        "component tree with a single block component" => [
          'expected_renderable' => [
            ComponentTreeStructure::ROOT_UUID => [
              'uuid-in-root' => [
                '#theme' => 'block__system_branding_block__as_js_component',
                '#cache' => [
                  'tags' => [
                    'config:experience_builder.js_component.site_branding',
                    'config:experience_builder.component.block.system_branding_block',
                  ],
                ],
                '#js_component' => [JavaScriptComponent::class => 'site_branding'],
              ],
            ],
          ],
          'expected_html' => <<<HTML
<!-- xb-start-uuid-in-root --><astro-island uid="uuid-in-root"
        component-url="::SITE_DIR_BASE_URL::/files/astro-island/K8cqmr0kCDhtaBupQXmdi2_0mbGKNbJW1Rs3GzW0bV0.js"
        component-export="default"
        renderer-url="::XB_DIR_BASE_URL::/ui/lib/astro-hydration/dist/client.js"
        props="{&quot;homeUrl&quot;:[&quot;raw&quot;,&quot;\/&quot;],&quot;logo&quot;:[&quot;raw&quot;,&quot;&quot;],&quot;siteName&quot;:[&quot;raw&quot;,&quot;XB Test Site&quot;]}"
        ssr="" client="only"
        opts="{&quot;name&quot;:&quot;Site branding&quot;,&quot;value&quot;:&quot;preact&quot;}"><script type="module" src="::XB_DIR_BASE_URL::/ui/lib/astro-hydration/dist/client.js" blocking="render"></script><script type="module" src="::SITE_DIR_BASE_URL::/files/astro-island/K8cqmr0kCDhtaBupQXmdi2_0mbGKNbJW1Rs3GzW0bV0.js" blocking="render"></script><template data-astro-template="siteSlogan"><!-- xb-prop-start-uuid-in-root/siteSlogan -->Experience Builder Test Site<!-- xb-prop-end-uuid-in-root/siteSlogan --></template></astro-island><!-- xb-end-uuid-in-root -->
HTML,
          'expected_cache_tags' => [
            'config:experience_builder.js_component.site_branding',
            'config:experience_builder.component.block.system_branding_block',
          ],
        ] + $original_test_case,

        "component tree with complex nesting" => [
          'expected_renderable' => [
            ComponentTreeStructure::ROOT_UUID => [
              'uuid-in-root' => [
                '#slots' => [
                  'the_body' => [
                    'uuid-level-1' => [
                      '#slots' => [
                        'the_body' => [
                          'uuid-level-2' => [
                            '#slots' => [
                              'the_body' => [
                                'uuid-block' => [
                                  '#theme' => 'block__system_branding_block__as_js_component',
                                  '#cache' => [
                                    'tags' => [
                                      'config:experience_builder.js_component.site_branding',
                                      'config:experience_builder.component.block.system_branding_block',
                                    ],
                                  ],
                                  '#js_component' => [JavaScriptComponent::class => 'site_branding'],
                                ],
                                'uuid-js-component' => [
                                  '#component_url' => '::SITE_DIR_BASE_URL::/files/astro-island/SqNXISgeROx6gBR_DcET4VKD8ttxgkRL9NO-4C0fUSM.js',
                                ],
                              ],
                            ],
                          ],
                        ],
                      ],
                    ],
                  ],
                ],
              ],
            ],
          ],
          'expected_html' => <<<HTML
<!-- xb-start-uuid-in-root --><div  data-component-id="xb_test_sdc:props-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;"><!-- xb-prop-start-uuid-in-root/heading -->Hello, world!<!-- xb-prop-end-uuid-in-root/heading --></h1>
  <div class="component--props-slots--body">
        <!-- xb-slot-start-uuid-in-root/the_body --><!-- xb-start-uuid-level-1 --><div  data-component-id="xb_test_sdc:props-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;"><!-- xb-prop-start-uuid-level-1/heading -->Hello, from slot level 1!<!-- xb-prop-end-uuid-level-1/heading --></h1>
  <div class="component--props-slots--body">
        <!-- xb-slot-start-uuid-level-1/the_body --><!-- xb-start-uuid-level-2 --><div  data-component-id="xb_test_sdc:props-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;"><!-- xb-prop-start-uuid-level-2/heading -->Hello, from slot level 2!<!-- xb-prop-end-uuid-level-2/heading --></h1>
  <div class="component--props-slots--body">
        <!-- xb-slot-start-uuid-level-2/the_body --><!-- xb-start-uuid-level-3 --><div  data-component-id="xb_test_sdc:props-no-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;"><!-- xb-prop-start-uuid-level-3/heading -->Hello, from slot level 3!<!-- xb-prop-end-uuid-level-3/heading --></h1>
</div>
<!-- xb-end-uuid-level-3 --><!-- xb-start-uuid-block --><astro-island uid="uuid-block"
        component-url="::SITE_DIR_BASE_URL::/files/astro-island/K8cqmr0kCDhtaBupQXmdi2_0mbGKNbJW1Rs3GzW0bV0.js"
        component-export="default"
        renderer-url="::XB_DIR_BASE_URL::/ui/lib/astro-hydration/dist/client.js"
        props="{&quot;homeUrl&quot;:[&quot;raw&quot;,&quot;\/&quot;],&quot;logo&quot;:[&quot;raw&quot;,&quot;&quot;],&quot;siteName&quot;:[&quot;raw&quot;,&quot;XB Test Site&quot;]}"
        ssr="" client="only"
        opts="{&quot;name&quot;:&quot;Site branding&quot;,&quot;value&quot;:&quot;preact&quot;}"><script type="module" src="::XB_DIR_BASE_URL::/ui/lib/astro-hydration/dist/client.js" blocking="render"></script><script type="module" src="::SITE_DIR_BASE_URL::/files/astro-island/K8cqmr0kCDhtaBupQXmdi2_0mbGKNbJW1Rs3GzW0bV0.js" blocking="render"></script><template data-astro-template="siteSlogan"><!-- xb-prop-start-uuid-block/siteSlogan -->Experience Builder Test Site<!-- xb-prop-end-uuid-block/siteSlogan --></template></astro-island><!-- xb-end-uuid-block --><!-- xb-start-uuid-js-component --><astro-island uid="uuid-js-component"
        component-url="::SITE_DIR_BASE_URL::/files/astro-island/SqNXISgeROx6gBR_DcET4VKD8ttxgkRL9NO-4C0fUSM.js"
        component-export="default"
        renderer-url="::XB_DIR_BASE_URL::/ui/lib/astro-hydration/dist/client.js"
        props="{&quot;text&quot;:[&quot;raw&quot;,&quot;Hello, from a \&quot;code component\&quot;!&quot;]}"
        ssr="" client="only"
        opts="{&quot;name&quot;:&quot;My First Code Component&quot;,&quot;value&quot;:&quot;preact&quot;}"><script type="module" src="::XB_DIR_BASE_URL::/ui/lib/astro-hydration/dist/client.js" blocking="render"></script><script type="module" src="::SITE_DIR_BASE_URL::/files/astro-island/SqNXISgeROx6gBR_DcET4VKD8ttxgkRL9NO-4C0fUSM.js" blocking="render"></script></astro-island><!-- xb-end-uuid-js-component --><!-- xb-start-uuid-last-in-tree --><div  data-component-id="xb_test_sdc:props-no-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;"><!-- xb-prop-start-uuid-last-in-tree/heading -->Hello, from slot &lt;LAST ONE&gt;!<!-- xb-prop-end-uuid-last-in-tree/heading --></h1>
</div>
<!-- xb-end-uuid-last-in-tree --><!-- xb-slot-end-uuid-level-2/the_body -->
    </div>
  <div class="component--props-slots--footer">
        <!-- xb-slot-start-uuid-level-2/the_footer -->Example value for &lt;strong&gt;the_footer&lt;/strong&gt;.<!-- xb-slot-end-uuid-level-2/the_footer -->
    </div>
  <div class="component--props-slots--colophon">
        <!-- xb-slot-start-uuid-level-2/the_colophon --><!-- xb-slot-end-uuid-level-2/the_colophon -->
    </div>
</div>
<!-- xb-end-uuid-level-2 --><!-- xb-slot-end-uuid-level-1/the_body -->
    </div>
  <div class="component--props-slots--footer">
        <!-- xb-slot-start-uuid-level-1/the_footer -->Example value for &lt;strong&gt;the_footer&lt;/strong&gt;.<!-- xb-slot-end-uuid-level-1/the_footer -->
    </div>
  <div class="component--props-slots--colophon">
        <!-- xb-slot-start-uuid-level-1/the_colophon --><!-- xb-slot-end-uuid-level-1/the_colophon -->
    </div>
</div>
<!-- xb-end-uuid-level-1 --><!-- xb-slot-end-uuid-in-root/the_body -->
    </div>
  <div class="component--props-slots--footer">
        <!-- xb-slot-start-uuid-in-root/the_footer -->Example value for &lt;strong&gt;the_footer&lt;/strong&gt;.<!-- xb-slot-end-uuid-in-root/the_footer -->
    </div>
  <div class="component--props-slots--colophon">
        <!-- xb-slot-start-uuid-in-root/the_colophon --><!-- xb-slot-end-uuid-in-root/the_colophon -->
    </div>
</div>
<!-- xb-end-uuid-in-root -->
HTML,
          'expected_cache_tags' => [
            'config:experience_builder.component.sdc.xb_test_sdc.props-slots',
            'config:experience_builder.component.sdc.xb_test_sdc.props-no-slots',
            'config:experience_builder.component.js.my-cta',
            'config:experience_builder.js_component.site_branding',
            'config:experience_builder.component.block.system_branding_block',
          ],
        ] + $original_test_case,
        default => $original_test_case,
      };

      yield $case_label => NestedArray::mergeDeepArray([$original_test_case, $expected_changes_vs_original], TRUE);
    }
  }

}
