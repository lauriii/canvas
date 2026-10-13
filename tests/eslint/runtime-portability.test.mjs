/**
 * @file
 * Tests the repository ESLint configuration that keeps `drupal-canvas` source
 * portable across Drupal-rendered pages, Workbench, and Headless.
 *
 * @see docs/adr/0021-code-component-runtime-compatibility-across-frontend-modes.md
 */

import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { ESLint } from 'eslint';
import { describe, expect, it } from 'vitest';

const repoRoot = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '../..',
);
const eslint = new ESLint({ cwd: repoRoot });

async function lint(code, file = 'packages/drupal-canvas/src/example.ts') {
  const [result] = await eslint.lintText(code, {
    filePath: path.join(repoRoot, file),
  });
  return result.messages.filter((message) =>
    [
      'no-restricted-globals',
      '@typescript-eslint/no-restricted-imports',
    ].includes(message.ruleId),
  );
}

async function lintRules(code, file) {
  return (await lint(code, file)).map((message) => message.ruleId);
}

describe('drupal-canvas runtime portability lint', () => {
  it('reports a portable helper that imports the settings adapter', async () => {
    const messages = await lint(
      "import { readCanvasDataV0 } from './drupal-settings.js';",
    );
    expect(messages.map((message) => message.ruleId)).toEqual([
      '@typescript-eslint/no-restricted-imports',
    ]);
    expect(messages[0].message).toContain('ADR 0021');
    expect(messages[0].message).toContain(
      '.agents/skills/canvas-code-component-runtime-portability',
    );
  });

  it.each([
    ['drupalSettings.canvasData;'],
    ["Drupal.t('Title');"],
    ['window.drupalSettings;'],
    ['globalThis?.drupalSettings?.canvasData;'],
  ])('reports Drupal global access: %s', async (code) => {
    expect(await lintRules(code)).toEqual(['no-restricted-globals']);
  });

  it.each([
    ["import { getPageData } from './drupal-utils.js';"],
    ["import { getSiteData } from 'drupal-canvas/drupal-utils';"],
    ["import { JsonApiClient } from './jsonapi-client.js';"],
    ["export { drupalSettingsToCanvasContext } from './drupal-settings';"],
  ])('reports Drupal runtime reader imports: %s', async (code) => {
    expect(await lintRules(code)).toEqual([
      '@typescript-eslint/no-restricted-imports',
    ]);
  });

  it('allows type-only imports but not mixed value imports', async () => {
    expect(
      await lintRules(
        "import type { CanvasDataV0 } from './drupal-settings.js';",
      ),
    ).toEqual([]);
    expect(
      await lintRules(
        "import { type CanvasDataV0, readCanvasDataV0 } from './drupal-settings.js';",
      ),
    ).toEqual(['@typescript-eslint/no-restricted-imports']);
  });

  it('allows portable imports, local names, and browser globals', async () => {
    const code = `
      import { sortMenu } from './drupal-utils.js';
      import { createJsonApiClient } from './jsonapi-client.js';
      import { usePageContext } from 'drupal-canvas/react';

      export function read(drupalSettings: { canvasData?: unknown }) {
        return [drupalSettings.canvasData, window.location.href, sortMenu];
      }
      export { createJsonApiClient, usePageContext };
    `;
    expect(await lintRules(code)).toEqual([]);
  });

  it.each([
    [
      'the settings adapter reading drupalSettings',
      'globalThis.drupalSettings;',
      'packages/drupal-canvas/src/drupal-settings.ts',
    ],
    [
      'the entry point re-exporting readers',
      "export { readCanvasDataV0 } from './drupal-settings.js';",
      'packages/drupal-canvas/src/index.ts',
    ],
    [
      'tests setting up Drupal globals',
      "import { readCanvasDataV0 } from './drupal-settings.js'; globalThis.drupalSettings = {};",
      'packages/drupal-canvas/src/example.test.ts',
    ],
    [
      'integrations outside drupal-canvas',
      'window.drupalSettings;',
      'packages/workbench/src/client/example.ts',
    ],
  ])('allows %s', async (_, code, file) => {
    expect(await lintRules(code, file)).toEqual([]);
  });

  it('keeps the react-redux restriction in drupal-canvas', async () => {
    expect(
      await lintRules("import { useSelector } from 'react-redux';"),
    ).toEqual(['@typescript-eslint/no-restricted-imports']);
  });
});
