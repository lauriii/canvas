import { Command } from 'commander';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import * as p from '@clack/prompts';

import { getConfig, setConfig } from '../config';
import { createApiService, ensureAuthConfig } from '../services/api';
import { printCommandIntro } from '../utils/command-intro';
import { headlessCommands } from './headless';

import type { ApiService } from '../services/api';

vi.mock('../config');
vi.mock('../services/api');
vi.mock('../utils/command-intro');

const api = {
  listFrontends: vi.fn(),
  addFrontend: vi.fn(),
  removeFrontend: vi.fn(),
};

const stdinTty = Object.getOwnPropertyDescriptor(process.stdin, 'isTTY');
const stdoutTty = Object.getOwnPropertyDescriptor(process.stdout, 'isTTY');

function setInteractive(value: boolean) {
  Object.defineProperty(process.stdin, 'isTTY', { configurable: true, value });
  Object.defineProperty(process.stdout, 'isTTY', { configurable: true, value });
}

afterEach(() => {
  if (stdinTty) Object.defineProperty(process.stdin, 'isTTY', stdinTty);
  else Reflect.deleteProperty(process.stdin, 'isTTY');
  if (stdoutTty) Object.defineProperty(process.stdout, 'isTTY', stdoutTty);
  else Reflect.deleteProperty(process.stdout, 'isTTY');
  vi.unstubAllEnvs();
});

async function run(...args: string[]) {
  const program = new Command();
  program.exitOverride();
  headlessCommands(program);
  await program.parseAsync(['node', 'canvas', ...args]);
}

describe('frontend commands', () => {
  beforeEach(() => {
    vi.spyOn(console, 'log').mockImplementation(() => {});
    setInteractive(false);
    vi.mocked(createApiService).mockResolvedValue(api as unknown as ApiService);
    vi.mocked(getConfig).mockReturnValue({
      siteUrl: 'https://drupal.example',
    } as ReturnType<typeof getConfig>);
    vi.unstubAllEnvs();
    vi.stubEnv('CANVAS_SCOPE', undefined);
  });

  it.each(['localhost:3000', 'ftp://app.example'])(
    'rejects %s before authenticating or calling the API',
    async (url) => {
      setInteractive(true);

      await expect(run('headless:add', url)).rejects.toThrow(
        'Frontend URL must start with http:// or https://.',
      );
      expect(ensureAuthConfig).not.toHaveBeenCalled();
      expect(createApiService).not.toHaveBeenCalled();
      expect(api.addFrontend).not.toHaveBeenCalled();
    },
  );

  it.each(['http://localhost:3000', 'HTTPS://app.example'])(
    'passes %s to the API unchanged',
    async (url) => {
      api.addFrontend.mockResolvedValue({ url, changed: true });

      await run('headless:add', url);

      expect(api.addFrontend).toHaveBeenCalledExactlyOnceWith(url, false);
    },
  );

  it('registers and selects the default with one item request', async () => {
    api.addFrontend.mockResolvedValue({
      url: 'https://app.example',
      changed: true,
    });
    await run(
      'headless:add',
      'https://app.example',
      '--default',
      '--site-url',
      'https://drupal.example',
    );
    expect(api.addFrontend).toHaveBeenCalledExactlyOnceWith(
      'https://app.example',
      true,
    );
    expect(api.listFrontends).not.toHaveBeenCalled();
    expect(p.confirm).not.toHaveBeenCalled();
    expect(setConfig).toHaveBeenCalledWith({
      scope: 'canvas:headless:frontends',
    });
    expect(printCommandIntro).toHaveBeenCalledWith('headless:add');
    expect(p.log.success).toHaveBeenCalledWith(
      'https://app.example is now the default frontend',
    );
    expect(p.outro).toHaveBeenCalledWith('Frontend registration completed');
  });

  it('reports an existing registration as a successful no-op', async () => {
    api.addFrontend.mockResolvedValue({
      url: 'https://app.example',
      changed: false,
    });
    await run('headless:add', 'https://app.example');
    expect(api.addFrontend).toHaveBeenCalledWith('https://app.example', false);
    expect(p.log.info).toHaveBeenCalledWith(
      'https://app.example is already registered',
    );
    expect(p.outro).toHaveBeenCalledExactlyOnceWith('No changes made');
    expect(p.log.success).not.toHaveBeenCalled();
  });

  it('reports an already-default frontend without claiming a change', async () => {
    api.addFrontend.mockResolvedValue({
      url: 'https://app.example',
      changed: false,
    });
    await run('headless:add', 'https://app.example', '--default');
    expect(p.log.info).toHaveBeenCalledWith(
      'https://app.example is already the default frontend',
    );
    expect(p.outro).toHaveBeenCalledExactlyOnceWith('No changes made');
    expect(p.log.success).not.toHaveBeenCalled();
  });

  it('lists URLs without exposing associations or the URL-list hash and without noninteractive prompts', async () => {
    const frontends = [{ url: 'https://app.example', components: ['js.hero'] }];
    api.listFrontends.mockResolvedValue({
      frontends,
      frontendUrlsHash: 'private-hash',
    });
    await run('headless:list');
    expect(setConfig).toHaveBeenCalledWith({
      scope: 'canvas:headless:frontends',
    });
    expect(vi.mocked(p.log.info).mock.calls).toEqual([
      [
        'Headless frontends for https://drupal.example\n\nhttps://app.example (default)',
      ],
    ]);
    expect(printCommandIntro).toHaveBeenCalledWith('headless:list');
    expect(p.outro).toHaveBeenCalledExactlyOnceWith('1 frontend registered');
    expect(ensureAuthConfig).not.toHaveBeenCalled();
  });

  it('marks the first readable entry as default without a column', async () => {
    api.listFrontends.mockResolvedValue({
      frontends: [
        { url: 'https://first.example', components: [] },
        { url: 'https://second.example', components: [] },
      ],
    });
    await run('headless:list');
    expect(p.log.info).toHaveBeenCalledWith(
      'Headless frontends for https://drupal.example\n\nhttps://first.example (default)\nhttps://second.example',
    );
    expect(p.outro).toHaveBeenCalledExactlyOnceWith('2 frontends registered');
  });

  it('succeeds with an empty list', async () => {
    api.listFrontends.mockResolvedValue({ frontends: [] });
    await run('headless:list');
    expect(p.outro).toHaveBeenCalledExactlyOnceWith(
      'No headless frontends registered for https://drupal.example',
    );
    expect(p.log.info).not.toHaveBeenCalled();
  });

  it('confirms a missing URL before reporting no change', async () => {
    setInteractive(true);
    vi.mocked(p.confirm).mockResolvedValue(true);
    api.removeFrontend.mockResolvedValue({
      url: 'https://app.example',
      removed: false,
    });
    await run('headless:remove', 'https://app.example');
    expect(p.confirm).toHaveBeenCalledOnce();
    expect(api.listFrontends).not.toHaveBeenCalled();
    expect(api.removeFrontend).toHaveBeenCalledExactlyOnceWith(
      'https://app.example',
    );
    expect(p.log.info).toHaveBeenCalledWith(
      'https://app.example is not registered',
    );
    expect(p.outro).toHaveBeenCalledExactlyOnceWith('No changes made');
  });

  it('requires --yes before calling the API in noninteractive execution', async () => {
    await expect(run('headless:remove', 'https://app.example')).rejects.toThrow(
      'Run again with --yes (-y)',
    );
    expect(api.removeFrontend).not.toHaveBeenCalled();
  });

  it('confirms removal interactively and explains default promotion', async () => {
    setInteractive(true);
    api.removeFrontend.mockResolvedValue({
      url: 'https://first.example',
      removed: true,
      newDefault: 'https://second.example',
    });
    vi.mocked(p.confirm).mockResolvedValue(true);
    await run('headless:remove', 'https://first.example');
    expect(p.confirm).toHaveBeenCalledWith(
      expect.objectContaining({
        message: expect.stringContaining('Remove https://first.example?'),
      }),
    );
    expect(api.removeFrontend).toHaveBeenCalledExactlyOnceWith(
      'https://first.example',
    );
    expect(p.confirm).toHaveBeenCalledBefore(api.removeFrontend);
    expect(api.listFrontends).not.toHaveBeenCalled();
    expect(p.log.success).toHaveBeenCalledExactlyOnceWith(
      'Removed https://first.example. https://second.example is now the default',
    );
    expect(p.outro).toHaveBeenCalledExactlyOnceWith(
      'Frontend removal completed',
    );
  });

  it('does not mutate when confirmation is declined', async () => {
    setInteractive(true);
    vi.mocked(p.confirm).mockResolvedValue(false);
    await run('headless:remove', 'https://app.example');
    expect(api.removeFrontend).not.toHaveBeenCalled();
    expect(createApiService).not.toHaveBeenCalled();
  });

  it.each(['--yes', '-y'])(
    'removes the last frontend with %s without prompting',
    async (flag) => {
      api.removeFrontend.mockResolvedValue({
        url: 'https://app.example',
        removed: true,
        newDefault: null,
      });
      await run('headless:remove', 'https://app.example', flag);
      expect(api.removeFrontend).toHaveBeenCalledExactlyOnceWith(
        'https://app.example',
      );
      expect(p.confirm).not.toHaveBeenCalled();
      expect(p.log.success).toHaveBeenCalledExactlyOnceWith(
        'Removed https://app.example',
      );
      expect(p.outro).toHaveBeenCalledExactlyOnceWith(
        'Frontend removal completed',
      );
    },
  );

  it('propagates API failures without emitting success output', async () => {
    api.listFrontends.mockRejectedValue(
      new Error('API Error (403): Forbidden'),
    );
    await expect(run('headless:list')).rejects.toThrow('403');
    expect(console.log).not.toHaveBeenCalled();
    expect(p.log.success).not.toHaveBeenCalled();
    expect(p.outro).not.toHaveBeenCalled();
  });

  describe('removal picker', () => {
    const frontends = [
      { url: 'https://first.example', components: ['js.hero'] },
      { url: 'https://second.example', components: [] },
    ];

    beforeEach(() => {
      setInteractive(true);
      api.listFrontends.mockResolvedValue({ frontends });
      api.removeFrontend.mockImplementation(async (url: string) => ({
        url,
        removed: true,
      }));
      vi.mocked(p.multiselect).mockResolvedValue([0]);
      vi.mocked(p.confirm).mockResolvedValue(true);
      vi.mocked(p.isCancel).mockImplementation(
        (value) => typeof value === 'symbol',
      );
    });

    it('lists every frontend with a default marker and an all option', async () => {
      await run('headless:remove');
      expect(p.multiselect).toHaveBeenCalledWith({
        message:
          'Select frontends to remove (Space to select, Enter to continue)',
        initialValues: [],
        required: false,
        options: [
          { value: 0, label: 'https://first.example (default)' },
          { value: 1, label: 'https://second.example' },
          { value: -1, label: 'All frontends' },
        ],
      });
      expect(p.confirm).toHaveBeenCalledWith(
        expect.objectContaining({
          initialValue: false,
          message: expect.stringContaining('Remove the selected frontend?'),
        }),
      );
      expect(api.removeFrontend).toHaveBeenCalledExactlyOnceWith(
        frontends[0].url,
      );
    });

    it('removes only the selected nondefault frontend', async () => {
      vi.mocked(p.multiselect).mockResolvedValue([1]);
      await run('headless:remove');
      expect(api.removeFrontend).toHaveBeenCalledExactlyOnceWith(
        frontends[1].url,
      );
    });

    it('removes multiple selected URLs in configured order and confirms their count', async () => {
      const third = { url: 'https://third.example', components: [] };
      api.listFrontends.mockResolvedValue({ frontends: [...frontends, third] });
      vi.mocked(p.multiselect).mockResolvedValue([1, 0]);
      await run('headless:remove');
      expect(api.removeFrontend.mock.calls).toEqual(
        frontends.map(({ url }) => [url]),
      );
      expect(vi.mocked(p.log.success).mock.calls).toEqual([
        ['Removed https://first.example'],
        ['Removed https://second.example'],
      ]);
      expect(p.outro).toHaveBeenCalledExactlyOnceWith(
        'Frontend removal completed',
      );
      expect(p.confirm).toHaveBeenCalledWith(
        expect.objectContaining({
          message: expect.stringContaining('Remove 2 selected frontends?'),
        }),
      );
    });

    it('cancels without mutation when no frontends are selected', async () => {
      vi.mocked(p.multiselect).mockResolvedValue([]);
      await run('headless:remove');
      expect(p.confirm).not.toHaveBeenCalled();
      expect(api.removeFrontend).not.toHaveBeenCalled();
      expect(p.cancel).toHaveBeenCalledWith('Frontend removal canceled');
      expect(p.outro).not.toHaveBeenCalled();
    });

    it('does not remove a frontend twice when all and individual options are selected', async () => {
      vi.mocked(p.multiselect).mockResolvedValue([-1, 0]);
      await run('headless:remove');
      expect(api.removeFrontend.mock.calls).toEqual(
        frontends.map(({ url }) => [url]),
      );
    });

    it('confirms all once and removes only the displayed snapshot using item requests', async () => {
      vi.mocked(p.multiselect).mockResolvedValue([-1]);
      await run('headless:remove');
      expect(p.confirm).toHaveBeenCalledExactlyOnceWith(
        expect.objectContaining({
          message: expect.stringContaining('Remove 2 selected frontends?'),
        }),
      );
      expect(api.listFrontends).toHaveBeenCalledOnce();
      expect(api.removeFrontend.mock.calls).toEqual(
        frontends.map(({ url }) => [url]),
      );
    });

    it('keeps the picker but skips confirmation with --yes', async () => {
      await run('headless:remove', '--yes');
      expect(p.multiselect).toHaveBeenCalledOnce();
      expect(p.confirm).not.toHaveBeenCalled();
      expect(api.removeFrontend).toHaveBeenCalledExactlyOnceWith(
        frontends[0].url,
      );
    });

    it('succeeds without prompting for an empty list', async () => {
      api.listFrontends.mockResolvedValue({ frontends: [] });
      await run('headless:remove');
      expect(p.multiselect).not.toHaveBeenCalled();
      expect(p.confirm).not.toHaveBeenCalled();
      expect(api.removeFrontend).not.toHaveBeenCalled();
      expect(p.outro).toHaveBeenCalledExactlyOnceWith(
        'No frontends registered',
      );
      expect(p.log.info).not.toHaveBeenCalled();
    });

    it.each([false, true])(
      'reports actual changes when selected frontends disappear concurrently (removed: %s)',
      async (removed) => {
        vi.mocked(p.multiselect).mockResolvedValue([-1]);
        api.removeFrontend
          .mockResolvedValueOnce({ url: frontends[0].url, removed: false })
          .mockResolvedValueOnce({ url: frontends[1].url, removed });
        await run('headless:remove');
        expect(p.outro).toHaveBeenCalledExactlyOnceWith(
          removed ? 'Frontend removal completed' : 'No changes made',
        );
        expect(p.log.info).toHaveBeenCalledWith(
          `${frontends[0].url} is not registered`,
        );
      },
    );

    it.each([[], ['--yes']])(
      'rejects URL omission without a terminal (%j)',
      async (...args) => {
        setInteractive(false);
        await expect(run('headless:remove', ...args)).rejects.toThrow(
          'Pass a URL and --yes (-y)',
        );
        expect(api.listFrontends).not.toHaveBeenCalled();
        expect(api.removeFrontend).not.toHaveBeenCalled();
      },
    );

    it('does not mutate if the picker is canceled', async () => {
      vi.mocked(p.multiselect).mockResolvedValue(Symbol('cancel'));
      await run('headless:remove');
      expect(p.confirm).not.toHaveBeenCalled();
      expect(api.removeFrontend).not.toHaveBeenCalled();
      expect(p.cancel).toHaveBeenCalledWith('Frontend removal canceled');
    });

    it.each([false, Symbol('cancel')])(
      'does not mutate if confirmation is declined or canceled (%s)',
      async (answer) => {
        vi.mocked(p.confirm).mockResolvedValue(answer);
        await run('headless:remove');
        expect(api.removeFrontend).not.toHaveBeenCalled();
      },
    );

    it('stops on partial failure without reporting later removals as successful', async () => {
      vi.mocked(p.multiselect).mockResolvedValue([-1]);
      api.removeFrontend
        .mockResolvedValueOnce({
          url: frontends[0].url,
          removed: true,
        })
        .mockRejectedValueOnce(new Error('API Error (503): locked'));
      await expect(run('headless:remove')).rejects.toThrow('503');
      expect(api.removeFrontend).toHaveBeenCalledTimes(2);
      expect(p.log.success).toHaveBeenCalledWith(`Removed ${frontends[0].url}`);
      expect(p.outro).not.toHaveBeenCalled();
      expect(p.log.success).not.toHaveBeenCalledWith(
        `Removed ${frontends[1].url}`,
      );
    });
  });
});
