<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Comment;

use Drupal\canvas\Entity\Comment;
use Drupal\canvas\Entity\CommentAccessControlHandler;
use Drupal\canvas\Entity\CommentThread;
use Drupal\canvas\Entity\CommentThreadAccessControlHandler;
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
#[CoversClass(CommentThreadAccessControlHandler::class)]
#[CoversClass(CommentAccessControlHandler::class)]
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
    // The `path` and `path_alias` modules are enabled by the base class, so
    // saving any entity resolves an alias and needs this table to exist.
    // @see \Drupal\Tests\canvas\Kernel\CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema(CommentThread::ENTITY_TYPE_ID);
    $this->installEntitySchema(Comment::ENTITY_TYPE_ID);
    // Claim uid 1, so that no test user below is the all-powerful super user.
    $this->createUser();
  }

  /**
   * Tests creating, reading, updating and deleting threads and comments.
   */
  public function testCrudRoundTrip(): void {
    $author = $this->createTestUser([CommentThread::CREATE_PERMISSION]);
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
    self::assertGreaterThan(0, $reloaded->getChangedTime());

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
   *
   * A blank body must never reach the storage layer nor raise an exception:
   * the HTTP API turns these violations into a 422.
   */
  public function testBlankBodyIsAConstraintViolation(): void {
    $author = $this->createTestUser([CommentThread::CREATE_PERMISSION]);
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
   * Tests that resolving and reopening a thread preserves its comments.
   */
  public function testResolveAndReopenRoundTrip(): void {
    $author = $this->createTestUser([CommentThread::CREATE_PERMISSION]);
    $thread = self::createThread($author);
    self::createComment($thread, $author, 'First.');
    self::createComment($thread, $author, 'Second.');

    $thread->resolve((int) $author->id(), 1690000000);
    self::assertEntityIsValid($thread);
    $thread->save();

    $storage = $this->container->get(EntityTypeManagerInterface::class)
      ->getStorage(CommentThread::ENTITY_TYPE_ID);
    $resolved = $storage->loadUnchanged((string) $thread->id());
    self::assertInstanceOf(CommentThread::class, $resolved);
    self::assertTrue($resolved->isResolved());
    self::assertSame((int) $author->id(), (int) $resolved->get('resolved_by')->target_id);
    self::assertSame('1690000000', (string) $resolved->get('resolved_at')->value);
    self::assertSame(['First.', 'Second.'], $this->getCommentBodies($thread));

    $resolved->reopen();
    self::assertEntityIsValid($resolved);
    $resolved->save();

    $reopened = $storage->loadUnchanged((string) $thread->id());
    self::assertInstanceOf(CommentThread::class, $reopened);
    self::assertFalse($reopened->isResolved());
    self::assertTrue($reopened->get('resolved_by')->isEmpty());
    self::assertTrue($reopened->get('resolved_at')->isEmpty());
    self::assertSame(['First.', 'Second.'], $this->getCommentBodies($thread));
  }

  /**
   * Tests entity access for each of the three comment permissions.
   */
  public function testAccessPerPermission(): void {
    $author = $this->createTestUser([
      CommentThread::VIEW_PERMISSION,
      CommentThread::CREATE_PERMISSION,
    ]);
    $thread = self::createThread($author);
    $comment = self::createComment($thread, $author, 'Mine.');

    $entity_type_manager = $this->container->get(EntityTypeManagerInterface::class);
    $thread_handler = $entity_type_manager->getAccessControlHandler(CommentThread::ENTITY_TYPE_ID);
    $comment_handler = $entity_type_manager->getAccessControlHandler(Comment::ENTITY_TYPE_ID);

    // Viewing is enough to read, but not to write.
    $viewer = $this->createTestUser([CommentThread::VIEW_PERMISSION]);
    self::assertTrue($thread->access('view', $viewer));
    self::assertTrue($comment->access('view', $viewer));
    self::assertFalse($thread_handler->createAccess(NULL, $viewer));
    self::assertFalse($comment_handler->createAccess(NULL, $viewer));
    self::assertFalse($thread->access('update', $viewer));
    self::assertFalse($comment->access('delete', $viewer));

    // Creating implies resolving: resolve and reopen are thread updates.
    self::assertTrue($thread_handler->createAccess(NULL, $author));
    self::assertTrue($comment_handler->createAccess(NULL, $author));
    self::assertTrue($thread->access('update', $author));
    self::assertTrue($comment->access('update', $author));
    self::assertFalse($thread->access('delete', $author));

    // A moderator may remove somebody else's message, but never rewrite it.
    $moderator = $this->createTestUser([
      CommentThread::VIEW_PERMISSION,
      CommentThread::CREATE_PERMISSION,
      CommentThread::MODERATE_PERMISSION,
    ]);
    self::assertFalse($comment->access('update', $moderator));
    self::assertTrue($comment->access('delete', $moderator));
    self::assertTrue($thread->access('delete', $moderator));
  }

  /**
   * Tests that comment access does not depend on surface edit access.
   *
   * This is the regression guard for the deferred comment-only role: holding
   * every comment permission must grant comment access without any Canvas Page
   * permission, and holding page edit access must grant no comment access at
   * all. Commenting and editing are independent in both directions.
   */
  public function testCommentAccessIsIndependentOfSurfaceEditAccess(): void {
    $author = $this->createTestUser([CommentThread::CREATE_PERMISSION]);
    $thread = self::createThread($author);
    $comment = self::createComment($thread, $author, 'Independent.');

    $entity_type_manager = $this->container->get(EntityTypeManagerInterface::class);
    $thread_handler = $entity_type_manager->getAccessControlHandler(CommentThread::ENTITY_TYPE_ID);
    $comment_handler = $entity_type_manager->getAccessControlHandler(Comment::ENTITY_TYPE_ID);

    $commenter = $this->createTestUser([
      CommentThread::VIEW_PERMISSION,
      CommentThread::CREATE_PERMISSION,
      CommentThread::MODERATE_PERMISSION,
    ]);
    self::assertFalse($commenter->hasPermission(Page::EDIT_PERMISSION));
    self::assertTrue($thread_handler->createAccess(NULL, $commenter));
    self::assertTrue($comment_handler->createAccess(NULL, $commenter));
    self::assertTrue($thread->access('view', $commenter));
    self::assertTrue($comment->access('view', $commenter));

    $page_editor = $this->createTestUser([Page::EDIT_PERMISSION]);
    self::assertTrue($page_editor->hasPermission(Page::EDIT_PERMISSION));
    self::assertFalse($thread_handler->createAccess(NULL, $page_editor));
    self::assertFalse($comment_handler->createAccess(NULL, $page_editor));
    self::assertFalse($thread->access('view', $page_editor));
    self::assertFalse($comment->access('view', $page_editor));
  }

  /**
   * Creates a user, failing the test when that is not possible.
   */
  private function createTestUser(array $permissions): UserInterface {
    $user = $this->createUser($permissions);
    self::assertInstanceOf(UserInterface::class, $user);
    return $user;
  }

  /**
   * Gets the bodies of a thread's comments, oldest first.
   */
  private function getCommentBodies(CommentThread $thread): array {
    $storage = $this->container->get(EntityTypeManagerInterface::class)
      ->getStorage(Comment::ENTITY_TYPE_ID);
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('thread', $thread->id())
      ->sort('id')
      ->execute();
    $bodies = [];
    foreach ($storage->loadMultiple($ids) as $comment) {
      self::assertInstanceOf(Comment::class, $comment);
      $bodies[] = $comment->getBody();
    }
    return $bodies;
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
