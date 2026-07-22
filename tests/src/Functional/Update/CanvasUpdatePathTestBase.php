<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional\Update;

use Drupal\canvas\Health\Doctor;
use Drupal\canvas\Health\HealthCheck;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\FunctionalTests\Update\UpdatePathTestBase;
use Drupal\Tests\canvas\Traits\AssertSameInputsTrait;
use Symfony\Component\Validator\ConstraintViolation;

abstract class CanvasUpdatePathTestBase extends UpdatePathTestBase {

  use AssertSameInputsTrait;

  /**
   * Adds before/after doctor assertions around the update run.
   */
  protected function runUpdates(): void {
    $engine = \Drupal::service(Doctor::class);
    $before = $engine->runSystemCheck(HealthCheck::UpdatesEscapedConfig);
    self::assertSame('problem', $before['status'], \sprintf('No pending migration detected before updates. Pending: %s.', \json_encode($before['details']['pending'])));

    parent::runUpdates();

    // Fresh engine instance: avoids stale caches.
    $engine = \Drupal::service(Doctor::class);

    // Every system check must pass: the update path just ran, so the two
    // update checks must be outright healthy; the risk-kind checks may report
    // advisory risks (dev checkout, missing instance updaters) but never a
    // problem.
    foreach (HealthCheck::cases() as $check) {
      if (!$check->isSystemCheck()) {
        continue;
      }
      self::assertSystemCheckHealthy($check, $engine->runSystemCheck($check));
    }

    // Every data check must find zero violations, except risk-kind checks
    // (auto-save: drafts are allowed to be invalid).
    $failing_findings = self::collectFailingFindings($engine->runDataChecks());
    self::assertSame([], $failing_findings, \sprintf('Invalid data after updates: %s.', self::describeFailingFindings($failing_findings)));
  }

  /**
   * Asserts one system check is healthy after updates.
   *
   * Risk-kind checks (e.g. dev checkout, missing instance updaters) may
   * still report an advisory risk, but never a problem; every other system
   * check must be outright healthy.
   */
  private static function assertSystemCheckHealthy(HealthCheck $check, array $result): void {
    $details = \json_encode($result['details']);
    if ($check->findingsAreRisks()) {
      self::assertNotSame('problem', $result['status'], \sprintf('%s reports a problem after updates: %s.', $check->value, $details));
      return;
    }
    self::assertSame('healthy', $result['status'], \sprintf('%s not healthy after updates: %s.', $check->value, $details));
  }

  /**
   * Flattens a Doctor::run() stream to findings with violations.
   *
   * Risk-kind checks (e.g. auto-save, where invalid drafts are expected) are
   * skipped.
   *
   * @return array
   */
  private static function collectFailingFindings(iterable $items): array {
    $failing = [];
    foreach ($items as $item) {
      if (HealthCheck::from($item['check'])->findingsAreRisks()) {
        continue;
      }
      foreach ($item['findings'] as $finding) {
        if ($finding['violations'] !== []) {
          $failing[] = ['check' => $item['check']] + $finding;
        }
      }
    }
    return $failing;
  }

  /**
   * Describes failing findings for an assertion failure message.
   */
  private static function describeFailingFindings(array $failing_findings): string {
    $descriptions = \array_map(
      static fn (array $f): string => \sprintf('[%s] %s: %s', $f['check'], $f['label'], \implode(', ', \array_column($f['violations'], 'message'))),
      $failing_findings,
    );
    return (string) \json_encode($descriptions);
  }

  protected static function assertEntityIsValid(ConfigEntityInterface $entity): void {
    $violations = $entity->getTypedData()->validate();
    self::assertCount(0, $violations, \sprintf('Violations for %s %s: %s', $entity->getEntityType()->getLabel(), $entity->id(), \implode(\PHP_EOL, \array_map(
      // @phpstan-ignore-next-line
      static fn (ConstraintViolation $violation): string => \sprintf('%s: %s', $violation->getPropertyPath(), $violation->getMessage()),
      \iterator_to_array($violations),
    ))));
  }

}
