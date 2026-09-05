<?php

declare(strict_types=1);

namespace Drupal\multi_frontend\Form;

use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * The forms a site has chosen to expose.
 *
 * Opt-in, and deliberately not discovery. Drupal has hundreds of form
 * classes, and most of them are administrative: configuration forms, delete
 * confirmations, and bulk operations. Exposing a form class over HTTP because
 * it exists would hand a client a submit endpoint for every one of them, so a
 * module names the forms it means to publish and nothing else is reachable.
 *
 * This is the opposite of the choice made for components, where publication
 * follows from writing a producer. The asymmetry is intentional: producing
 * props is a read, and a form endpoint writes.
 */
final class FormRegistry {

  /**
   * @var array<string, array{class: string, label: string, permission: ?string}>|null
   */
  private ?array $forms = NULL;

  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Returns every exposed form definition, keyed by its public ID.
   *
   * @return array<string, array{class: string, label: string, permission: ?string}>
   *   The definitions.
   */
  public function all(): array {
    if ($this->forms !== NULL) {
      return $this->forms;
    }
    $forms = [];
    $this->moduleHandler->invokeAllWith(
      'multi_frontend_form_info',
      static function (callable $hook) use (&$forms): void {
        foreach ((array) $hook() as $id => $definition) {
          if (!\is_string($id) || !\is_array($definition) || !isset($definition['class'])) {
            continue;
          }
          $forms[$id] = $definition + ['label' => $id, 'permission' => NULL];
        }
      },
    );
    \ksort($forms);
    return $this->forms = $forms;
  }

  /**
   * Returns one exposed form definition, or NULL.
   *
   * @return array{class: string, label: string, permission: ?string}|null
   *   The definition.
   */
  public function get(string $id): ?array {
    return $this->all()[$id] ?? NULL;
  }

}
