import { createContext, useContext, useEffect, useMemo, useState } from 'react';

import type { ReactNode } from 'react';

export type Theme = 'dark' | 'light' | 'system';

type ResolvedTheme = 'dark' | 'light';

interface ThemeProviderProps {
  children: ReactNode;
  defaultTheme?: Theme;
  storageKey?: string;
}

interface ThemeProviderState {
  theme: Theme;
  resolvedTheme: ResolvedTheme;
  setTheme: (theme: Theme) => void;
}

const ThemeProviderContext = createContext<ThemeProviderState | undefined>(
  undefined,
);

function getSystemTheme(): ResolvedTheme {
  return window.matchMedia('(prefers-color-scheme: dark)').matches
    ? 'dark'
    : 'light';
}

function resolveTheme(theme: Theme): ResolvedTheme {
  return theme === 'system' ? getSystemTheme() : theme;
}

export function ThemeProvider({
  children,
  defaultTheme = 'system',
  storageKey = 'canvas-workbench-theme',
}: ThemeProviderProps) {
  const [theme, setThemeState] = useState<Theme>(defaultTheme);
  const [resolvedTheme, setResolvedTheme] = useState<ResolvedTheme>(() =>
    resolveTheme(defaultTheme),
  );

  useEffect(() => {
    const storedTheme = window.localStorage.getItem(storageKey);
    const initialTheme =
      storedTheme === 'dark' ||
      storedTheme === 'light' ||
      storedTheme === 'system'
        ? storedTheme
        : defaultTheme;
    setThemeState(initialTheme);
  }, [defaultTheme, storageKey]);

  useEffect(() => {
    const root = window.document.documentElement;
    const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');

    const applyTheme = (nextTheme: Theme) => {
      const nextResolvedTheme =
        nextTheme === 'system'
          ? mediaQuery.matches
            ? 'dark'
            : 'light'
          : nextTheme;

      root.classList.remove('light', 'dark');
      root.classList.add(nextResolvedTheme);
      root.style.colorScheme = nextResolvedTheme;
      setResolvedTheme(nextResolvedTheme);
    };

    applyTheme(theme);

    if (theme !== 'system') {
      return undefined;
    }

    const handleChange = () => {
      applyTheme('system');
    };

    mediaQuery.addEventListener('change', handleChange);

    return () => {
      mediaQuery.removeEventListener('change', handleChange);
    };
  }, [theme]);

  useEffect(() => {
    const handleStorage = (event: StorageEvent) => {
      if (event.key !== storageKey) {
        return;
      }

      const nextTheme = event.newValue;

      if (
        nextTheme === 'dark' ||
        nextTheme === 'light' ||
        nextTheme === 'system'
      ) {
        setThemeState(nextTheme);
        return;
      }

      setThemeState(defaultTheme);
    };

    window.addEventListener('storage', handleStorage);

    return () => {
      window.removeEventListener('storage', handleStorage);
    };
  }, [defaultTheme, storageKey]);

  const value = useMemo(
    () => ({
      theme,
      resolvedTheme,
      setTheme: (nextTheme: Theme) => {
        window.localStorage.setItem(storageKey, nextTheme);
        setThemeState(nextTheme);
      },
    }),
    [resolvedTheme, storageKey, theme],
  );

  return (
    <ThemeProviderContext.Provider value={value}>
      {children}
    </ThemeProviderContext.Provider>
  );
}

export function useTheme(): ThemeProviderState {
  const context = useContext(ThemeProviderContext);

  if (!context) {
    throw new Error('useTheme must be used within a ThemeProvider');
  }

  return context;
}
