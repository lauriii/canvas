import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
} from 'react';
import clsx from 'clsx';
import { ResetIcon } from '@radix-ui/react-icons';

import { useAppDispatch } from '@/app/hooks';
import {
  useSafeFormContext,
  useSafeRHFContext,
} from '@/components/form/contexts/FormContext';
import { dispatchFieldValue } from '@/components/form/react-hook-form/utils';

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
  // The point being dragged or nudged, before it is committed.
  draft: FocalPoint | null;
  // The point used while the image has none of its own.
  inherited: FocalPoint | null;
} | null>(null);

export const useFocalPointContext = () => useContext(FocalPointContext);

// Out of range values are not shown: the mediaFocalPoint transform does not store
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

export const formatFocalPoint = (
  point: FocalPoint | null,
  inherited: FocalPoint | null = null,
) =>
  point ? `${point.x}%, ${point.y}%` : inherited ? 'From media' : 'Not set';

/**
 * Parses an `x,y` focal point, such as the one an image inherits.
 */
export const parseFocalPoint = (value?: string): FocalPoint | null => {
  const [x = null, y = null] = (value ?? '').split(',').map(toPercent);
  return x !== null && y !== null ? { x, y } : null;
};

// Arrow keys move the point by 1%, or 10% with Shift; the change is committed
// once nudging pauses, so holding a key sends one update.
const NUDGE_COMMIT_DELAY = 400;
const NUDGES: Record<string, [number, number]> = {
  ArrowLeft: [-1, 0],
  ArrowRight: [1, 0],
  ArrowUp: [0, -1],
  ArrowDown: [0, 1],
};

/**
 * Shows an image with a draggable focal point marker above the x and y inputs.
 *
 * While the image has no focal point of its own, the marker shows the one it
 * inherits, such as the Focal Point module's point for the media item.
 *
 * The x and y inputs (`children`) are the real, react-hook-form connected
 * form elements. They register with this component so that dragging the
 * marker updates them through the regular form pipeline.
 *
 * @see DrupalFocalPointCoordinate
 */
const FocalPointPicker = ({
  src,
  inherited = null,
  onPointChange,
  children,
}: {
  src?: string;
  inherited?: FocalPoint | null;
  onPointChange?: (
    point: FocalPoint | null,
    registrations: Partial<Record<Axis, CoordinateRegistration>>,
  ) => void;
  children?: ReactNode;
}) => {
  const dispatch = useAppDispatch();
  const formContext = useSafeFormContext();
  const rhfContext = useSafeRHFContext();
  const [coordinates, setCoordinates] = useState<
    Partial<Record<Axis, CoordinateRegistration>>
  >({});
  // The point shown while dragging, and until the inputs have caught up.
  const [draft, setDraft] = useState<FocalPoint | null>(null);
  const [dragging, setDragging] = useState(false);
  // The x value still to commit once the y value has rendered; empty clears.
  const [pendingX, setPendingX] = useState<number | '' | null>(null);
  const nudgeTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  useEffect(() => () => clearTimeout(nudgeTimer.current ?? undefined), []);

  const register = useCallback(
    (axis: Axis, registration: CoordinateRegistration) =>
      setCoordinates((current) => ({ ...current, [axis]: registration })),
    [],
  );

  const x = toPercent(coordinates.x?.value);
  const y = toPercent(coordinates.y?.value);
  const stored = x !== null && y !== null ? { x, y } : null;
  const point = draft ?? stored ?? inherited;
  const isInherited = !draft && !stored && !!inherited;
  const contextValue = useMemo(
    () => ({ register, draft, inherited }),
    [register, draft, inherited],
  );

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

  // Commits a point, or clears it so the image inherits one again.
  const commit = (next: FocalPoint | null) => {
    const yName = coordinates.y?.name;
    if (!coordinates.x?.fieldContext || !yName || !formContext) {
      setDraft(null);
      return;
    }
    const formId = formContext.formId as FormId;
    rhfContext?.setValue(yName, next?.y ?? '');
    dispatchFieldValue(dispatch, formId, yName, next?.y ?? '');
    setPendingX(next?.x ?? '');
  };

  const pointFromEvent = (e: React.PointerEvent<HTMLDivElement>) => {
    const rect = e.currentTarget.getBoundingClientRect();
    return {
      x: clampPercent(((e.clientX - rect.left) / rect.width) * 100),
      y: clampPercent(((e.clientY - rect.top) / rect.height) * 100),
    };
  };

  const nudge = (e: React.KeyboardEvent<HTMLDivElement>) => {
    const direction = NUDGES[e.key];
    if (!direction) {
      return;
    }
    e.preventDefault();
    const step = e.shiftKey ? 10 : 1;
    const next = {
      x: clampPercent((point?.x ?? 50) + direction[0] * step),
      y: clampPercent((point?.y ?? 50) + direction[1] * step),
    };
    setDraft(next);
    clearTimeout(nudgeTimer.current ?? undefined);
    nudgeTimer.current = setTimeout(() => commit(next), NUDGE_COMMIT_DELAY);
  };

  const center = () => {
    setDraft({ x: 50, y: 50 });
    commit({ x: 50, y: 50 });
  };

  const reset = () => {
    setDraft(null);
    commit(null);
  };

  return (
    <FocalPointContext.Provider value={contextValue}>
      <div
        className={clsx(styles.frame, dragging && styles.dragging)}
        data-canvas-focal-point-frame
        role="group"
        tabIndex={0}
        aria-label={`Focal point ${point ? `${point.x}%, ${point.y}%` : 'not set'}${isInherited ? ' from media' : ''}. Drag on the image or use the arrow keys to move it.`}
        onKeyDown={nudge}
        onDoubleClick={center}
        onPointerDown={(e) => {
          // Leave the reset button to its own click.
          if ((e.target as HTMLElement).closest('button')) {
            return;
          }
          e.preventDefault();
          e.currentTarget.focus();
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
        <span className={styles.thirds} aria-hidden="true" />
        {point && (
          <>
            <span
              className={styles.guideX}
              style={{ left: `${point.x}%` }}
              aria-hidden="true"
            />
            <span
              className={styles.guideY}
              style={{ top: `${point.y}%` }}
              aria-hidden="true"
            />
            <span
              className={clsx(styles.dot, isInherited && styles.inherited)}
              style={{ left: `${point.x}%`, top: `${point.y}%` }}
              aria-hidden="true"
            />
            {draft && (
              <span
                className={clsx(
                  styles.readout,
                  point.y < 20 && styles.readoutBelow,
                )}
                style={{
                  left: `${Math.min(80, Math.max(20, point.x))}%`,
                  top: `${point.y}%`,
                }}
                aria-hidden="true"
              >
                {point.x} · {point.y}
              </span>
            )}
          </>
        )}
        {stored && (
          <button
            type="button"
            className={styles.reset}
            onClick={reset}
            aria-label={
              inherited
                ? "Reset focal point to the media's focal point"
                : 'Reset focal point to center'
            }
            title={
              inherited ? "Reset to the media's focal point" : 'Reset to center'
            }
          >
            <ResetIcon />
          </button>
        )}
      </div>
      <div className={styles.inputs}>{children}</div>
    </FocalPointContext.Provider>
  );
};

export default FocalPointPicker;
