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
        // Drupal keys errors by element parents joined with "][". A client
        // that received a flat schema should get a flat path back, and a
        // nested one should get a pointer it can map onto what it sent.
        'path' => '/' . \str_replace('][', '/', (string) $path),
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
