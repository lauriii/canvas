<?php

declare(strict_types=1);

namespace Drupal\canvas\Workspace;

/**
 * Thrown when an account lacks the permission for a review transition.
 *
 * @see \Drupal\canvas\Workspace\WorkspaceReview::transition()
 */
final class WorkspaceReviewAccessException extends \RuntimeException {
}
