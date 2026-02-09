import { useEffect, useRef } from 'react';
import { useLocation, useParams } from 'react-router-dom';

import { useAppDispatch } from '@/app/hooks';
import { useUndoRedo } from '@/hooks/useUndoRedo';

/**
 * List of route parameters that, when changed, should trigger cleanup actions
 * (at the moment that's just clearing undo/redo history when navigating to edit something else).
 *
 * @todo hopefully this won't be necessary once http://drupal.org/i/3566074 is resolved
 *
 */
const UNDO_REDO_HISTORY_CLEANUP_TRIGGER_PARAMS = [
  'entityType',
  'entityId',
  'bundle',
  'viewMode',
  'codeComponentId',
] as const;

/**
 * Hook that monitors route changes and triggers cleanup actions when
 * specific route parameters change.
 *
 * This allows the use of standard <Link> components throughout the app
 * while ensuring consistent cleanup behavior on navigation.
 *
 * Usage: Call this hook once at the route level (App.tsx).
 */
function useNavigationListener() {
  const location = useLocation();
  const params = useParams();
  const dispatch = useAppDispatch();
  const { dispatchClearUndoRedoHistory } = useUndoRedo();

  // Store previous parameter values to detect changes
  const previousParamsRef = useRef<Record<string, string | undefined>>({});

  useEffect(() => {
    // Get current values of the monitored parameters
    const currentParams: Record<string, string | undefined> = {};
    UNDO_REDO_HISTORY_CLEANUP_TRIGGER_PARAMS.forEach((paramName) => {
      currentParams[paramName] = params[paramName];
    });

    // Check if this is the initial render
    const isInitialRender = Object.keys(previousParamsRef.current).length === 0;

    if (!isInitialRender) {
      // Check if any of the monitored parameters have changed
      const hasRelevantChange = UNDO_REDO_HISTORY_CLEANUP_TRIGGER_PARAMS.some(
        (paramName) => {
          const previous = previousParamsRef.current[paramName];
          const current = currentParams[paramName];

          // Consider it a change if:
          // - Previous value existed and current is different, OR
          // - Previous value didn't exist and current does exist
          return (
            previous !== current &&
            (previous !== undefined || current !== undefined)
          );
        },
      );

      if (hasRelevantChange) {
        // Execute cleanup actions
        // Clear undo/redo history when navigating to a different entity or view mode or moving into the code editor etc.
        dispatchClearUndoRedoHistory();
      }
    }

    // Update the ref with current values for next comparison
    previousParamsRef.current = currentParams;
  }, [location.pathname, params, dispatch, dispatchClearUndoRedoHistory]);
}

export default useNavigationListener;
