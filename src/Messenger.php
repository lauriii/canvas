<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\RouteMatchInterface;

/**
 * Decorates messenger to suppress messages on most Canvas API routes.
 *
 * Layout preview routes are an exception: messages are kept and normalized to
 * admin-safe HTML so the layout preview response can include them in JSON.
 */
readonly class Messenger implements MessengerInterface {

  public function __construct(
    private MessengerInterface $messenger,
    private RouteMatchInterface $currentRouteMatch,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function addMessage($message, $type = MessengerInterface::TYPE_STATUS, $repeat = FALSE): MessengerInterface {
    $routeName = $this->currentRouteMatch->getRouteName();
    if (!\is_string($routeName)
      || !str_starts_with($routeName, 'canvas.api.')
      || $this->isCurrentRouteLayoutPreview()) {
      $this->messenger->addMessage($message, $type, $repeat);
    }
    return $this;
  }

  /**
   * Whether the current route should expose normalized preview message HTML.
   */
  private function isCurrentRouteLayoutPreview(): bool {
    $routeName = $this->currentRouteMatch->getRouteName();
    return \is_string($routeName) && str_starts_with($routeName, 'canvas.api.layout');
  }

  /**
   * {@inheritdoc}
   */
  public function addStatus($message, $repeat = FALSE): MessengerInterface {
    return $this->addMessage($message, static::TYPE_STATUS, $repeat);
  }

  /**
   * {@inheritdoc}
   */
  public function addError($message, $repeat = FALSE): MessengerInterface {
    return $this->addMessage($message, static::TYPE_ERROR, $repeat);
  }

  /**
   * {@inheritdoc}
   */
  public function addWarning($message, $repeat = FALSE): MessengerInterface {
    return $this->addMessage($message, static::TYPE_WARNING, $repeat);
  }

  /**
   * {@inheritdoc}
   */
  public function all(): array {
    $all = $this->messenger->all();
    if (!$this->isCurrentRouteLayoutPreview()) {
      return $all;
    }
    return $this->normalizePreviewGrouped($all);
  }

  /**
   * {@inheritdoc}
   */
  public function messagesByType($type): array {
    $messages = $this->messenger->messagesByType($type);
    if (!$this->isCurrentRouteLayoutPreview()) {
      return $messages;
    }
    return $this->normalizePreviewMessageList($messages);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAll(): array {
    $all = $this->messenger->deleteAll();
    if (!$this->isCurrentRouteLayoutPreview()) {
      return $all;
    }
    return $this->normalizePreviewGrouped($all);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteByType($type): array {
    $messages = $this->messenger->deleteByType($type);
    if (!$this->isCurrentRouteLayoutPreview()) {
      return $messages;
    }
    return $this->normalizePreviewMessageList($messages);
  }

  /**
   * @param array $all
   *   Keys are message types; values are lists of messages.
   */
  private function normalizePreviewGrouped(array $all): array {
    $out = [];
    foreach ($all as $type => $messages) {
      $out[$type] = $this->normalizePreviewMessageList($messages);
    }
    return $out;
  }

  /**
   * @param array $messages
   *   Messages for a single type.
   */
  private function normalizePreviewMessageList(array $messages): array {
    $normalized = [];
    foreach ($messages as $message) {
      $normalized[] = $this->messageToPreviewHtml($message);
    }
    return \array_values(\array_filter($normalized));
  }

  /**
   * Normalizes a messenger item to admin-safe HTML.
   */
  private function messageToPreviewHtml(mixed $message): string {
    return trim(Xss::filterAdmin((string) $message));
  }

}
