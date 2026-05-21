<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel;

use Drupal\ai\OperationType\GenericType\ImageFile;
use Drupal\canvas_ai\CanvasAiChatHelper;
use Drupal\Core\Config\Schema\SchemaIncompleteException;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests for the CanvasAiChatHelper service.
 *
 * Verifies that getFilteredChatHistory() correctly trims conversation history
 * and that the special sentinel values (-1 = full history, 0 = no history)
 * behave as documented.
 */
#[Group('canvas_ai')]
final class CanvasAiChatHelperTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_ai',
    'ai',
    'ai_agents',
  ];

  /**
   * The CanvasAiChatHelper service under test.
   *
   * @var \Drupal\canvas_ai\CanvasAiChatHelper
   */
  protected CanvasAiChatHelper $canvasAiChatHelper;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['canvas_ai']);
    $this->canvasAiChatHelper = $this->container->get('canvas_ai.chat_helper');
  }

  /**
   * Verifies that the default max_messages value is 10.
   *
   * With 17 messages in history and a limit of 10, the returned
   * history should contain the most recent 10 messages.
   */
  public function testDefaultMaxMessagesIsApplied(): void {
    $this->assertSame(
      10,
      (int) $this->container->get('config.factory')->get('canvas_ai.settings')->get('chat_history_max_messages'),
    );

    $result = $this->canvasAiChatHelper->getFilteredChatHistory(
      $this->getDrupalConversationHistory(),
    );

    $this->assertCount(10, $result);
  }

  /**
   * Data provider for testChatHistoryRespectMaxMessages().
   */
  public static function maxMessagesProvider(): array {
    return [
      '0 returns no history (every request starts fresh)' => [0, 0],
      '5 returns the last 5 messages' => [5, 5],
      '10 returns the last 10 messages' => [10, 10],
      '15 returns the last 15 of 17 messages' => [15, 15],
      '25 returns all 17 messages (limit exceeds history length)' => [25, 17],
      '-1 returns the full history with no trimming' => [-1, 17],
    ];
  }

  /**
   * Tests that the returned history length matches the configured max.
   */
  #[DataProvider('maxMessagesProvider')]
  public function testChatHistoryRespectMaxMessages(int $max_messages, int $expected_count): void {
    $this->container->get('config.factory')
      ->getEditable('canvas_ai.settings')
      ->set('chat_history_max_messages', $max_messages)
      ->save();

    $result = $this->canvasAiChatHelper->getFilteredChatHistory(
      $this->getDrupalConversationHistory(),
    );

    $this->assertCount($expected_count, $result);
  }

  /**
   * Tests that setting chat_history_max_messages below -1 fails schema validation.
   */
  public function testMaxMessagesBelowMinimumFailsSchemaValidation(): void {
    $this->expectException(SchemaIncompleteException::class);
    $this->expectExceptionMessageMatches('/-1.*or more/');

    $this->container->get('config.factory')
      ->getEditable('canvas_ai.settings')
      ->set('chat_history_max_messages', -5)
      ->save();
  }

  /**
   * Verifies that the plugin returns the LAST N messages, not the first N.
   *
   * With max_messages set to 15 and a 17-message history, the returned messages
   * must be the final 15 entries of the original conversation, preserving the
   * most recent context for the agent.
   */
  public function testLastNMessagesAreReturnedNotFirstN(): void {
    $this->container->get('config.factory')
      ->getEditable('canvas_ai.settings')
      ->set('chat_history_max_messages', 15)
      ->save();

    $history = $this->getDrupalConversationHistory();
    $result = $this->canvasAiChatHelper->getFilteredChatHistory($history);

    $this->assertCount(15, $result);

    // Verify content and role mapping of the 15 returned messages against the
    // last 15 entries in the original raw history.
    $last_fifteen = array_slice($history, -15);
    foreach ($result as $index => $message) {
      $this->assertArrayHasKey('text', $last_fifteen[$index]);
      $expected_role = $last_fifteen[$index]['role'] === 'user' ? 'user' : 'assistant';
      $this->assertSame($last_fifteen[$index]['text'], $message->getText());
      $this->assertSame($expected_role, $message->getRole());
    }
  }

  /**
   * Tests the handling of image messages in the chat history.
   */
  public function testImageMessageInHistory(): void {
    $history = $this->getDrupalConversationHistory();

    // Confirm the source history contains an image at index 2.
    $this->assertArrayHasKey('files', $history[2]);

    // Set the history limit to 20.
    $this->container->get('config.factory')
      ->getEditable('canvas_ai.settings')
      ->set('chat_history_max_messages', 20)
      ->save();

    $result = $this->canvasAiChatHelper->getFilteredChatHistory($history);

    // The image message is at index 2 in the returned history.
    $images = $result[2]->getImages();
    $this->assertCount(1, $images);

    $image = $images[0];
    $this->assertInstanceOf(ImageFile::class, $image);
    $this->assertSame('image/png', $image->getMimeType());
    $this->assertSame(base64_decode('ZHJ1cGFs', TRUE), $image->getBinary());
  }

  /**
   * A 17-message conversation history about Drupal.
   *
   * @return array<int, array{role: string, text?: string, files?: array}>
   */
  protected function getDrupalConversationHistory(): array {
    return [
      ['role' => 'user', 'text' => 'What is Drupal?'],
      ['role' => 'ai', 'text' => 'Drupal is an open-source content management system written in PHP.'],
      [
        'role' => 'user',
        'text' => 'What is in this image?',
        'files' => [
          ['src' => 'data:image/png;base64,ZHJ1cGFs'],
        ],
      ],
      ['role' => 'ai', 'text' => "That's the Drupal logo — the water droplet icon known as Druplicon."],
      ['role' => 'user', 'text' => 'What is a Drupal module?'],
      ['role' => 'ai', 'text' => 'A Drupal module is a set of PHP files that extend Drupal functionality.'],
      ['role' => 'user', 'text' => 'What is a Drupal theme?'],
      ['role' => 'ai', 'text' => 'A Drupal theme controls the visual presentation of your website.'],
      ['role' => 'user', 'text' => 'What is the Drupal hook system?'],
      ['role' => 'ai', 'text' => 'Hooks allow modules to alter and extend the behavior of Drupal core and other modules.'],
      ['role' => 'user', 'text' => 'What is a Drupal entity?'],
      ['role' => 'ai', 'text' => 'A Drupal entity is a typed piece of data, such as a node, user, or taxonomy term.'],
      ['role' => 'user', 'text' => 'What is Drupal Canvas?'],
      ['role' => 'ai', 'text' => 'Drupal Canvas is an AI-powered visual page builder for Drupal that lets editors assemble and generate pages from reusable Single Directory Components through a drag-and-drop interface.'],
      ['role' => 'user', 'text' => 'What is a Drupal service?'],
      ['role' => 'ai', 'text' => 'A Drupal service is a PHP object registered in the dependency injection container.'],
      ['role' => 'user', 'text' => 'How do I create a custom block in Drupal?'],
    ];
  }

}
