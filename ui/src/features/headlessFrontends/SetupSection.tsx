import { useState } from 'react';
import { ExternalLinkIcon } from '@radix-ui/react-icons';
import { Box, Flex, Link, RadioCards, Tabs, Text } from '@radix-ui/themes';

import { getSiteName, getSiteUrl } from '@/utils/drupal-globals';

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

interface Framework {
  // The template identifier the scaffolding command accepts via --template.
  value: string;
  label: string;
  adapterPackage: string;
  docsUrl: string;
}

const PACKAGE_DOCS_BASE_URL =
  'https://git.drupalcode.org/project/canvas/-/tree/1.x/packages';

const FRAMEWORKS: Framework[] = [
  {
    value: 'nextjs',
    label: 'Next.js',
    adapterPackage: '@drupal-canvas/headless-next',
    docsUrl: `${PACKAGE_DOCS_BASE_URL}/headless-next`,
  },
  {
    value: 'astro',
    label: 'Astro',
    adapterPackage: '@drupal-canvas/headless-astro',
    docsUrl: `${PACKAGE_DOCS_BASE_URL}/headless-astro`,
  },
  {
    value: 'nuxt',
    label: 'Nuxt',
    adapterPackage: '@drupal-canvas/headless-nuxt',
    docsUrl: `${PACKAGE_DOCS_BASE_URL}/headless-nuxt`,
  },
  {
    value: 'tanstack-start',
    label: 'TanStack Start',
    adapterPackage: '@drupal-canvas/headless-tanstack-start',
    docsUrl: `${PACKAGE_DOCS_BASE_URL}/headless-tanstack-start`,
  },
];

// Double quotes work across POSIX shells, cmd.exe, and PowerShell; single
// quotes are not recognized as quoting by cmd.exe. Backslash-escape the
// characters POSIX shells still expand inside double quotes.
const quoteShellArg = (value: string) =>
  `"${value.replace(/([$"\\`])/g, '\\$1')}"`;

const buildCreateCommand = (
  packageManager: PackageManager,
  framework: Framework,
) => {
  const flags = [];
  const siteUrl = getSiteUrl();
  if (siteUrl) {
    flags.push(`--site-url ${siteUrl}`);
  }
  const siteName = getSiteName();
  if (siteName) {
    flags.push(`--site-name ${quoteShellArg(siteName)}`);
  }
  flags.push(`--template ${framework.value}`);
  return [CREATE_COMMANDS[packageManager], ...flags].join(' ');
};

interface SetupSectionProps {
  packageManager: PackageManager;
  onPackageManagerChange: (value: PackageManager) => void;
}

const SetupSection = ({
  packageManager,
  onPackageManagerChange,
}: SetupSectionProps) => {
  const [framework, setFramework] = useState<Framework>(FRAMEWORKS[0]);

  return (
    <Flex direction="column" gap="4">
      <Flex direction="column" gap="2">
        <Text size="2" weight="bold">
          Framework
        </Text>
        <RadioCards.Root
          size="1"
          columns={{ initial: '2', sm: '4' }}
          value={framework.value}
          onValueChange={(value) => {
            const selected = FRAMEWORKS.find((item) => item.value === value);
            if (selected) {
              setFramework(selected);
            }
          }}
          aria-label="Framework"
          data-testid="canvas-headless-framework-select"
        >
          {FRAMEWORKS.map((item) => (
            <RadioCards.Item key={item.value} value={item.value}>
              {item.label}
            </RadioCards.Item>
          ))}
        </RadioCards.Root>
      </Flex>
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
                command={buildCreateCommand(packageManager, framework)}
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
                    Install the adapter package for {framework.label}:
                  </Text>
                  <PackageManagerSwitcher
                    value={packageManager}
                    onValueChange={onPackageManagerChange}
                  />
                  <CommandSnippet
                    command={`${INSTALL_COMMANDS[packageManager]} ${framework.adapterPackage}`}
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
    </Flex>
  );
};

export default SetupSection;
