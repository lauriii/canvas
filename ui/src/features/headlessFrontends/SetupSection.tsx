import { useState } from 'react';
import { ExternalLinkIcon } from '@radix-ui/react-icons';
import { Box, Flex, Link, Select, Tabs, Text } from '@radix-ui/themes';

import CommandSnippet from './CommandSnippet';
import PackageManagerSwitcher from './PackageManagerSwitcher';
import StepNumber from './StepNumber';

import type { PackageManager } from './types';

import styles from './HeadlessFrontends.module.css';

const CREATE_COMMANDS: Record<PackageManager, string> = {
  npm: 'npx @drupal-canvas/create@latest',
  pnpm: 'pnpm dlx @drupal-canvas/create@latest',
  yarn: 'yarn dlx @drupal-canvas/create@latest',
  bun: 'bunx @drupal-canvas/create@latest',
};

const INSTALL_COMMANDS: Record<PackageManager, string> = {
  npm: 'npm install',
  pnpm: 'pnpm add',
  yarn: 'yarn add',
  bun: 'bun add',
};

interface FrameworkAdapter {
  value: string;
  label: string;
  package: string;
  docsUrl: string;
}

const PACKAGE_DOCS_BASE_URL =
  'https://git.drupalcode.org/project/canvas/-/tree/1.x/packages';

const FRAMEWORK_ADAPTERS: FrameworkAdapter[] = [
  {
    value: 'next',
    label: 'Next.js',
    package: '@drupal-canvas/headless-next',
    docsUrl: `${PACKAGE_DOCS_BASE_URL}/headless-next`,
  },
  {
    value: 'nuxt',
    label: 'Nuxt',
    package: '@drupal-canvas/headless-nuxt',
    docsUrl: `${PACKAGE_DOCS_BASE_URL}/headless-nuxt`,
  },
  {
    value: 'astro',
    label: 'Astro',
    package: '@drupal-canvas/headless-astro',
    docsUrl: `${PACKAGE_DOCS_BASE_URL}/headless-astro`,
  },
  {
    value: 'tanstack-start',
    label: 'TanStack Start',
    package: '@drupal-canvas/headless-tanstack-start',
    docsUrl: `${PACKAGE_DOCS_BASE_URL}/headless-tanstack-start`,
  },
];

interface SetupSectionProps {
  packageManager: PackageManager;
  onPackageManagerChange: (value: PackageManager) => void;
}

const SetupSection = ({
  packageManager,
  onPackageManagerChange,
}: SetupSectionProps) => {
  const [framework, setFramework] = useState<FrameworkAdapter>(
    FRAMEWORK_ADAPTERS[0],
  );

  return (
    <Tabs.Root defaultValue="new">
      <Tabs.List justify="start" size="1">
        <Tabs.Trigger
          value="new"
          data-testid="canvas-headless-setup-new-tab-select"
        >
          Create a new codebase
        </Tabs.Trigger>
        <Tabs.Trigger
          value="existing"
          data-testid="canvas-headless-setup-existing-tab-select"
        >
          Use an existing codebase
        </Tabs.Trigger>
      </Tabs.List>
      <Box pt="3">
        <Tabs.Content
          value="new"
          data-testid="canvas-headless-setup-new-tab-content"
        >
          <Flex direction="column" gap="3" className={styles.sectionCard}>
            <Text size="1" color="gray">
              Scaffold a new frontend project that comes preconfigured for
              Drupal Canvas:
            </Text>
            <PackageManagerSwitcher
              value={packageManager}
              onValueChange={onPackageManagerChange}
            />
            <CommandSnippet
              command={CREATE_COMMANDS[packageManager]}
              data-testid="canvas-headless-create-command"
            />
          </Flex>
        </Tabs.Content>
        <Tabs.Content
          value="existing"
          data-testid="canvas-headless-setup-existing-tab-content"
        >
          <Flex direction="column" gap="4" className={styles.sectionCard}>
            <Text size="1" color="gray">
              Add the Drupal Canvas adapter to your existing codebase:
            </Text>
            <Flex gap="3">
              <StepNumber>1</StepNumber>
              <Flex direction="column" gap="3" flexGrow="1">
                <Text size="1">
                  Install the adapter package for your framework:
                </Text>
                <Flex gap="3" wrap="wrap" align="center">
                  <Select.Root
                    size="1"
                    value={framework.value}
                    onValueChange={(value) => {
                      const adapter = FRAMEWORK_ADAPTERS.find(
                        (item) => item.value === value,
                      );
                      if (adapter) {
                        setFramework(adapter);
                      }
                    }}
                  >
                    <Select.Trigger
                      aria-label="Framework"
                      className={styles.frameworkSelect}
                    />
                    <Select.Content>
                      {FRAMEWORK_ADAPTERS.map((adapter) => (
                        <Select.Item key={adapter.value} value={adapter.value}>
                          {adapter.label}
                        </Select.Item>
                      ))}
                    </Select.Content>
                  </Select.Root>
                  <PackageManagerSwitcher
                    value={packageManager}
                    onValueChange={onPackageManagerChange}
                  />
                </Flex>
                <CommandSnippet
                  command={`${INSTALL_COMMANDS[packageManager]} ${framework.package}`}
                  data-testid="canvas-headless-install-command"
                />
              </Flex>
            </Flex>
            <Flex gap="3">
              <StepNumber>2</StepNumber>
              <Flex direction="column" gap="1" flexGrow="1">
                <Text size="1">Wire the adapter into your app:</Text>
                <Text size="1">
                  <Link
                    href={framework.docsUrl}
                    target="_blank"
                    rel="noreferrer"
                    data-testid="canvas-headless-adapter-docs-link"
                  >
                    Read and follow the {framework.label} setup guide{' '}
                    <ExternalLinkIcon width="12" height="12" />
                  </Link>
                </Text>
              </Flex>
            </Flex>
          </Flex>
        </Tabs.Content>
      </Box>
    </Tabs.Root>
  );
};

export default SetupSection;
