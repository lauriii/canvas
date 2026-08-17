<?php

declare(strict_types=1);

namespace Drupal\canvas_test_block_ajax_exception\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\EnforcedResponseException;
use Drupal\Core\Form\FormAjaxException;
use Drupal\Core\Form\FormState;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\HttpFoundation\Response;

/**
 * Simulates a form block (e.g. Webform) that throws during an Ajax submission.
 *
 * Mirrors how a form's lazy/pre_render callback throws a FormAjaxException (or
 * EnforcedResponseException) during rendering when an Ajax submission is in
 * progress. Because this happens while rendering the render array (and, when
 * placed in another component's slot, while rendering the parent component's
 * Twig template), Twig wraps the exception in a RuntimeError.
 *
 * The exception thrown is controlled by the
 * `canvas_test_block_ajax_form_exception` state value.
 *
 * @see \Drupal\Core\Form\FormBuilder::buildForm()
 * @see \Drupal\Tests\canvas\Kernel\AjaxFormInComponentSlotTest
 */
#[Block(
  id: self::PLUGIN_ID,
  admin_label: new TranslatableMarkup('Test Block that throws a FormAjaxException during rendering'),
)]
final class CanvasTestBlockAjaxFormException extends BlockBase implements TrustedCallbackInterface {

  public const string PLUGIN_ID = 'canvas_test_block_ajax_form_exception';

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    // The exception is thrown from a #pre_render callback (not from build())
    // so that it happens during rendering, exactly like a form built by a lazy
    // builder or a form element's #pre_render.
    return [
      'form' => [
        '#pre_render' => [[static::class, 'throwAjaxException']],
      ],
    ];
  }

  /**
   * Throws the control-flow exception core uses for an Ajax form submission.
   */
  public static function throwAjaxException(array $element): array {
    $type = \Drupal::state()->get(self::PLUGIN_ID, 'form_ajax');
    throw match ($type) {
      'enforced_response' => new EnforcedResponseException(new Response('Enforced.')),
      default => new FormAjaxException([], new FormState()),
    };
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['throwAjaxException'];
  }

}
