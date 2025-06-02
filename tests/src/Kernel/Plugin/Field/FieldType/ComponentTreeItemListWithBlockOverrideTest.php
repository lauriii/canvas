<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Plugin\Field\FieldType;

// cspell:ignore DILNF Vaxx AEDM
use Drupal\Core\Render\Element;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\Component\Utility\NestedArray;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemList;

/**
 * @coversDefaultClass \Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemList
 * @covers \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\BlockComponent::renderComponent()
 * @group experience_builder
 */
class ComponentTreeItemListWithBlockOverrideTest extends ComponentTreeItemListTest {

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
   * @covers ::getHydratedTree()
   * @covers ::toRenderable()
   * @dataProvider provider
   */
  public function testHydrationAndRendering(array $value, array $expected_value, array $expected_renderable, string $expected_html, array $expected_cache_tags, bool $isPreview): void {
    self::loadEntitiesIntoRenderable($expected_renderable);
    parent::testHydrationAndRendering($value, $expected_value, $expected_renderable, $expected_html, $expected_cache_tags, $isPreview);
  }

  /**
   * Loads entities, because PHPUnit data providers can't access those.
   */
  private static function loadEntitiesIntoRenderable(array &$renderable): void {
    if (isset($renderable['#component']['#js_component'])) {
      $renderable['#component']['#js_component'] = JavaScriptComponent::load($renderable['#component']['#js_component'][JavaScriptComponent::class]);
    }
    if (isset($renderable['#component']['#slots'])) {
      self::loadEntitiesIntoRenderable($renderable['#component']['#slots']);
    }
    foreach (Element::children($renderable) as $child) {
      self::loadEntitiesIntoRenderable($renderable[$child]);
    }
  }

  /**
   * Updates base expectations for the JavaScriptComponent block overrides.
   */
  public static function provider(): \Generator {
    $path_to_deepest_level = [
      ComponentTreeItemList::ROOT_UUID,
      '41595148-e5c1-4873-b373-be3ae6e21340',
      '#component', '#slots', 'the_body',
      'dfd2e899-6d88-46f8-b6aa-98929d1586dd',
      '#component', '#slots', 'the_body',
      'e0b92f23-c177-4196-8fa4-3e837f99a357',
      '#component', '#slots', 'the_body',
    ];

    foreach (parent::provider() as $case_label => $original_test_case) {
      $expected_changes_vs_original = match ($case_label) {
        "component tree with a single block component" => [
          'expected_renderable' => [
            ComponentTreeItemList::ROOT_UUID => [
              '41595148-e5c1-4873-b373-be3ae6e21340' => [
                '#component' => [
                  '#theme' => 'block__system_branding_block__as_js_component',
                  '#cache' => [
                    'tags' => [
                      'config:experience_builder.js_component.site_branding',
                      'config:system.site',
                      'config:experience_builder.component.block.system_branding_block',
                    ],
                  ],
                  '#js_component' => [JavaScriptComponent::class => 'site_branding'],
                  '#xb_preview' => FALSE,
                ],
              ],
            ],
          ],
          'expected_html' => <<<HTML
<astro-island uid="41595148-e5c1-4873-b373-be3ae6e21340"
        component-url="::SITE_DIR_BASE_URL::/files/astro-island/5XdTAI4-WnaGx3DILNF0UUqh3wMJVmVaxxWHs5tg9QU.js"
        component-export="default"
        renderer-url="::XB_DIR_BASE_URL::/ui/lib/astro-hydration/dist/client.js"
        props="{&quot;homeUrl&quot;:[&quot;raw&quot;,&quot;\/&quot;],&quot;logo&quot;:[&quot;raw&quot;,&quot;&quot;],&quot;siteName&quot;:[&quot;raw&quot;,&quot;XB Test Site&quot;]}"
        ssr="" client="only"
        opts="{&quot;name&quot;:&quot;Site branding&quot;,&quot;value&quot;:&quot;preact&quot;}"><script type="module" src="::XB_DIR_BASE_URL::/ui/lib/astro-hydration/dist/client.js" blocking="render"></script><script type="module" src="::SITE_DIR_BASE_URL::/files/astro-island/5XdTAI4-WnaGx3DILNF0UUqh3wMJVmVaxxWHs5tg9QU.js" blocking="render"></script><template data-astro-template="siteSlogan">Experience Builder Test Site</template></astro-island>
HTML,
          'expected_cache_tags' => [
            'config:experience_builder.js_component.site_branding',
            'config:system.site',
            'config:experience_builder.component.block.system_branding_block',
          ],
        ] + $original_test_case,

        "component tree with a single block component in preview" => [
          'expected_renderable' => [
            ComponentTreeItemList::ROOT_UUID => [
              '41595148-e5c1-4873-b373-be3ae6e21340' => [
                '#component' => [
                  '#theme' => 'block__system_branding_block__as_js_component',
                  '#cache' => [
                    'tags' => [
                      'config:experience_builder.js_component.site_branding',
                      'config:system.site',
                      'config:experience_builder.component.block.system_branding_block',
                    ],
                  ],
                  '#js_component' => [JavaScriptComponent::class => 'site_branding'],
                  '#xb_preview' => TRUE,
                ],
              ],
            ],
          ],
          'expected_html' => <<<HTML
<!-- xb-start-41595148-e5c1-4873-b373-be3ae6e21340 --><astro-island uid="41595148-e5c1-4873-b373-be3ae6e21340"
        component-url="::SITE_DIR_BASE_URL::/files/astro-island/5XdTAI4-WnaGx3DILNF0UUqh3wMJVmVaxxWHs5tg9QU.js"
        component-export="default"
        renderer-url="::XB_DIR_BASE_URL::/ui/lib/astro-hydration/dist/client.js"
        props="{&quot;homeUrl&quot;:[&quot;raw&quot;,&quot;\/&quot;],&quot;logo&quot;:[&quot;raw&quot;,&quot;&quot;],&quot;siteName&quot;:[&quot;raw&quot;,&quot;XB Test Site&quot;]}"
        ssr="" client="only"
        opts="{&quot;name&quot;:&quot;Site branding&quot;,&quot;value&quot;:&quot;preact&quot;}"><script type="module" src="::XB_DIR_BASE_URL::/ui/lib/astro-hydration/dist/client.js" blocking="render"></script><script type="module" src="::SITE_DIR_BASE_URL::/files/astro-island/5XdTAI4-WnaGx3DILNF0UUqh3wMJVmVaxxWHs5tg9QU.js" blocking="render"></script><template data-astro-template="siteSlogan"><!-- xb-prop-start-41595148-e5c1-4873-b373-be3ae6e21340/siteSlogan -->Experience Builder Test Site<!-- xb-prop-end-41595148-e5c1-4873-b373-be3ae6e21340/siteSlogan --></template></astro-island><!-- xb-end-41595148-e5c1-4873-b373-be3ae6e21340 -->
HTML,
          'expected_cache_tags' => [
            'config:experience_builder.js_component.site_branding',
            'config:system.site',
            'config:experience_builder.component.block.system_branding_block',
            AutoSaveManager::CACHE_TAG,
          ],
        ] + $original_test_case,

        "component tree with complex nesting" => [
          ...self::overwriteRenderableExpectations(
            $original_test_case,
            [
              // The block component instance becomes a "block override" component instance.
              0 => [
                'parents' => [...$path_to_deepest_level, '68167e4a-9245-41be-b564-f1e1dcad1dec', '#component'],
                'value' => [
                  '#theme' => 'block__system_branding_block__as_js_component',
                  '#cache' => [
                    'tags' => [
                      'config:experience_builder.js_component.site_branding',
                      'config:system.site',
                      'config:experience_builder.component.block.system_branding_block',
                    ],
                  ],
                  '#js_component' => [JavaScriptComponent::class => 'site_branding'],
                  '#xb_preview' => FALSE,
                ],
              ],
              // The first code component gets a different component URL.
              1 => [
                'parents' => [...$path_to_deepest_level, '2f57ba57-f32a-4a7b-9896-9d1104b446f1', '#component', '#component_url'],
                'value' => '::SITE_DIR_BASE_URL::/files/astro-island/AEDMYeB1j39xcv3HMf-I_1dg7C1ZTcATXfNDFQw9V1I.js',
              ],
              // The second code component gets a different component URL.
              2 => [
                'parents' => [...$path_to_deepest_level, 'b4bc6c8f-66f7-458a-99a9-41c29b2801e7', '#component', '#component_url'],
                'value' => '::SITE_DIR_BASE_URL::/files/astro-island/AEDMYeB1j39xcv3HMf-I_1dg7C1ZTcATXfNDFQw9V1I.js',
              ],
            ],
          ),
          'expected_html' => <<<HTML
<div  data-component-id="xb_test_sdc:props-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;">Hello, world!</h1>
  <div class="component--props-slots--body">
        <div  data-component-id="xb_test_sdc:props-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;">Hello, from slot level 1!</h1>
  <div class="component--props-slots--body">
        <div  data-component-id="xb_test_sdc:props-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;">Hello, from slot level 2!</h1>
  <div class="component--props-slots--body">
        <div  data-component-id="xb_test_sdc:props-no-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;">Hello, from slot level 3!</h1>
</div>
<astro-island uid="68167e4a-9245-41be-b564-f1e1dcad1dec"
        component-url="::SITE_DIR_BASE_URL::/files/astro-island/5XdTAI4-WnaGx3DILNF0UUqh3wMJVmVaxxWHs5tg9QU.js"
        component-export="default"
        renderer-url="::XB_DIR_BASE_URL::/ui/lib/astro-hydration/dist/client.js"
        props="{&quot;homeUrl&quot;:[&quot;raw&quot;,&quot;\/&quot;],&quot;logo&quot;:[&quot;raw&quot;,&quot;&quot;],&quot;siteName&quot;:[&quot;raw&quot;,&quot;XB Test Site&quot;]}"
        ssr="" client="only"
        opts="{&quot;name&quot;:&quot;Site branding&quot;,&quot;value&quot;:&quot;preact&quot;}"><script type="module" src="::XB_DIR_BASE_URL::/ui/lib/astro-hydration/dist/client.js" blocking="render"></script><script type="module" src="::SITE_DIR_BASE_URL::/files/astro-island/5XdTAI4-WnaGx3DILNF0UUqh3wMJVmVaxxWHs5tg9QU.js" blocking="render"></script><template data-astro-template="siteSlogan">Experience Builder Test Site</template></astro-island><astro-island uid="2f57ba57-f32a-4a7b-9896-9d1104b446f1"
        component-url="::SITE_DIR_BASE_URL::/files/astro-island/AEDMYeB1j39xcv3HMf-I_1dg7C1ZTcATXfNDFQw9V1I.js"
        component-export="default"
        renderer-url="::XB_DIR_BASE_URL::/ui/lib/astro-hydration/dist/client.js"
        props="{&quot;text&quot;:[&quot;raw&quot;,&quot;Hello, from a \&quot;code component\&quot;!&quot;]}"
        ssr="" client="only"
        opts="{&quot;name&quot;:&quot;My First Code Component&quot;,&quot;value&quot;:&quot;preact&quot;}"><script type="module" src="::XB_DIR_BASE_URL::/ui/lib/astro-hydration/dist/client.js" blocking="render"></script><script type="module" src="::SITE_DIR_BASE_URL::/files/astro-island/AEDMYeB1j39xcv3HMf-I_1dg7C1ZTcATXfNDFQw9V1I.js" blocking="render"></script></astro-island><astro-island uid="b4bc6c8f-66f7-458a-99a9-41c29b2801e7"
        component-url="::SITE_DIR_BASE_URL::/files/astro-island/AEDMYeB1j39xcv3HMf-I_1dg7C1ZTcATXfNDFQw9V1I.js"
        component-export="default"
        renderer-url="::XB_DIR_BASE_URL::/ui/lib/astro-hydration/dist/client.js"
        props="{&quot;text&quot;:[&quot;raw&quot;,&quot;Hello, from a \&quot;auto-save code component\&quot;!&quot;]}"
        ssr="" client="only"
        opts="{&quot;name&quot;:&quot;My Code Component with Auto-Save&quot;,&quot;value&quot;:&quot;preact&quot;}"><script type="module" src="::XB_DIR_BASE_URL::/ui/lib/astro-hydration/dist/client.js" blocking="render"></script><script type="module" src="::SITE_DIR_BASE_URL::/files/astro-island/AEDMYeB1j39xcv3HMf-I_1dg7C1ZTcATXfNDFQw9V1I.js" blocking="render"></script></astro-island><div  data-component-id="xb_test_sdc:props-no-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;">Hello, from slot &lt;LAST ONE&gt;!</h1>
</div>

    </div>
  <div class="component--props-slots--footer">
        Example value for &lt;strong&gt;the_footer&lt;/strong&gt;.
    </div>
  <div class="component--props-slots--colophon">

    </div>
</div>

    </div>
  <div class="component--props-slots--footer">
        Example value for &lt;strong&gt;the_footer&lt;/strong&gt;.
    </div>
  <div class="component--props-slots--colophon">

    </div>
</div>

    </div>
  <div class="component--props-slots--footer">
        Example value for &lt;strong&gt;the_footer&lt;/strong&gt;.
    </div>
  <div class="component--props-slots--colophon">

    </div>
</div>

HTML,
          'expected_cache_tags' => [
            'config:experience_builder.component.sdc.xb_test_sdc.props-slots',
            'config:experience_builder.component.sdc.xb_test_sdc.props-no-slots',
            'config:experience_builder.js_component.my-cta-with-auto-save',
            'config:experience_builder.component.js.my-cta-with-auto-save',
            'config:experience_builder.js_component.my-cta',
            'config:experience_builder.component.js.my-cta',
            'config:experience_builder.js_component.site_branding',
            'config:system.site',
            'config:experience_builder.component.block.system_branding_block',
          ],
        ],

        "component tree with complex nesting in preview" => [
          ...self::overwriteRenderableExpectations(
            $original_test_case,
            [
              // The block component instance becomes a "block override" component instance.
              0 => [
                'parents' => [...$path_to_deepest_level, '68167e4a-9245-41be-b564-f1e1dcad1dec', '#component'],
                'value' => [
                  '#theme' => 'block__system_branding_block__as_js_component',
                  '#cache' => [
                    'tags' => [
                      'config:experience_builder.js_component.site_branding',
                      'config:system.site',
                      'config:experience_builder.component.block.system_branding_block',
                    ],
                  ],
                  '#js_component' => [JavaScriptComponent::class => 'site_branding'],
                  '#xb_preview' => TRUE,
                ],
              ],
              // The first code component gets a different component URL.
              1 => [
                'parents' => [...$path_to_deepest_level, '2f57ba57-f32a-4a7b-9896-9d1104b446f1', '#component', '#component_url'],
                'value' => '::SITE_DIR_BASE_URL::/files/astro-island/AEDMYeB1j39xcv3HMf-I_1dg7C1ZTcATXfNDFQw9V1I.js',
              ],
              // ⚠️ The second code component gets the auto-saved component URL!
              2 => [
                'parents' => [...$path_to_deepest_level, 'b4bc6c8f-66f7-458a-99a9-41c29b2801e7', '#component', '#component_url'],
                'value' => '/xb/api/v0/auto-saves/js/js_component/my-cta-with-auto-save',
              ],
            ],
          ),
          'expected_html' => <<<HTML
<!-- xb-start-41595148-e5c1-4873-b373-be3ae6e21340 --><div  data-component-id="xb_test_sdc:props-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;"><!-- xb-prop-start-41595148-e5c1-4873-b373-be3ae6e21340/heading -->Hello, world!<!-- xb-prop-end-41595148-e5c1-4873-b373-be3ae6e21340/heading --></h1>
  <div class="component--props-slots--body">
        <!-- xb-slot-start-41595148-e5c1-4873-b373-be3ae6e21340/the_body --><!-- xb-start-dfd2e899-6d88-46f8-b6aa-98929d1586dd --><div  data-component-id="xb_test_sdc:props-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;"><!-- xb-prop-start-dfd2e899-6d88-46f8-b6aa-98929d1586dd/heading -->Hello, from slot level 1!<!-- xb-prop-end-dfd2e899-6d88-46f8-b6aa-98929d1586dd/heading --></h1>
  <div class="component--props-slots--body">
        <!-- xb-slot-start-dfd2e899-6d88-46f8-b6aa-98929d1586dd/the_body --><!-- xb-start-e0b92f23-c177-4196-8fa4-3e837f99a357 --><div  data-component-id="xb_test_sdc:props-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;"><!-- xb-prop-start-e0b92f23-c177-4196-8fa4-3e837f99a357/heading -->Hello, from slot level 2!<!-- xb-prop-end-e0b92f23-c177-4196-8fa4-3e837f99a357/heading --></h1>
  <div class="component--props-slots--body">
        <!-- xb-slot-start-e0b92f23-c177-4196-8fa4-3e837f99a357/the_body --><!-- xb-start-81c63cac-187d-4f05-8acc-1c38fb2489d3 --><div  data-component-id="xb_test_sdc:props-no-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;"><!-- xb-prop-start-81c63cac-187d-4f05-8acc-1c38fb2489d3/heading -->Hello, from slot level 3!<!-- xb-prop-end-81c63cac-187d-4f05-8acc-1c38fb2489d3/heading --></h1>
</div>
<!-- xb-end-81c63cac-187d-4f05-8acc-1c38fb2489d3 --><!-- xb-start-68167e4a-9245-41be-b564-f1e1dcad1dec --><astro-island uid="68167e4a-9245-41be-b564-f1e1dcad1dec"
        component-url="::SITE_DIR_BASE_URL::/files/astro-island/5XdTAI4-WnaGx3DILNF0UUqh3wMJVmVaxxWHs5tg9QU.js"
        component-export="default"
        renderer-url="::XB_DIR_BASE_URL::/ui/lib/astro-hydration/dist/client.js"
        props="{&quot;homeUrl&quot;:[&quot;raw&quot;,&quot;\/&quot;],&quot;logo&quot;:[&quot;raw&quot;,&quot;&quot;],&quot;siteName&quot;:[&quot;raw&quot;,&quot;XB Test Site&quot;]}"
        ssr="" client="only"
        opts="{&quot;name&quot;:&quot;Site branding&quot;,&quot;value&quot;:&quot;preact&quot;}"><script type="module" src="::XB_DIR_BASE_URL::/ui/lib/astro-hydration/dist/client.js" blocking="render"></script><script type="module" src="::SITE_DIR_BASE_URL::/files/astro-island/5XdTAI4-WnaGx3DILNF0UUqh3wMJVmVaxxWHs5tg9QU.js" blocking="render"></script><template data-astro-template="siteSlogan"><!-- xb-prop-start-68167e4a-9245-41be-b564-f1e1dcad1dec/siteSlogan -->Experience Builder Test Site<!-- xb-prop-end-68167e4a-9245-41be-b564-f1e1dcad1dec/siteSlogan --></template></astro-island><!-- xb-end-68167e4a-9245-41be-b564-f1e1dcad1dec --><!-- xb-start-2f57ba57-f32a-4a7b-9896-9d1104b446f1 --><astro-island uid="2f57ba57-f32a-4a7b-9896-9d1104b446f1"
        component-url="::SITE_DIR_BASE_URL::/files/astro-island/AEDMYeB1j39xcv3HMf-I_1dg7C1ZTcATXfNDFQw9V1I.js"
        component-export="default"
        renderer-url="::XB_DIR_BASE_URL::/ui/lib/astro-hydration/dist/client.js"
        props="{&quot;text&quot;:[&quot;raw&quot;,&quot;Hello, from a \&quot;code component\&quot;!&quot;]}"
        ssr="" client="only"
        opts="{&quot;name&quot;:&quot;My First Code Component&quot;,&quot;value&quot;:&quot;preact&quot;}"><script type="module" src="::XB_DIR_BASE_URL::/ui/lib/astro-hydration/dist/client.js" blocking="render"></script><script type="module" src="::SITE_DIR_BASE_URL::/files/astro-island/AEDMYeB1j39xcv3HMf-I_1dg7C1ZTcATXfNDFQw9V1I.js" blocking="render"></script></astro-island><!-- xb-end-2f57ba57-f32a-4a7b-9896-9d1104b446f1 --><!-- xb-start-b4bc6c8f-66f7-458a-99a9-41c29b2801e7 --><astro-island uid="b4bc6c8f-66f7-458a-99a9-41c29b2801e7"
        component-url="/xb/api/v0/auto-saves/js/js_component/my-cta-with-auto-save"
        component-export="default"
        renderer-url="::XB_DIR_BASE_URL::/ui/lib/astro-hydration/dist/client.js"
        props="{&quot;text&quot;:[&quot;raw&quot;,&quot;Hello, from a \&quot;auto-save code component\&quot;!&quot;]}"
        ssr="" client="only"
        opts="{&quot;name&quot;:&quot;My Code Component with Auto-Save - Draft&quot;,&quot;value&quot;:&quot;preact&quot;}"><script type="module" src="::XB_DIR_BASE_URL::/ui/lib/astro-hydration/dist/client.js" blocking="render"></script><script type="module" src="/xb/api/v0/auto-saves/js/js_component/my-cta-with-auto-save" blocking="render"></script></astro-island><!-- xb-end-b4bc6c8f-66f7-458a-99a9-41c29b2801e7 --><!-- xb-start-9f09ecd8-ec65-408c-b5c8-ef036e6aeb97 --><div  data-component-id="xb_test_sdc:props-no-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;"><!-- xb-prop-start-9f09ecd8-ec65-408c-b5c8-ef036e6aeb97/heading -->Hello, from slot &lt;LAST ONE&gt;!<!-- xb-prop-end-9f09ecd8-ec65-408c-b5c8-ef036e6aeb97/heading --></h1>
</div>
<!-- xb-end-9f09ecd8-ec65-408c-b5c8-ef036e6aeb97 --><!-- xb-slot-end-e0b92f23-c177-4196-8fa4-3e837f99a357/the_body -->
    </div>
  <div class="component--props-slots--footer">
        <!-- xb-slot-start-e0b92f23-c177-4196-8fa4-3e837f99a357/the_footer --><div class="xb--slot-empty-placeholder"></div><!-- xb-slot-end-e0b92f23-c177-4196-8fa4-3e837f99a357/the_footer -->
    </div>
  <div class="component--props-slots--colophon">
        <!-- xb-slot-start-e0b92f23-c177-4196-8fa4-3e837f99a357/the_colophon --><div class="xb--slot-empty-placeholder"></div><!-- xb-slot-end-e0b92f23-c177-4196-8fa4-3e837f99a357/the_colophon -->
    </div>
</div>
<!-- xb-end-e0b92f23-c177-4196-8fa4-3e837f99a357 --><!-- xb-slot-end-dfd2e899-6d88-46f8-b6aa-98929d1586dd/the_body -->
    </div>
  <div class="component--props-slots--footer">
        <!-- xb-slot-start-dfd2e899-6d88-46f8-b6aa-98929d1586dd/the_footer --><div class="xb--slot-empty-placeholder"></div><!-- xb-slot-end-dfd2e899-6d88-46f8-b6aa-98929d1586dd/the_footer -->
    </div>
  <div class="component--props-slots--colophon">
        <!-- xb-slot-start-dfd2e899-6d88-46f8-b6aa-98929d1586dd/the_colophon --><div class="xb--slot-empty-placeholder"></div><!-- xb-slot-end-dfd2e899-6d88-46f8-b6aa-98929d1586dd/the_colophon -->
    </div>
</div>
<!-- xb-end-dfd2e899-6d88-46f8-b6aa-98929d1586dd --><!-- xb-slot-end-41595148-e5c1-4873-b373-be3ae6e21340/the_body -->
    </div>
  <div class="component--props-slots--footer">
        <!-- xb-slot-start-41595148-e5c1-4873-b373-be3ae6e21340/the_footer --><div class="xb--slot-empty-placeholder"></div><!-- xb-slot-end-41595148-e5c1-4873-b373-be3ae6e21340/the_footer -->
    </div>
  <div class="component--props-slots--colophon">
        <!-- xb-slot-start-41595148-e5c1-4873-b373-be3ae6e21340/the_colophon --><div class="xb--slot-empty-placeholder"></div><!-- xb-slot-end-41595148-e5c1-4873-b373-be3ae6e21340/the_colophon -->
    </div>
</div>
<!-- xb-end-41595148-e5c1-4873-b373-be3ae6e21340 -->
HTML,
          'expected_cache_tags' => [
            'config:experience_builder.component.sdc.xb_test_sdc.props-slots',
            'config:experience_builder.component.sdc.xb_test_sdc.props-no-slots',
            AutoSaveManager::CACHE_TAG,
            'config:experience_builder.js_component.my-cta-with-auto-save',
            'config:experience_builder.component.js.my-cta-with-auto-save',
            'config:experience_builder.js_component.my-cta',
            'config:experience_builder.component.js.my-cta',
            'config:experience_builder.js_component.site_branding',
            'config:system.site',
            'config:experience_builder.component.block.system_branding_block',
          ],
        ],

        default => $original_test_case,
      };

      yield $case_label => NestedArray::mergeDeepArray([$original_test_case, $expected_changes_vs_original], TRUE);
    }
  }

}
