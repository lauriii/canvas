import { Outlet } from 'react-router-dom';
import {
  DndContext,
  PointerSensor,
  pointerWithin,
  rectIntersection,
  useSensor,
  useSensors,
} from '@dnd-kit/core';
import { InfoCircledIcon } from '@radix-ui/react-icons';
import { Box, Callout, Flex } from '@radix-ui/themes';

import AiPanel from '@/components/aiExtension/AiPanel';
import DevTools from '@/components/devTools/DevTools';
import ErrorBoundary from '@/components/error/ErrorBoundary';
import SavingOverlay from '@/components/SavingOverlay';
import Toast from '@/components/Toast';
import Topbar from '@/components/topbar/Topbar';
import useExtensions from '@/features/extensions/useExtensions';
import DragEventsHandler from '@/features/layout/previewOverlay/DragEventsHandler';
import useRouteSync from '@/hooks/useRouteSync';

import type React from 'react';
import type { CollisionDetection } from '@dnd-kit/core';

import styles from '@/features/editor/App.module.css';

// This uses the suggested composition here https://docs.dndkit.com/api-documentation/context-provider/collision-detection-algorithms#composition-of-existing-algorithms
// the collision will use the mouse cursor's position, but if the mouse cursor is not in a valid dropzone it will fallback
// and use the rectangle intersection check (based on the drag overlay's size)
function customCollisionDetectionAlgorithm(
  args: Parameters<CollisionDetection>[0],
): ReturnType<CollisionDetection> {
  // When dragging in the layers, use the rectIntersection as it works best.
  if (args.active.data.current?.origin === 'layers') {
    return rectIntersection(args);
  }

  // First, let's see if there are any collisions with the pointer
  const pointerCollisions = pointerWithin(args);

  // Collision detection algorithms return an array of collisions
  if (pointerCollisions.length > 0) {
    return pointerCollisions;
  }

  // If there are no collisions with the pointer, return rectangle intersections
  return rectIntersection(args);
}

const App: React.FC = () => {
  useRouteSync();
  useExtensions();

  const pointerSensor = useSensor(PointerSensor, {
    // Require the mouse to move by 3 pixels before activating - without this you can't click to select a component
    activationConstraint: {
      distance: 3,
    },
  });
  const sensors = useSensors(pointerSensor);
  return (
    <div className="canvas-app">
      <div className={styles.canvasCalloutContainer}>
        <Box maxWidth="500px">
          <Callout.Root color="orange" size="2">
            <Callout.Icon>
              <InfoCircledIcon />
            </Callout.Icon>
            <Callout.Text>
              Drupal Canvas requires a browser window at least 1024 pixels wide
              to function properly.
            </Callout.Text>
            <Callout.Text>
              Please resize your browser window or switch to a device with a
              larger screen to continue using Drupal Canvas.
            </Callout.Text>
          </Callout.Root>
        </Box>
      </div>
      <div className={styles.canvasAppContent}>
        <ErrorBoundary
          variant="alert"
          title="Drupal Canvas has encountered an unexpected error."
        >
          <DndContext
            sensors={sensors}
            collisionDetection={customCollisionDetectionAlgorithm}
          >
            <Flex className={styles.canvasContainer} gap="0">
              <ErrorBoundary variant="page">
                <AiPanel />
                <Outlet />
              </ErrorBoundary>
            </Flex>
            <Topbar />
            <DragEventsHandler />
            {import.meta.env.DEV && <DevTools />}
            <SavingOverlay />
            <Toast />
          </DndContext>
        </ErrorBoundary>
      </div>
    </div>
  );
};

export default App;
