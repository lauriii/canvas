<?php

declare(strict_types=1);

namespace Drupal\multi_frontend\Form;

use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;

/**
 * Submits a form from values a client sent, and reports violations.
 *
 * Uses core's programmatic submission, which runs the form's own validation
 * and submit handlers. That matters: validation is not re-implemented here or
 * pushed onto the client, so a form's rules hold no matter who is posting.
 *
 * Programmatic submission deliberately skips the form token, because there is
 * no build to bind one to. The route supplies CSRF protection instead, using
 * the X-CSRF-Token header check core already ships for REST. The client gets
 * that token from core's existing /session/token endpoint, so there is no new
 * token concept to learn.
 *
 * @see \Drupal\Core\Form\FormBuilder::submitForm()
 * @see \Drupal\Core\Access\CsrfRequestHeaderAccessCheck
 */
final class FormSubmitter {

  public function __construct(
    private readonly FormBuilderInterface $formBuilder,
    private readonly MessengerInterface $messenger,
    private readonly FormDescriber $describer,
  ) {}

  /**
   * Submits a form.
   *
   * @param class-string|string $form_arg
   *   The form class or service id.
   * @param array<string, mixed> $values
   *   The submitted values.
   *
   * @return array{status: string, violations: array<int, array{path: string, message: string}>, messages: string[]}
   *   The outcome.
   */
  public function submit(string $form_arg, array $values): array {
    // The published schema says additionalProperties: false, and that has to
    // be enforced here to be true. FormState::setValues() seeds every key
    // into form state and core copies them to user input without removing
    // unknown ones, so a submit handler could read a property the contract
    // never advertised. Reject rather than strip: silently dropping a value a
    // client believed it sent is how a contract loses trust.
    $allowed = \array_keys((array) $this->describer->describe($form_arg)['schema']['properties']);
    $unknown = \array_diff(\array_keys($values), $allowed);
    if ($unknown !== []) {
      return [
        'status' => 'invalid',
        'violations' => \array_values(\array_map(
          static fn (string $name): array => [
            'path' => '/' . self::escapePointerSegment($name),
            'message' => 'This property is not part of the form contract.',
          ],
          $unknown,
        )),
        'messages' => [],
      ];
    }

    $form_state = new FormState();
    $form_state->setValues($values);
    // Core defaults programmatic submission to bypassing element access,
    // because it assumes the caller is trusted PHP. This caller is an HTTP
    // client, so the default would let anyone submit values for elements
    // hidden behind #access, and FormBuilder's own comment on that branch is
    // that such submissions "may bypass access restriction and be treated as
    // high-privilege users instead".
    // @see \Drupal\Core\Form\FormBuilder::doBuildForm()
    $form_state->setProgrammedBypassAccessCheck(FALSE);
    $this->formBuilder->submitForm($form_arg, $form_state);

    $violations = [];
    foreach ($form_state->getErrors() as $path => $message) {
      $violations[] = [
        'path' => self::pointerFor((string) $path),
        'message' => \strip_tags((string) $message),
      ];
    }

    // Drain unconditionally. A failed submission can still have put messages
    // on the messenger, and leaving them there lets them surface somewhere
    // they were never meant to -- which is the failure webform_jsonschema
    // records as "Drupal messages are shown on drupal frontend on validation
    // errors". They are dropped rather than returned, because the violations
    // are the response to a failure.
    $messages = $this->drainMessages();

    return [
      'status' => $violations === [] ? 'ok' : 'invalid',
      'violations' => $violations,
      'messages' => $violations === [] ? $messages : [],
    ];
  }

  /**
   * Turns a Drupal error key into a JSON Pointer into the submitted values.
   *
   * Drupal keys errors by element parents joined with "][", which is a path
   * through the *form*, while the published schema is a flat map of values.
   * The value a client sent lives under the element's own name, so the last
   * segment is the one that points into its payload; anything else would hand
   * back a pointer that resolves to nothing.
   */
  private static function pointerFor(string $path): string {
    if ($path === '') {
      // A form-level error belongs to the whole document, and RFC 6901 spells
      // that as the empty string, not "/".
      return '';
    }
    $segments = \explode('][', $path);
    return '/' . self::escapePointerSegment((string) \end($segments));
  }

  /**
   * Escapes one JSON Pointer segment.
   *
   * RFC 6901: "~" becomes "~0" and "/" becomes "~1", in that order. Element
   * names can contain both, and an unescaped one silently targets a different
   * property.
   */
  private static function escapePointerSegment(string $segment): string {
    return \str_replace(['~', '/'], ['~0', '~1'], $segment);
  }

  /**
   * Takes any status messages the submit handlers set.
   *
   * @return string[]
   *   The messages.
   */
  private function drainMessages(): array {
    $out = [];
    foreach ($this->messenger->deleteAll() as $messages) {
      foreach ($messages as $message) {
        $out[] = \strip_tags((string) $message);
      }
    }
    return $out;
  }

}
