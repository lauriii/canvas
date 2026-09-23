import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { beforeAll, describe, expect, it } from 'vitest';

const root = fileURLToPath(new URL('../', import.meta.url));

beforeAll(() => {
  execFileSync('npm', ['run', 'build'], { cwd: root, stdio: 'pipe' });
}, 60_000);

describe('built output', () => {
  it('ships partial declarations for consumer Angular linking', () => {
    const output = readFileSync(
      new URL(
        '../dist/fesm2022/drupal-canvas-headless-angular.mjs',
        import.meta.url,
      ),
      'utf8',
    );
    expect(output).toContain('ɵɵngDeclareComponent');
    expect(output).not.toContain('ɵɵdefineComponent');
  });
});
