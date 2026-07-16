import { ExternalLinkIcon } from '@radix-ui/react-icons';
import { Flex, Heading, Link, Text } from '@radix-ui/themes';

import CommandSnippet from './CommandSnippet';
import PackageManagerSwitcher from './PackageManagerSwitcher';
import SetupSection from './SetupSection';
import StepNumber from './StepNumber';

import type { PackageManager } from './types';

import styles from './HeadlessFrontends.module.css';

const PULL_COMMANDS: Record<PackageManager, string> = {
  npm: 'npx canvas pull',
  pnpm: 'pnpm exec canvas pull',
  yarn: 'yarn canvas pull',
  bun: 'bunx canvas pull',
};

// Placeholder until the CLI setup guide is published.
const CLI_DOCS_URL = 'https://www.npmjs.com/package/@drupal-canvas/cli';

interface SetupGuideProps {
  packageManager: PackageManager;
  onPackageManagerChange: (value: PackageManager) => void;
}

// The one-time setup content: getting a frontend codebase ready and pulling
// existing components into it. Shown inline while no frontends are connected
// yet, and from the setup guide dialog afterwards.
const SetupGuide = ({
  packageManager,
  onPackageManagerChange,
}: SetupGuideProps) => (
  <Flex direction="column" gap="7">
    <Flex direction="column" gap="3">
      <Heading as="h2" size="3">
        Set up your codebase
      </Heading>
      <SetupSection
        packageManager={packageManager}
        onPackageManagerChange={onPackageManagerChange}
      />
    </Flex>

    <Flex direction="column" gap="3">
      <Heading as="h2" size="3">
        Keep using your existing components
      </Heading>
      <Flex direction="column" gap="4" className={styles.sectionCard}>
        <Text size="1" color="gray">
          Synchronize your existing components with your headless codebase:
        </Text>
        <Flex gap="3">
          <StepNumber>1</StepNumber>
          <Flex direction="column" gap="1" flexGrow="1">
            <Text size="1">Set up the Canvas CLI:</Text>
            <Text size="1">
              <Link
                href={CLI_DOCS_URL}
                target="_blank"
                rel="noreferrer"
                data-testid="canvas-headless-cli-docs-link"
              >
                Read and follow the Canvas CLI setup guide{' '}
                <ExternalLinkIcon width="12" height="12" />
              </Link>
            </Text>
          </Flex>
        </Flex>
        <Flex gap="3">
          <StepNumber>2</StepNumber>
          <Flex direction="column" gap="3" flexGrow="1">
            <Text size="1">Pull your existing components:</Text>
            <PackageManagerSwitcher
              value={packageManager}
              onValueChange={onPackageManagerChange}
            />
            <CommandSnippet
              command={PULL_COMMANDS[packageManager]}
              data-testid="canvas-headless-pull-command"
            />
          </Flex>
        </Flex>
      </Flex>
    </Flex>
  </Flex>
);

export default SetupGuide;
