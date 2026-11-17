<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\Hook;

use Drupal\canvas_ssr\HostStatus;
use Drupal\canvas_ssr\JsRunner\RunnerInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Says on the status report whether SSR is on, and if not, why.
 *
 * Without a runner, or without a base it can use, islands fall back to CSR,
 * which a visitor can't tell from a slow page. Each web server has its own
 * runner and cache directories, and the report is served by one of them. So
 * it checks this one, and lists what the others recorded.
 *
 * @see \Drupal\canvas_ssr\HostStatus
 */
final class CanvasSsrRequirements {

  use StringTranslationTrait;

  /**
   * Constructs a CanvasSsrRequirements.
   *
   * @param \Drupal\canvas_ssr\JsRunner\RunnerInterface $runner
   *   The runner renders use, which says why it's unavailable.
   * @param \Drupal\canvas_ssr\HostStatus $hostStatus
   *   What every web server recorded.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   Formats when a problem started, and when it's next retried.
   */
  public function __construct(
    private readonly RunnerInterface $runner,
    private readonly HostStatus $hostStatus,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Implements hook_runtime_requirements().
   */
  #[Hook('runtime_requirements')]
  public function runtime(): array {
    $this_host = HostStatus::host();
    $on = $this->runner->isAvailable();
    $problems = [];
    $off = !$on;
    if (!$on) {
      $reason = $this->runner->unavailableReason();
      $problems[] = $reason === NULL
        ? $this->t('On @host, this web server, no runner is available, so every island renders in the browser instead.', ['@host' => $this_host])
        : $this->t('On @host, this web server, no runner is available, so every island renders in the browser instead. @reason', [
          '@host' => $this_host,
          '@reason' => $reason,
        ]);
    }
    else {
      foreach ($this->runner->limitations() as $limitation) {
        $problems[] = $this->t("On @host, this web server, SSR is on, with a limit it can't enforce. @limitation", [
          '@host' => $this_host,
          '@limitation' => $limitation,
        ]);
      }
    }
    foreach ($this->hostStatus->reports() as $host => $report) {
      // This web server's was just checked.
      if (isset($report['limits']) && $host !== $this_host) {
        foreach ($report['limits']['limitations'] as $limitation) {
          $problems[] = $this->t("On @host, SSR was on as of @time, with a limit it can't enforce. @limitation", [
            '@host' => $host,
            '@time' => $this->dateFormatter->format($report['limits']['at'], 'short'),
            '@limitation' => $limitation,
          ]);
        }
      }
      if (isset($report['off']) && $host !== $this_host) {
        $off = TRUE;
        $problems[] = $this->t('On @host, no runner was available as of @time, so its islands render in the browser instead. @reason', [
          '@host' => $host,
          '@time' => $this->dateFormatter->format($report['off']['at'], 'short'),
          '@reason' => $report['off']['reason'],
        ]);
      }
      if (isset($report['base'])) {
        $problems[] = $this->baseProblem($host, $report['base']);
      }
    }
    // A fallback for want of a runner is cached as a render would be, and
    // nothing invalidates it when one becomes available.
    if ($off) {
      $problems[] = $this->t('Once a runner is available, clear all caches. Pages cached while none was go on rendering their islands in the browser.');
    }

    $requirement = [
      'title' => $this->t('Server-side rendering'),
      'value' => $on ? $this->t('On') : $this->t('Off'),
      'severity' => $problems === [] ? RequirementSeverity::OK : RequirementSeverity::Warning,
    ];
    if ($problems !== []) {
      $requirement['description'] = [
        '#theme' => 'item_list',
        '#items' => $problems,
      ];
    }

    return ['canvas_ssr' => $requirement];
  }

  /**
   * Describes a host whose base can't be built.
   *
   * @param string $host
   *   The host.
   * @param array{since: int, due: int, error: string} $record
   *   Since when its runner has failed to build it, when it tries next, and
   *   why.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The description.
   */
  private function baseProblem(string $host, array $record): TranslatableMarkup {
    return $this->t("On @host, the SSR base hasn't been built since @since, so islands render in the browser instead. It's tried again at @due. The last try reported: @error", [
      '@host' => $host,
      '@since' => $this->dateFormatter->format($record['since'], 'short'),
      '@due' => $this->dateFormatter->format($record['due'], 'short'),
      '@error' => $record['error'],
    ]);
  }

}
