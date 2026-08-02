import { useParams } from 'react-router-dom';

export interface CommentSurface {
  /** The `surfaceType` request argument, for example `canvas_page`. */
  surfaceType: string;
  /** The `surfaceId` request argument, for example `1`. */
  surfaceId: string;
  /** False until the route names an entity, and on non-editor routes. */
  hasSurface: boolean;
}

/**
 * Resolves the surface that comments are read from and written to.
 *
 * A "surface" is the entity currently open in the editor. Comments are stored
 * against it, not against the layout, so the surface is the only thing both the
 * sidebar and the on-canvas pins need to agree on.
 *
 * The route is the authority here, matching `/editor/:entityType/:entityId` in
 * `ui/src/app/AppRoutes.tsx`. `configurationSlice` looks like it ought to hold
 * this, but nothing in the app ever dispatches `setConfiguration`, so its
 * `entityType` and `entity` stay at their `'none'` placeholder forever.
 *
 * @returns The surface arguments plus whether they are usable yet.
 */
export const useCommentSurface = (): CommentSurface => {
  const { entityType, entityId } = useParams();
  return {
    surfaceType: entityType ?? '',
    surfaceId: entityId ?? '',
    hasSurface: !!entityType && !!entityId,
  };
};

export default useCommentSurface;
