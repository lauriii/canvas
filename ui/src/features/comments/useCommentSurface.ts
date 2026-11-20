import { useParams } from 'react-router';

export interface CommentSurface {
  /** The `surfaceType` request argument, for example `canvas_page`. */
  surfaceType: string;
  /** The `surfaceId` request argument, for example `1`. */
  surfaceId: string;
  /**
   * False until the route names an entity, on non-editor routes, and for an
   * entity type that cannot be commented on.
   */
  hasSurface: boolean;
}

/**
 * The entity types the comment API accepts as a surface.
 *
 * Keep in step with `ApiCommentController::SUPPORTED_SURFACE_TYPES`.
 */
const SUPPORTED_SURFACE_TYPES = ['canvas_page'];

/**
 * Resolves the surface that comments are read from and written to: the entity
 * open in the editor, taken from the `/editor/:entityType/:entityId` route.
 *
 * @returns The surface arguments plus whether they are usable yet.
 */
export const useCommentSurface = (): CommentSurface => {
  const { entityType, entityId } = useParams();
  return {
    surfaceType: entityType ?? '',
    surfaceId: entityId ?? '',
    hasSurface:
      !!entityType &&
      !!entityId &&
      SUPPORTED_SURFACE_TYPES.includes(entityType),
  };
};

export default useCommentSurface;
