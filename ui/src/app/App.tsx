import type React from 'react';
import { Outlet } from 'react-router-dom';
import ErrorBoundary from '@/components/error/ErrorBoundary';
import type { CollisionDetection } from '@dnd-kit/core';
import {
  DndContext,
  PointerSensor,
  pointerWithin,
  rectIntersection,
  useSensor,
  useSensors,
} from '@dnd-kit/core';
import Topbar from '@/components/topbar/Topbar';
import Layout from '@/features/layout/Layout';
import { snapCenterToCursor } from '@dnd-kit/modifiers';
import DragEventsHandler from '@/features/layout/previewOverlay/DragEventsHandler'; // This uses the suggested composition here https://docs.dndkit.com/api-documentation/context-provider/collision-detection-algorithms#composition-of-existing-algorithms

// This uses the suggested composition here https://docs.dndkit.com/api-documentation/context-provider/collision-detection-algorithms#composition-of-existing-algorithms
// the collision will use the mouse cursor's position, but if the mouse cursor is not in a valid dropzone it will fallback
// and use the rectangle intersection check (based on the drag overlay's size)
function customCollisionDetectionAlgorithm(
  args: Parameters<CollisionDetection>[0],
): ReturnType<CollisionDetection> {
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
  const pointerSensor = useSensor(PointerSensor, {
    // Require the mouse to move by 3 pixels before activating - without this you can't click to select a component
    activationConstraint: {
      distance: 3,
    },
  });
  const sensors = useSensors(pointerSensor);
  return (
    <div className="xb-app">
      <ErrorBoundary
        variant="alert"
        title="An unexpected error has occurred while fetching layouts."
      >
        <DndContext
          sensors={sensors}
          modifiers={[snapCenterToCursor]}
          collisionDetection={customCollisionDetectionAlgorithm}
        >
          <Layout />
          <ErrorBoundary variant="page">
            <Outlet />
          </ErrorBoundary>
          <Topbar />
          <DragEventsHandler />
        </DndContext>
      </ErrorBoundary>
    </div>
  );
};

export default App;
