<?php

declare(strict_types=1);

namespace Drupal\canvas_ai_test\EventSubscriber;

use Drupal\Component\Serialization\Json;
use Drupal\Core\State\StateInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Event subscriber to intercept Canvas AI API requests during tests.
 */
class CanvasAiRequestInterceptor implements EventSubscriberInterface {

  /**
   * The state key holding the hop count of every chat turn seen so far.
   */
  private const HOP_COUNT_STATE_KEY = 'canvas_ai_test.hop_count';

  public function __construct(
    private readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events[KernelEvents::REQUEST][] = ['onKernelRequest', 100];
    return $events;
  }

  /**
   * Intercepts Canvas AI API requests and returns fixture responses.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   */
  public function onKernelRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $request = $event->getRequest();
    $path = $request->getPathInfo();
    if (str_starts_with($path, '/admin/api/canvas/ai')) {
      $response = $this->getFixtureResponse($request);
      $event->setResponse($response);
    }
  }

  /**
   * Gets the appropriate fixture response based on request content.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The fixture response.
   */
  private function getFixtureResponse(Request $request): Response {
    $prompt = self::decodePrompt($request);
    $messages = \is_array($prompt['messages'] ?? NULL) ? $prompt['messages'] : [];
    $lastMessage = array_pop($messages);
    $user_message = \is_array($lastMessage) ? (string) ($lastMessage['text'] ?? '') : '';
    $user_message = strtolower($user_message);
    $user_message = preg_replace('/[^a-z0-9 ]/', '', $user_message) ?? '';
    $user_message = preg_replace('/\s+/', '_', $user_message) ?? '';
    $hop = $this->countHop((string) ($prompt['request_id'] ?? ''));
    return $this->loadFixtureResponse(self::resolveFixturePath($user_message, $hop));
  }

  /**
   * Decodes the request into the values the fixture is selected from.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return array
   *   The prompt, carrying an ordered 'messages' list.
   */
  private static function decodePrompt(Request $request): array {
    if ($request->getContentTypeFormat() === 'json') {
      $prompt = Json::decode($request->getContent());
      return \is_array($prompt) ? $prompt : [];
    }

    // @todo Add Playwright coverage for a message with an image attachment.
    // With attachments the client sends one 'message<number>' JSON string per
    // message instead of a 'messages' array.
    // @see \Drupal\canvas_dev_ai\Controller\CanvasDevAiBuilder::collectNumberedMessages()
    $prompt = $request->request->all();
    $messages = [];
    foreach ($prompt as $key => $value) {
      if (!\is_string($value) || preg_match('/^message(\d+)$/', (string) $key, $matches) !== 1) {
        continue;
      }
      $decoded = Json::decode($value);
      $messages[(int) $matches[1]] = \is_array($decoded) ? $decoded : [];
      unset($prompt[$key]);
    }
    ksort($messages);
    $prompt['messages'] = array_values($messages);
    return $prompt;
  }

  /**
   * Records that a chat turn made another request, and returns its hop number.
   *
   * The dev chat runs a turn as several requests under one request ID, each
   * re-POSTing the same messages. Counting them is what lets a turn be served a
   * different fixture per hop.
   *
   * @param string $request_id
   *   The request ID identifying the chat turn. Empty for the live wizard,
   *   which does not send one.
   *
   * @return int
   *   The 1-based hop number of this request within its turn.
   */
  private function countHop(string $request_id): int {
    if ($request_id === '') {
      return 1;
    }
    $counts = $this->state->get(self::HOP_COUNT_STATE_KEY, []);
    $counts = \is_array($counts) ? $counts : [];
    $hop = (int) ($counts[$request_id] ?? 0) + 1;
    $counts[$request_id] = $hop;
    $this->state->set(self::HOP_COUNT_STATE_KEY, $counts);
    return $hop;
  }

  /**
   * Resolves the fixture a given hop of a chat turn is answered with.
   *
   * A turn that hops is described by one file per hop: `<slug>.json`,
   * `<slug>-2.json`, and so on. Everything else keeps answering from
   * `<slug>.json`, which is what single-request turns rely on.
   *
   * @param string $slug
   *   The slugified user message.
   * @param int $hop
   *   The 1-based hop number.
   *
   * @return string
   *   Path to the fixture file.
   */
  private static function resolveFixturePath(string $slug, int $hop): string {
    $directory = dirname(__DIR__, 2) . '/fixtures/';
    $numbered = $directory . $slug . '-' . $hop . '.json';
    if ($hop > 1 && file_exists($numbered)) {
      return $numbered;
    }
    return $directory . $slug . '.json';
  }

  /**
   * Loads a fixture response from a file.
   *
   * @param string $fixture_path
   *   Path to the fixture file.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   */
  private function loadFixtureResponse(string $fixture_path): Response {
    $data = file_get_contents($fixture_path);
    if ($data === FALSE) {
      throw new \RuntimeException("Failed to read fixture file: $fixture_path");
    }
    $json_data = json_decode($data, TRUE);
    return new JsonResponse($json_data, 200);
  }

}
