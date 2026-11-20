<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Comment;

use Drupal\canvas\Entity\Comment;
use Drupal\canvas\Entity\CommentThread;
use Drupal\canvas\Entity\Page;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Tests the comment thread and comment entity types.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('canvas_comments')]
#[CoversClass(CommentThread::class)]
#[CoversClass(Comment::class)]
final class CommentEntityTest extends CanvasKernelTestBase {

  use UserCreationTrait;

  /**
   * A component instance UUID to anchor threads to.
   */
  private const string COMPONENT_UUID = '16176e0b-8197-40e3-ad49-48f1b6e9a7f9';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    // Saving any entity resolves an alias.
    // @see \Drupal\Tests\canvas\Kernel\CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema(CommentThread::ENTITY_TYPE_ID);
    $this->installEntitySchema(Comment::ENTITY_TYPE_ID);
  }

  /**
   * Tests creating, reading, updating and deleting threads and comments.
   */
  public function testCrudRoundTrip(): void {
    $author = $this->createTestUser();
    $thread = self::createThread($author);
    $comment = self::createComment($thread, $author, 'Should this be a heading?');

    $storage = $this->container->get(EntityTypeManagerInterface::class)
      ->getStorage(CommentThread::ENTITY_TYPE_ID);
    $reloaded = $storage->loadUnchanged((string) $thread->id());
    self::assertInstanceOf(CommentThread::class, $reloaded);
    self::assertSame(Page::ENTITY_TYPE_ID, $reloaded->getSurfaceType());
    self::assertSame('1', $reloaded->getSurfaceId());
    self::assertSame(self::COMPONENT_UUID, $reloaded->getComponentUuid());
    self::assertFalse($reloaded->isResolved());
    self::assertSame((int) $author->id(), (int) $reloaded->getOwnerId());
    self::assertGreaterThan(0, $reloaded->getCreatedTime());

    // A thread without a component instance UUID is anchored to the surface.
    $surface_thread = CommentThread::create([
      'surface_type' => Page::ENTITY_TYPE_ID,
      'surface_id' => '1',
      'uid' => $author->id(),
    ]);
    self::assertEntityIsValid($surface_thread);
    $surface_thread->save();
    self::assertNull($surface_thread->getComponentUuid());

    // Update.
    $comment->set('body', 'Should this be a heading, or a paragraph?');
    self::assertEntityIsValid($comment);
    $comment->save();
    $comment_storage = $this->container->get(EntityTypeManagerInterface::class)
      ->getStorage(Comment::ENTITY_TYPE_ID);
    $reloaded_comment = $comment_storage->loadUnchanged((string) $comment->id());
    self::assertInstanceOf(Comment::class, $reloaded_comment);
    self::assertSame('Should this be a heading, or a paragraph?', $reloaded_comment->getBody());

    // Delete.
    $comment->delete();
    self::assertNull($comment_storage->loadUnchanged((string) $comment->id()));
    $thread->delete();
    self::assertNull($storage->loadUnchanged((string) $thread->id()));
  }

  /**
   * Tests that a missing or blank comment body is a constraint violation.
   */
  public function testBlankBodyIsAConstraintViolation(): void {
    $author = $this->createTestUser();
    $thread = self::createThread($author);

    $comment = Comment::create([
      'thread' => $thread->id(),
      'uid' => $author->id(),
    ]);
    self::assertViolatesBodyOnly($comment->validate());

    $comment->set('body', '');
    self::assertViolatesBodyOnly($comment->validate());

    $comment->set('body', 'Not blank.');
    self::assertEntityIsValid($comment);
  }

  /**
   * Creates a user, failing the test when that is not possible.
   */
  private function createTestUser(): UserInterface {
    $user = $this->createUser();
    self::assertInstanceOf(UserInterface::class, $user);
    return $user;
  }

  /**
   * Creates and saves a thread anchored to a component instance.
   */
  private static function createThread(UserInterface $author): CommentThread {
    $thread = CommentThread::create([
      'surface_type' => Page::ENTITY_TYPE_ID,
      'surface_id' => '1',
      'component_uuid' => self::COMPONENT_UUID,
      'uid' => $author->id(),
    ]);
    self::assertEntityIsValid($thread);
    $thread->save();
    return $thread;
  }

  /**
   * Creates and saves a comment in a thread.
   */
  private static function createComment(CommentThread $thread, UserInterface $author, string $body): Comment {
    $comment = Comment::create([
      'thread' => $thread->id(),
      'body' => $body,
      'uid' => $author->id(),
    ]);
    self::assertEntityIsValid($comment);
    $comment->save();
    return $comment;
  }

  /**
   * Asserts that a violation list is non-empty and only about the body.
   */
  private static function assertViolatesBodyOnly(ConstraintViolationListInterface $violations): void {
    self::assertGreaterThan(0, $violations->count());
    foreach ($violations as $violation) {
      self::assertStringStartsWith('body', $violation->getPropertyPath());
    }
  }

}
