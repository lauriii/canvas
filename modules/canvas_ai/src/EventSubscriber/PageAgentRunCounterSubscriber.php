<?php

declare(strict_types=1);

namespace Drupal\canvas_ai\EventSubscriber;

use Drupal\ai_agents\Event\BuildSystemPromptEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Removes the run counter from the drupal_canvas_page_agent system prompt.
 *
 * The ai_agents module adds "This is the first|second|third|Nth time that this
 * agent has been run." to the system prompt, and the ordinal changes on every
 * loop. Providers cache requests on an exact prefix, so the counter ends the
 * cached prefix and the component catalog and chat history after it are sent
 * uncached on every loop.
 *
 * @see \Drupal\ai_agents\PluginBase\AiAgentEntityWrapper::getDefaultInformationTools()
 * @todo Remove once the required drupal/ai_agents release includes https://git.drupalcode.org/project/ai_agents/-/work_items/3586083
 *
 * @internal
 */
final class PageAgentRunCounterSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      BuildSystemPromptEvent::EVENT_NAME => 'onBuildSystemPrompt',
    ];
  }

  /**
   * Removes the run counter sentence from the page agent's system prompt.
   *
   * @param \Drupal\ai_agents\Event\BuildSystemPromptEvent $event
   *   The event.
   */
  public static function onBuildSystemPrompt(BuildSystemPromptEvent $event): void {
    if ($event->getAgentId() !== 'drupal_canvas_page_agent') {
      return;
    }
    $system_prompt = $event->getSystemPrompt();
    $event->setSystemPrompt(\preg_replace(
      '/This is the (?:first|second|third|\d+th) time that this agent has been run\. \n/',
      '',
      $system_prompt,
    ) ?? $system_prompt);
  }

}
