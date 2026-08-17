<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\Entity\Page;
use Drupal\Core\Form\EnforcedResponseException;
use Drupal\Core\Form\FormAjaxException;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that Ajax form control-flow exceptions bubble up out of slots.
 *
 * When a form (e.g. a Webform block) is placed in another component's slot and
 * submitted via Ajax, core throws a FormAjaxException (or an
 * EnforcedResponseException) during rendering. Twig wraps that exception in a
 * RuntimeError while rendering the parent component's template. Canvas must
 * unwrap and rethrow it so that core's FormAjaxSubscriber can build the Ajax
 * response, instead of swallowing it into a "component failed to render"
 * fallback.
 *
 * @see \Drupal\canvas\Element\RenderSafeComponentContainer::renderComponent()
 * @see \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList::renderify()
 * @see https://www.drupal.org/i/3553957
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class AjaxFormInComponentSlotTest extends CanvasKernelTestBase {

  use GenerateComponentConfigTrait;

  private const string PARENT_UUID = '11111111-1111-4111-8111-111111111111';
  private const string CHILD_UUID = '22222222-2222-4222-8222-222222222222';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_test_block_ajax_exception',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $this->generateComponentConfig();
  }

  /**
   * @return array<string, array{class-string<\Throwable>, string}>
   */
  public static function providerAjaxException(): array {
    return [
      'FormAjaxException' => [FormAjaxException::class, 'form_ajax'],
      'EnforcedResponseException' => [EnforcedResponseException::class, 'enforced_response'],
    ];
  }

  /**
   * @param class-string<\Throwable> $expected_exception
   *   The exception that must bubble up out of the render.
   * @param string $exception_type
   *   The exception type the test block should throw.
   */
  #[DataProvider('providerAjaxException')]
  public function testAjaxExceptionBubblesUpFromSlot(string $expected_exception, string $exception_type): void {
    \Drupal::state()->set('canvas_test_block_ajax_form_exception', $exception_type);

    $page = Page::create([
      'title' => 'Test page',
      'components' => [
        [
          'uuid' => self::PARENT_UUID,
          'component_id' => 'sdc.canvas_test_sdc.props-slots',
          // Static prop sources must be stored in their collapsed form: just
          // the value.
          'inputs' => [
            'heading' => 'Parent component',
          ],
        ],
        [
          'uuid' => self::CHILD_UUID,
          'parent_uuid' => self::PARENT_UUID,
          'slot' => 'the_body',
          'component_id' => 'block.canvas_test_block_ajax_form_exception',
          'inputs' => [],
        ],
      ],
    ]);
    self::assertEntityIsValid($page);
    $page->save();

    $renderable = $page->getComponentTree()->toRenderable($page, FALSE);

    // The Ajax control-flow exception must bubble up out of the render so that
    // core's FormAjaxSubscriber can build the Ajax response. Before the fix it
    // was swallowed into a fallback, so no exception was thrown here.
    $this->expectException($expected_exception);
    $renderer = $this->container->get(RendererInterface::class);
    \assert($renderer instanceof RendererInterface);
    $renderer->executeInRenderContext(
      new RenderContext(),
      static fn () => $renderer->render($renderable),
    );
  }

}
