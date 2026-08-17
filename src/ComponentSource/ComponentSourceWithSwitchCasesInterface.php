<?php

declare(strict_types=1);

namespace Drupal\canvas\ComponentSource;

/**
 * @internal
 *
 * Defines an interface for component sources that support switch-cases.
 *
 *  ⚠️ This is highly experimental and *will* be refactored or even removed.
 *
 * @see https://www.drupal.org/i/3525746#comment-16121437
 */
interface ComponentSourceWithSwitchCasesInterface extends ComponentSourceInterface {

  public const string SWITCH = 'switch';

  public const string CASE = 'case';

  public function isCase(): bool;

  public function isSwitch(): bool;

  /**
   * Negotiates which case of a hydrated switch component instance applies.
   *
   * Called once per switch during live rendering, with the hydrated switch
   * instance — including all of its hydrated cases in its slots — so the
   * negotiation can honor priority order across cases. The returned
   * cacheability MUST cover the entire decision (every input consulted, or
   * that could be consulted under a different request context), no matter
   * which case won: the rendering pipeline attaches it to the switch's render
   * element and prunes all non-negotiated cases.
   *
   * Only called on live renders and only on switches; previews render all
   * cases without negotiation.
   *
   * @see \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList::renderify()
   */
  public function negotiateCases(array $switch_instance): SwitchCaseNegotiation;

}
