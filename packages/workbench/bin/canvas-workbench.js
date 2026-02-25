#!/usr/bin/env node
// Launches Vite with the @drupal-canvas/workbench root/config while preserving caller cwd.
import { spawn } from 'node:child_process';
import { createRequire } from 'node:module';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const vitePackageJsonPath = require.resolve('vite/package.json');
const viteBinPath = path.resolve(
  path.dirname(vitePackageJsonPath),
  'bin/vite.js',
);

const currentFilePath = fileURLToPath(import.meta.url);
const currentDir = path.dirname(currentFilePath);
const packageRoot = path.resolve(currentDir, '..');

const incomingArgs = process.argv.slice(2);
const hasExplicitCommand =
  incomingArgs[0] !== undefined && !incomingArgs[0].startsWith('-');

const command = hasExplicitCommand ? incomingArgs[0] : 'dev';
const passThroughArgs = hasExplicitCommand
  ? incomingArgs.slice(1)
  : incomingArgs;

const viteArgs = [
  command,
  packageRoot,
  '--config',
  path.join(packageRoot, 'vite.config.ts'),
  ...passThroughArgs,
];

const child = spawn(process.execPath, [viteBinPath, ...viteArgs], {
  stdio: 'inherit',
  cwd: process.cwd(),
  env: process.env,
});

child.on('exit', (code, signal) => {
  if (signal) {
    process.kill(process.pid, signal);
    return;
  }

  process.exit(code ?? 0);
});

child.on('error', (error) => {
  console.error(error);
  process.exit(1);
});
