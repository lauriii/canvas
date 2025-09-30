/**
 * @file
 * Synchronizes route information bidirectionally between React Router and Redux.
 */

import { useEffect, useRef } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { selectCurrentRoute, setCurrentRoute } from '@/features/ui/uiSlice';

import type { RouteSnapshot } from '@/features/ui/uiSlice';

export default function useRouteSync() {
  const { pathname, search, hash } = useLocation();
  const navigate = useNavigate();
  const dispatch = useAppDispatch();
  const currentRouteFromRedux = useAppSelector(selectCurrentRoute);

  // Track if we're currently navigating programmatically to prevent loops.
  const isNavigatingProgrammatically = useRef(false);

  // Track current pathname from the router to avoid including it in effect
  // dependencies that deals with Redux state changes.
  const currentPathname = useRef(pathname);

  // React Router → Redux:
  // Track route changes from navigation happening in the app.
  useEffect(() => {
    currentPathname.current = pathname;
    if (!isNavigatingProgrammatically.current) {
      const routeSnapshot: RouteSnapshot = {
        pathname,
        search,
        hash,
      };
      dispatch(setCurrentRoute(routeSnapshot));
    }
    isNavigatingProgrammatically.current = false;
  }, [dispatch, hash, pathname, search]);

  // Redux → React Router:
  // Navigate when Redux state changes (e.g., undo/redo).
  useEffect(() => {
    if (
      currentRouteFromRedux &&
      currentRouteFromRedux.pathname !== '' &&
      currentRouteFromRedux.pathname !== currentPathname.current
    ) {
      isNavigatingProgrammatically.current = true;
      navigate({
        pathname: currentRouteFromRedux.pathname,
        search: currentRouteFromRedux.search,
        hash: currentRouteFromRedux.hash,
      });
    }
  }, [currentRouteFromRedux, navigate]);
}
