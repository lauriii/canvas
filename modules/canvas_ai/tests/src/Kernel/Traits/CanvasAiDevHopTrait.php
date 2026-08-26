<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel\Traits;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Session\SessionConfigurationInterface;
use Drupal\Core\Session\SessionManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Drives chat turns through the canvas_dev_ai controller, one hop per request.
 *
 * The controller runs the agent with setLooped(FALSE), so one request is one
 * hop: it returns should_continue while the agent still has work parked, and
 * the frontend sends the next hop. ::driveTurn() plays that caller.
 *
 * Requires \Drupal\Tests\canvas\Kernel\Traits\RequestTrait.
 *
 * @see \Drupal\canvas_dev_ai\Controller\CanvasDevAiBuilder::render()
 */
trait CanvasAiDevHopTrait {

  /**
   * The controller path every hop is sent to.
   */
  private const AI_DEV_PATH = '/admin/api/canvas/ai-dev';

  /**
   * The number of hops one turn may take before the test gives up.
   */
  private const MAX_HOPS = 20;

  /**
   * Prepares the session state the hops and their progress narration need.
   *
   * Call from setUp().
   *
   * @see \Drupal\ai_agents\Service\AgentStatus\Storages\PrivateTempStatusStorage
   */
  protected function setUpAiDevHops(): void {
    // Starting the session keeps the CSRF token seed stable across the request.
    $this->container->get('session')->start();
    // The ai_agents status storage, which records the progress narration the
    // agent emits while it works ("I am creating the Red Button component now."),
    // skips every write while the session manager reports not started — which it
    // always does under CLI.
    $session_manager = $this->createMock(SessionManagerInterface::class);
    $session_manager->method('isStarted')->willReturn(TRUE);
    $this->container->set('session_manager', $session_manager);
  }

  /**
   * Sends hops until the controller reports the turn is over.
   *
   * @param array $prompt
   *   The prompt values the first hop sends, merged over the required keys.
   *
   * @return array
   *   The decoded response of every hop, in order.
   */
  protected function driveTurn(array $prompt): array {
    $responses = [];
    do {
      $response = $this->hop($prompt);
      $responses[] = $response;
      self::assertLessThan(self::MAX_HOPS, \count($responses), 'The turn ended within the hop ceiling.');
    } while (!empty($response['should_continue']));
    return $responses;
  }

  /**
   * Sends one hop to the controller and returns the decoded response.
   *
   * Every hop of one turn sends the same request_id, which the controller keys
   * the stored agent state on: a later hop resumes the run the previous one
   * parked.
   *
   * @param array $prompt
   *   The prompt values this hop sends, merged over the required keys.
   *
   * @return array
   *   The decoded JSON response.
   */
  protected function hop(array $prompt): array {
    $request = Request::create(
      self::AI_DEV_PATH,
      'POST',
      content: Json::encode($prompt + [
        'request_id' => 'test-request',
        'entity_type' => 'canvas_page',
        'derived_proptypes' => [],
        'selected_component_required_props' => [],
      ]),
      server: ['CONTENT_TYPE' => 'application/json'],
    );
    // The token is bound to the session, so the request needs a session cookie
    // for it to validate while the request is handled.
    $session_options = $this->container->get(SessionConfigurationInterface::class)->getOptions($request);
    $request->cookies->set($session_options['name'], 'ABCD');
    $request->headers->set(
      'X-CSRF-Token',
      $this->container->get(CsrfTokenGenerator::class)->get('canvas_ai.canvas_builder'),
    );

    $content = $this->request($request)->getContent();
    self::assertIsString($content);
    return Json::decode($content);
  }

}
