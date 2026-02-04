import { useCallback, useLayoutEffect, useRef } from 'react';

/**
 * Custom hook to create a stable function reference that always sees the latest state.
 *
 * This hook returns a callback with a stable identity (the function reference never changes),
 * but the callback always invokes the most recent version of the function you pass in.
 * This is useful when you want to avoid re-running effects that depend on a callback,
 * while still ensuring the callback sees the latest props/state.
 *
 * This pattern is similar to the proposed `useEvent` RFC and effectively replaces
 * the manual "function-in-ref" pattern.
 *
 * @example
 * ```tsx
 * const handleClick = useStableCallback((value: string) => {
 *   // This always sees the latest `someState`
 *   console.log(someState, value);
 * });
 *
 * useEffect(() => {
 *   // This effect won't re-run when `someState` changes
 *   element.addEventListener('click', () => handleClick('test'));
 * }, [handleClick]); // handleClick identity is stable
 * ```
 *
 * @param callback - The callback function to stabilize
 * @returns A stable callback that always invokes the latest version of the input callback
 */
export function useStableCallback<T extends (...args: any[]) => any>(
  callback: T,
): T {
  const callbackRef = useRef(callback);

  // Use useLayoutEffect to ensure the ref is updated synchronously before paint
  useLayoutEffect(() => {
    callbackRef.current = callback;
  });

  return useCallback((...args: Parameters<T>): ReturnType<T> => {
    return callbackRef.current(...args);
  }, []) as T;
}
