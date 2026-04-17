import { useEffect, useRef, useState } from 'react';

/**
 * Keeps the required-error flag in sync with whether a multi-value prop's
 * example array contains at least one non-empty item.
 *
 * Call this after `useRequiredProp` to replace the repetitive `useEffect`
 * that appears in FormPropTypeArray, FormPropTypeDate, and FormPropTypeLink.
 *
 * @param required - Whether the prop is currently marked as required.
 * @param hasNonEmptyValue - Whether the example array has at least one value.
 *   Derive this with `hasNonEmptyArrayValue()` from arrayPropUtils.
 * @param setShowRequiredError - The setter returned by `useRequiredProp`.
 * @param allowMultiple - Pass false to skip the sync (single-value mode).
 *   Defaults to true so FormPropTypeArray can call this without arguments.
 */
export function useSyncRequiredArrayError(
  required: boolean,
  hasNonEmptyValue: boolean,
  setShowRequiredError: (show: boolean) => void,
  allowMultiple = true,
): void {
  useEffect(() => {
    if (allowMultiple) {
      setShowRequiredError(required && !hasNonEmptyValue);
    } else {
      // allowMultiple is false: clear any multi-value error that was showing.
      // This fires both during the multi→single transition and whenever
      // `required` changes in steady-state single-value mode. In both cases
      // clearing is correct — single-value components manage their own error
      // state via their onChange handlers.
      setShowRequiredError(false);
    }
  }, [required, hasNonEmptyValue, setShowRequiredError, allowMultiple]);
}

/**
 * Custom hook to manage required prop logic, including pre-filling example values
 * and handling validation errors.
 *
 * @param required - Whether the prop is currently marked as required.
 * @param example - Current example value for the prop (string for single, array for multi).
 * @param callback - Called to prefill a default example.
 * @param dependencies - Additional dependencies to include
 * @returns An object containing:
 *   - showRequiredError — Whether to display a required validation error.
 *   - setShowRequiredError — Setter for showRequiredError state.
 */
export const useRequiredProp = (
  required: boolean,
  example: string | string[],
  callback: () => void,
  dependencies: unknown[],
) => {
  const prevRequiredRef = useRef(required);
  const [showRequiredError, setShowRequiredError] = useState(false);
  // Stabilize the dynamic dependencies so the effect has a fixed‑length dependency list.
  const depsRef = useRef(dependencies);
  if (
    dependencies.length !== depsRef.current.length ||
    dependencies.some((dep, i) => !Object.is(dep, depsRef.current[i]))
  ) {
    depsRef.current = dependencies;
  }

  useEffect(() => {
    const prevRequired = prevRequiredRef.current;

    // Prefill the example value using the callback() when required is
    // toggled on and example is empty.
    if (required !== prevRequired && required && !example) {
      callback();
    }

    // Clear error when required is toggled off
    if (!required) {
      setShowRequiredError(false);
    }

    // Update ref after comparisons are done
    prevRequiredRef.current = required;
    // depsRef.current is stabilized but ESLint can't verify that statically, so we disable
    // the warning about missing dependencies.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [required, example, callback, ...depsRef.current]);

  return {
    showRequiredError,
    setShowRequiredError,
  };
};
