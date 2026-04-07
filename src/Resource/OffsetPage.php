<?php

declare(strict_types=1);

namespace Drupal\canvas\Resource;

use Symfony\Component\HttpFoundation\Request;

/**
 * Value object for the page[offset] and page[limit] query parameters.
 *
 * @see https://jsonapi.org/format/#fetching-pagination
 */
final class OffsetPage {

  const string KEY_NAME = 'page';
  const string OFFSET_KEY = 'offset';
  const string LIMIT_KEY = 'limit';
  const int DEFAULT_OFFSET = 0;
  const int MAX_SIZE = 50;

  public function __construct(
    private readonly int $offset,
    private readonly int $limit,
  ) {}

  public function getOffset(): int {
    return $this->offset;
  }

  public function getLimit(): int {
    return $this->limit;
  }

  public static function createFromRequest(Request $request): self {
    $page_param = $request->query->all(self::KEY_NAME);
    $offset = max(0, (int) ($page_param[self::OFFSET_KEY] ?? self::DEFAULT_OFFSET));
    $limit = isset($page_param[self::LIMIT_KEY])
      ? min(self::MAX_SIZE, max(1, (int) $page_param[self::LIMIT_KEY]))
      : self::MAX_SIZE;
    return new self($offset, $limit);
  }

}
