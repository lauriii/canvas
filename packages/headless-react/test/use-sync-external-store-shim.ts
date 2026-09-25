/**
 * Test-only stand-in for `use-sync-external-store/shim`: that shim is CommonJS
 * and resolves `react` through Node, outside the aliasing that keeps this
 * package's tests on a single React copy. React 18+ ships the hook itself.
 */
export { useSyncExternalStore } from 'react';
