import { afterEach, describe, expect, it, vi } from 'vitest';

import useGit from './use-git.js';

const { envMock, simpleGitMock, gitInstance } = vi.hoisted(() => {
  const envMock = vi.fn();
  const gitInstance = { env: envMock };
  envMock.mockReturnValue(gitInstance);
  const simpleGitMock = vi.fn(() => gitInstance);

  return { envMock, simpleGitMock, gitInstance };
});

vi.mock('simple-git', () => ({
  simpleGit: simpleGitMock,
}));

describe('useGit', () => {
  const originalEditor = process.env.EDITOR;
  const originalPager = process.env.PAGER;

  afterEach(() => {
    vi.clearAllMocks();

    if (originalEditor === undefined) {
      delete process.env.EDITOR;
    } else {
      process.env.EDITOR = originalEditor;
    }

    if (originalPager === undefined) {
      delete process.env.PAGER;
    } else {
      process.env.PAGER = originalPager;
    }
  });

  it('allows inherited editor and pager env vars for git child processes', () => {
    process.env.EDITOR = 'vi';
    process.env.PAGER = 'cat';

    const git = useGit('/tmp/canvas-test');

    expect(simpleGitMock).toHaveBeenCalledWith('/tmp/canvas-test', {
      unsafe: {
        allowUnsafeEditor: true,
        allowUnsafePager: true,
      },
    });
    expect(envMock).toHaveBeenCalledWith(
      expect.objectContaining({
        EDITOR: 'vi',
        PAGER: 'cat',
        GIT_TERMINAL_PROMPT: 0,
      }),
    );
    expect(git).toBe(gitInstance);
  });
});
