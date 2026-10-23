import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
} from 'react';

import { useAppDispatch } from '@/app/hooks';
import {
  useSafeFormContext,
  useSafeRHFContext,
} from '@/components/form/contexts/FormContext';
import { dispatchFieldValue } from '@/components/form/react-hook-form/utils';
import useInputUIData from '@/hooks/useInputUIData';

import type { ReactNode } from 'react';
import type { FieldContextValue } from '@/components/form/contexts/FieldContext';
import type { FormId } from '@/features/form/formStateSlice';

import styles from './FocalPointPicker.module.css';

export type FocalPoint = { x: number; y: number };
export type Axis = keyof FocalPoint;

/**
 * What a coordinate input shares with the picker around it.
 */
export interface CoordinateRegistration {
  name: string;
  value: unknown;
  element: HTMLInputElement | null;
  fieldContext: FieldContextValue | null;
}

const FocalPointContext = createContext<{
  register: (axis: Axis, registration: CoordinateRegistration) => void;
} | null>(null);

export const useFocalPointContext = () => useContext(FocalPointContext);

// Out of range values are not shown: the focalPoint transform does not store
// them either.
const toPercent = (value: unknown): number | null => {
  if (value === '' || value === null || value === undefined) {
    return null;
  }
  const number = Number(value);
  return number >= 0 && number <= 100 ? number : null;
};

const clampPercent = (value: number) =>
  Math.round(Math.min(100, Math.max(0, value)));

/**
 * Returns the image a focal point is set on, from the component's layout model.
 *
 * @param imageProp - The name of the component's image prop.
 * @param delta - The focal point's position in its list, if it is in one.
 */
export const useFocalPointImage = (
  imageProp: string | undefined,
  delta: number,
): string | null => {
  const { model, selectedComponent } = useInputUIData();
  if (!imageProp) {
    return null;
  }
  const resolved = (model?.[selectedComponent] as any)?.resolved?.[imageProp];
  const image = Array.isArray(resolved)
    ? (resolved[delta] ?? resolved[0])
    : resolved;
  return typeof image?.src === 'string' ? image.src : null;
};

/**
 * A small preview of an image with the focal point marked on it.
 */
export const FocalPointThumbnail = ({
  src,
  point,
}: {
  src: string | null;
  point: FocalPoint | null;
}) => (
  <span className={styles.thumbnail} aria-hidden="true">
    {src && <img src={src} alt="" className={styles.image} />}
    {point && (
      <span
        className={styles.thumbnailDot}
        style={{ left: `${point.x}%`, top: `${point.y}%` }}
      />
    )}
  </span>
);

export const formatFocalPoint = (point: FocalPoint | null) =>
  point ? `${point.x}%, ${point.y}%` : 'Empty';

/**
 * Shows an image with a draggable focal point marker above the x and y inputs.
 *
 * The x and y inputs (`children`) are the real, react-hook-form connected
 * form elements. They register with this component so that dragging the
 * marker updates them through the regular form pipeline.
 *
 * @see DrupalFocalPointCoordinate
 */
const FocalPointPicker = ({
  imageProp,
  delta = 0,
  onPointChange,
  children,
}: {
  imageProp?: string;
  delta?: number;
  onPointChange?: (
    point: FocalPoint | null,
    registrations: Partial<Record<Axis, CoordinateRegistration>>,
  ) => void;
  children?: ReactNode;
}) => {
  const dispatch = useAppDispatch();
  const formContext = useSafeFormContext();
  const rhfContext = useSafeRHFContext();
  const src = useFocalPointImage(imageProp, delta);
  const [coordinates, setCoordinates] = useState<
    Partial<Record<Axis, CoordinateRegistration>>
  >({});
  // The point shown while dragging, and until the inputs have caught up.
  const [draft, setDraft] = useState<FocalPoint | null>(null);
  const [dragging, setDragging] = useState(false);
  // The x value still to commit once the y value has rendered.
  const [pendingX, setPendingX] = useState<number | null>(null);

  const register = useCallback(
    (axis: Axis, registration: CoordinateRegistration) =>
      setCoordinates((current) => ({ ...current, [axis]: registration })),
    [],
  );
  const contextValue = useMemo(() => ({ register }), [register]);

  const x = toPercent(coordinates.x?.value);
  const y = toPercent(coordinates.y?.value);
  const stored = x !== null && y !== null ? { x, y } : null;
  const point = draft ?? stored;

  useEffect(() => {
    onPointChange?.(stored, coordinates);
    // `stored` derives from `coordinates`, and callers pass inline callbacks.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [coordinates]);

  // Drop the draft once the inputs hold the committed point.
  useEffect(() => {
    if (!dragging && draft && draft.x === x && draft.y === y) {
      setDraft(null);
    }
  }, [dragging, draft, x, y]);

  // Each input's change handler updates the layout model with the form state
  // of the last render. Changing x and y in the same tick would therefore send
  // two updates, the first with a stale y. So y is written to the form state
  // first, and x's change handler runs after that has rendered: one update
  // that carries both values.
  useEffect(() => {
    if (pendingX === null) {
      return;
    }
    // No target element is passed: assigning to a controlled input's value
    // would make React restore the old value.
    coordinates.x?.fieldContext?.triggerChange(pendingX);
    setPendingX(null);
  }, [pendingX, coordinates.x]);

  const commit = (next: FocalPoint) => {
    const yName = coordinates.y?.name;
    if (!coordinates.x?.fieldContext || !yName || !formContext) {
      setDraft(null);
      return;
    }
    rhfContext?.setValue(yName, next.y);
    dispatchFieldValue(dispatch, formContext.formId as FormId, yName, next.y);
    setPendingX(next.x);
  };

  const pointFromEvent = (e: React.PointerEvent<HTMLDivElement>) => {
    const rect = e.currentTarget.getBoundingClientRect();
    return {
      x: clampPercent(((e.clientX - rect.left) / rect.width) * 100),
      y: clampPercent(((e.clientY - rect.top) / rect.height) * 100),
    };
  };

  return (
    <FocalPointContext.Provider value={contextValue}>
      <div
        className={styles.frame}
        data-canvas-focal-point-frame
        onPointerDown={(e) => {
          e.preventDefault();
          e.currentTarget.setPointerCapture?.(e.pointerId);
          setDragging(true);
          setDraft(pointFromEvent(e));
        }}
        onPointerMove={(e) => {
          if (dragging) {
            setDraft(pointFromEvent(e));
          }
        }}
        onPointerUp={(e) => {
          if (!dragging) {
            return;
          }
          const next = pointFromEvent(e);
          setDragging(false);
          setDraft(next);
          commit(next);
        }}
      >
        {src ? (
          <img src={src} alt="" className={styles.image} draggable={false} />
        ) : (
          <span className={styles.empty}>No image selected</span>
        )}
        {point && (
          <span
            className={styles.dot}
            style={{ left: `${point.x}%`, top: `${point.y}%` }}
            aria-hidden="true"
          />
        )}
      </div>
      <div className={styles.inputs}>{children}</div>
    </FocalPointContext.Provider>
  );
};

export default FocalPointPicker;
