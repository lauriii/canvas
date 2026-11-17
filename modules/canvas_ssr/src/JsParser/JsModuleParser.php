<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\JsParser;

use Drupal\canvas_ssr\Timeouts;
use Drupal\Component\Utility\Bytes;
use Peast\Peast;
use Peast\Syntax\Node\ExportAllDeclaration;
use Peast\Syntax\Node\ExportNamedDeclaration;
use Peast\Syntax\Node\ExpressionStatement;
use Peast\Syntax\Node\ImportDeclaration;
use Peast\Syntax\Node\ImportExpression;
use Peast\Syntax\Node\Literal;
use Peast\Syntax\Node\StringLiteral;
use Peast\Syntax\Node\TemplateLiteral;
use Psr\Log\LoggerInterface;

/**
 * Parses JS modules into ParsedModules.
 *
 * Results are kept for the request, since the boundary check and the
 * import rewriting both parse the same components. ParsedModuleCache keeps
 * them between requests too.
 *
 * Peast parses recursively, so deeply nested code can use a lot of memory:
 * about 10 KiB a level for brackets. PHP running out of memory is a fatal
 * error, which nothing could catch. So a parse that uses more than
 * MEMORY_LIMIT, or takes longer than the medium time limit, is given up on
 * and the source counts as unparsable. One that would leave the request
 * less than MEMORY_MARGIN of PHP's memory_limit is given up on too, but
 * only for the request, since that depends on what else the request did.
 * Real components use far less: most a few MiB, and the heaviest seen
 * about 20 MiB, for about 400 KB of JS that's mostly one inline image.
 */
final class JsModuleParser {

  /**
   * The most memory a parse may use, in bytes.
   */
  public const int MEMORY_LIMIT = 32 * 1024 * 1024;

  /**
   * How much of PHP's memory_limit a parse leaves the request, in bytes.
   */
  public const int MEMORY_MARGIN = 8 * 1024 * 1024;

  /**
   * How many tokens are consumed between checks of the limits.
   *
   * Memory grows by a bounded amount per token, so this keeps the overshoot
   * small, at little cost.
   */
  private const int TOKENS_PER_CHECK = 64;

  /**
   * Parse results keyed by source. NULL means the parse failed.
   *
   * Keyed by the source itself, not a hash, so nothing can collide. PHP
   * shares the string rather than copying it, and keeps its hash.
   *
   * @var array<string, \Drupal\canvas_ssr\JsParser\ParsedModule|null>
   */
  private array $modules = [];

  /**
   * Sources whose parse gave up this request for a reason that may pass.
   *
   * @var array<string, true>
   *
   * @see self::gaveUpForNow()
   */
  private array $gaveUp = [];

  /**
   * The time limits.
   */
  private readonly Timeouts $timeouts;

  /**
   * Constructs a JsModuleParser.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   Logs sources that fail to parse, and warns of those that went over a
   *   limit. One that took too long is parsed again in the next request,
   *   and may go over again, so the service passes a logger that limits
   *   that.
   * @param \Drupal\canvas_ssr\JsParser\ParsedModuleCache $cache
   *   Keeps results between requests.
   * @param array{short?: int, medium?: int, long?: int} $timeouts
   *   The time limits, in milliseconds, by name. A parse may take the
   *   medium one. Those left out get Timeouts::DEFAULTS.
   */
  public function __construct(
    private readonly LoggerInterface $logger,
    private readonly ParsedModuleCache $cache,
    array $timeouts = [],
  ) {
    $this->timeouts = Timeouts::fromArray($timeouts);
  }

  /**
   * Parses JS module source.
   *
   * @param string $source_code_js
   *   The module's source.
   * @param string $component
   *   The component it's the compiled JS of, by machine name, for the log.
   *
   * @return \Drupal\canvas_ssr\JsParser\ParsedModule|null
   *   The parsed module, or NULL if the source can't be parsed.
   */
  public function parse(string $source_code_js, string $component): ?ParsedModule {
    // Not isset(), which is FALSE for a remembered failure.
    if (!\array_key_exists($source_code_js, $this->modules)) {
      $this->modules[$source_code_js] = $this->parseWithCache($source_code_js, $component);
    }

    return $this->modules[$source_code_js];
  }

  /**
   * Returns whether a source's parse gave up for a reason that may pass.
   *
   * That's running out of time, which depends on how busy the host is, or
   * of what PHP's memory_limit leaves the request, which depends on what
   * else the request did. A syntax error, or a parse past MEMORY_LIMIT,
   * fails the same way every time.
   *
   * @param string $source_code_js
   *   A source parse() was given this request.
   *
   * @return bool
   *   TRUE if its parse gave up for now.
   */
  public function gaveUpForNow(string $source_code_js): bool {
    return isset($this->gaveUp[$source_code_js]);
  }

  /**
   * Parses a module, by way of the cache.
   *
   * @param string $source_code_js
   *   The module's source.
   * @param string $component
   *   The component's machine name.
   *
   * @return \Drupal\canvas_ssr\JsParser\ParsedModule|null
   *   The parsed module, or NULL if the source can't be parsed.
   */
  private function parseWithCache(string $source_code_js, string $component): ?ParsedModule {
    // A hash anyone can collide would let one pushed source stand in for
    // another's imports.
    $hash = \hash('sha256', $source_code_js);
    $cached = $this->cache->lookup($hash);
    if ($cached !== NULL) {
      return $cached === FALSE ? NULL : $cached;
    }
    try {
      $module = $this->doParse($source_code_js, $component);
    }
    catch (ParseTimedOutException) {
      // How long a parse takes depends on how busy the host is, so this
      // isn't kept past the request.
      $this->logger->warning('Gave up parsing the compiled JS of the {component} component after {limit} ms, so SSR treats it as unparsable.', [
        'component' => $component,
        'limit' => $this->timeouts->medium,
      ]);
      $this->gaveUp[$source_code_js] = TRUE;
      return NULL;
    }
    catch (ParseOutOfHeadroomException) {
      // Nor is this, since it depends on what else the request did.
      $this->logger->warning('Gave up parsing the compiled JS of the {component} component, since PHP\'s memory_limit would have left the request too little, so SSR treats it as unparsable.', [
        'component' => $component,
      ]);
      $this->gaveUp[$source_code_js] = TRUE;
      return NULL;
    }
    $this->cache->store($hash, $module);

    return $module;
  }

  /**
   * Parses a module without the cache.
   *
   * @param string $source_code_js
   *   The module's source.
   * @param string $component
   *   The component's machine name.
   *
   * @return \Drupal\canvas_ssr\JsParser\ParsedModule|null
   *   The parsed module, or NULL if the source can't be parsed.
   *
   * @throws \Drupal\canvas_ssr\JsParser\ParseTimedOutException
   *   When the parse takes longer than the medium time limit.
   * @throws \Drupal\canvas_ssr\JsParser\ParseOutOfHeadroomException
   *   When the parse would leave the request less than MEMORY_MARGIN of
   *   PHP's memory_limit.
   */
  private function doParse(string $source_code_js, string $component): ?ParsedModule {
    try {
      $parser = Peast::latest($source_code_js, [
        'sourceType' => Peast::SOURCE_TYPE_MODULE,
        'jsx' => TRUE,
      ]);
      $headroom = self::headroom();
      // Going over what PHP's memory_limit leaves says nothing about the
      // source.
      $out_of_memory = $headroom < self::MEMORY_LIMIT ? ParseOutOfHeadroomException::class : ParseOutOfMemoryException::class;
      $memory_limit = \memory_get_usage() + \min(self::MEMORY_LIMIT, $headroom);
      $deadline = \hrtime(TRUE) + $this->timeouts->medium * 1_000_000;
      $tokens = 0;
      // Peast catches only its own exceptions, so these get out of it.
      $parser->getScanner()->getEventsEmitter()->addListener('TokenConsumed', static function () use (&$tokens, $memory_limit, $out_of_memory, $deadline): void {
        if (++$tokens % self::TOKENS_PER_CHECK !== 0) {
          return;
        }
        if (\memory_get_usage() > $memory_limit) {
          throw new $out_of_memory();
        }
        if (\hrtime(TRUE) > $deadline) {
          throw new ParseTimedOutException();
        }
      });
      $ast = $parser->parse();
    }
    catch (ParseOutOfMemoryException) {
      // How much memory a parse takes depends only on the source, so this
      // is kept with other failures.
      $this->logger->warning('Gave up parsing the compiled JS of the {component} component after it used more than {limit} MiB, so SSR treats it as unparsable.', [
        'component' => $component,
        'limit' => self::MEMORY_LIMIT / 1024 / 1024,
      ]);
      return NULL;
    }
    catch (ParseTimedOutException | ParseOutOfHeadroomException $e) {
      throw $e;
    }
    catch (\Throwable $e) {
      $this->logger->debug(
        'Failed to parse JS module source: {message}',
        ['message' => $e->getMessage()]
      );
      return NULL;
    }

    $imports = [];
    $dynamic = [];
    $ast->traverse(function ($node) use (&$imports, &$dynamic): void {
      if ($node instanceof ImportExpression) {
        $location = $node->getLocation();
        $dynamic[] = [$location->getStart()->getIndex(), $location->getEnd()->getIndex()];
      }
      $source_node = match (TRUE) {
        $node instanceof ImportDeclaration => $node->getSource(),
        $node instanceof ImportExpression => $node->getSource(),
        $node instanceof ExportAllDeclaration => $node->getSource(),
        $node instanceof ExportNamedDeclaration => $node->getSource(),
        default => NULL,
      };
      // A template with nothing to fill in names a module as a string does.
      if ($source_node instanceof TemplateLiteral && $source_node->getExpressions() === [] && \count($source_node->getQuasis()) === 1) {
        $specifier = $source_node->getQuasis()[0]->getValue();
      }
      elseif ($source_node instanceof Literal) {
        $specifier = $source_node->getValue();
      }
      else {
        return;
      }
      if (\is_string($specifier) && $specifier !== '') {
        $location = $source_node->getLocation();
        $imports[] = [$specifier, $location->getStart()->getIndex(), $location->getEnd()->getIndex()];
      }
    });
    // In source order, however Peast walks the tree.
    \usort($imports, static fn(array $a, array $b): int => $a[1] <=> $b[1]);
    \usort($dynamic, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
    $offsets = \array_merge(...$dynamic);
    foreach ($imports as $import) {
      \array_push($offsets, $import[1], $import[2]);
    }
    $to_bytes = self::byteOffsets($source_code_js, $offsets);
    foreach ($imports as &$import) {
      $import[1] = $to_bytes[$import[1]];
      $import[2] = $to_bytes[$import[2]];
    }
    foreach ($dynamic as &$call) {
      $call = [$to_bytes[$call[0]], $to_bytes[$call[1]]];
    }
    unset($import, $call);

    $directives = [];
    foreach ($ast->getBody() as $statement) {
      if (!$statement instanceof ExpressionStatement) {
        break;
      }
      $expression = $statement->getExpression();
      if (!$expression instanceof StringLiteral) {
        break;
      }
      // Use the raw literal: a directive with an escape sequence isn't the
      // directive, even if its value matches.
      $directives[] = \substr($expression->getRaw(), 1, -1);
    }

    return new ParsedModule($imports, $directives, $dynamic);
  }

  /**
   * Converts character offsets in a source to byte offsets.
   *
   * Peast counts characters, after any byte order mark. PHP's string
   * functions count bytes.
   *
   * @param string $source
   *   The source the offsets are in.
   * @param list<int> $offsets
   *   Character offsets, as Peast gives them.
   *
   * @return array<int, int>
   *   The byte offset of each, keyed by character offset.
   */
  private static function byteOffsets(string $source, array $offsets): array {
    $offsets = \array_unique($offsets);
    // In ASCII, which has no byte order mark, they're the same.
    if (!\preg_match('/[\x80-\xFF]/', $source)) {
      return \array_combine($offsets, $offsets);
    }
    \sort($offsets);

    $chars = 0;
    $bytes = \str_starts_with($source, "\xEF\xBB\xBF") ? 3 : 0;
    $to_bytes = [];
    // Each step counts only the characters since the last, so the whole
    // conversion reads the source once.
    foreach ($offsets as $offset) {
      $count = $offset - $chars;
      // A character is at most 4 bytes, so this chunk holds all of them.
      $chunk = \substr($source, $bytes, $count * 4);
      $bytes += \strlen(\mb_substr($chunk, 0, $count, 'UTF-8'));
      $chars = $offset;
      $to_bytes[$offset] = $bytes;
    }

    return $to_bytes;
  }

  /**
   * Returns how much a parse may use of what PHP's memory_limit leaves.
   *
   * PHP checks the limit against the memory it has taken from the system,
   * which is more than what's in use.
   *
   * @return int
   *   The bytes it may use, which may be none, or \PHP_INT_MAX when there's
   *   no memory_limit.
   */
  private static function headroom(): int {
    $setting = (string) \ini_get('memory_limit');
    // Bytes::toNumber() would read "-1", no limit, as 1.
    if ((int) $setting <= 0) {
      return \PHP_INT_MAX;
    }

    return (int) Bytes::toNumber($setting) - \memory_get_usage(TRUE) - self::MEMORY_MARGIN;
  }

}
