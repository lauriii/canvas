import { vi } from 'vitest';

vi.mock('@clack/prompts', () => ({
  text: vi.fn(),
  password: vi.fn(),
  confirm: vi.fn(),
  multiselect: vi.fn(),
  isCancel: vi.fn(),
  cancel: vi.fn(),
  intro: vi.fn(),
  outro: vi.fn(),
  log: {
    warn: vi.fn(),
    info: vi.fn(),
    success: vi.fn(),
  },
}));
