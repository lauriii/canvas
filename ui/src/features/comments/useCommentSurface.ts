import { useAppSelector } from '@/app/hooks';
import {
  selectEntityId,
  selectEntityType,
} from '@/features/configuration/configurationSlice';

export interface CommentSurface {
  /** The `surfaceType` request argument, for example `canvas_page`. */
  surfaceType: string;
  /** The `surfaceId` request argument, for example `1`. */
  surfaceId: string;
  /** False before the editor knows which entity it is editing. */
  hasSurface: boolean;
}

// `configurationSlice` initializes both values to this placeholder.
// @see ui/src/features/configuration/configurationSlice.ts
const UNSET = 'none';

/**
 * Resolves the surface that comments are read from and written to.
 *
 * A "surface" is the entity currently open in the editor. Comments are stored
 * against it, not against the layout, so the surface is the only thing both the
 * sidebar and the on-canvas pins need to agree on.
 *
 * @returns The surface arguments plus whether they are usable yet.
 */
export const useCommentSurface = (): CommentSurface => {
  const surfaceType = useAppSelector(selectEntityType);
  const surfaceId = useAppSelector(selectEntityId);
  return {
    surfaceType,
    surfaceId,
    hasSurface:
      !!surfaceType &&
      !!surfaceId &&
      surfaceType !== UNSET &&
      surfaceId !== UNSET,
  };
};

export default useCommentSurface;
