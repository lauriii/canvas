<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Unit\EventSubscriber;

use Drupal\ai_agents\Event\BuildSystemPromptEvent;
use Drupal\canvas_ai\EventSubscriber\PageAgentRunCounterSubscriber;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the run counter is removed from the page agent's system prompt only.
 */
#[CoversClass(PageAgentRunCounterSubscriber::class)]
#[Group('canvas_ai')]
final class PageAgentRunCounterSubscriberTest extends UnitTestCase {

  /**
   * Tests the page agent's system prompt is the same on every loop.
   */
  #[DataProvider('providerOrdinals')]
  public function testRunCounterIsRemoved(string $ordinal): void {
    $event = self::dispatch('drupal_canvas_page_agent', $ordinal);

    self::assertSame("Agent instructions\n\nComponent catalog", $event->getSystemPrompt());
  }

  /**
   * Tests other agents keep the run counter.
   */
  public function testOtherAgentIsLeftAlone(): void {
    $event = self::dispatch('canvas_agent', 'second');

    self::assertSame("Agent instructions\n\nThis is the second time that this agent has been run. \nComponent catalog", $event->getSystemPrompt());
  }

  /**
   * Provides the ordinals ai_agents writes into the run counter.
   *
   * @return array<string, array{string}>
   *   Test cases, keyed by ordinal.
   *
   * @see \Drupal\ai_agents\PluginBase\AiAgentEntityWrapper::getDefaultInformationTools()
   */
  public static function providerOrdinals(): array {
    return [
      'first' => ['first'],
      'second' => ['second'],
      'third' => ['third'],
      '12th' => ['12th'],
    ];
  }

  /**
   * Passes a system prompt with the run counter to the subscriber.
   *
   * @param string $agent_id
   *   The ID of the agent the system prompt is built for.
   * @param string $ordinal
   *   The ordinal in the run counter.
   *
   * @return \Drupal\ai_agents\Event\BuildSystemPromptEvent
   *   The event, after the subscriber handled it.
   */
  private static function dispatch(string $agent_id, string $ordinal): BuildSystemPromptEvent {
    $event = new BuildSystemPromptEvent("Agent instructions\n\nThis is the $ordinal time that this agent has been run. \nComponent catalog", $agent_id, []);
    PageAgentRunCounterSubscriber::onBuildSystemPrompt($event);
    return $event;
  }

}
