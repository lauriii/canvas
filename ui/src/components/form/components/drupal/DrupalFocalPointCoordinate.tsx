import { useEffect, useRef, useState } from 'react';

import TextField from '@/components/form/components/TextField';
import { useFieldContext } from '@/components/form/contexts/FieldContext';
import { withRHF } from '@/components/form/react-hook-form/withRHF';
import { a2p } from '@/local_packages/utils.js';

import { useFocalPointContext } from './FocalPointPicker';

import type { Attributes } from '@/types/DrupalAttribute';

import styles from './FocalPointPicker.module.css';

// How far the pointer moves, in pixels, to scrub the value by 1%.
const SCRUB_PIXELS_PER_PERCENT = 2;

/**
 * The x or y number input of the focal point widget.
 *
 * Shows its axis and unit inside the field; dragging the axis label sideways
 * scrubs the value. Registers with the surrounding FocalPointPicker so
 * dragging its marker can update this input.
 */
const DrupalFocalPointCoordinate = ({
  attributes = {},
}: {
  attributes?: Attributes;
}) => {
  const focalPoint = useFocalPointContext();
  const fieldContext = useFieldContext();
  const ref = useRef<HTMLDivElement | null>(null);
  const scrubStart = useRef<{ x: number; value: number } | null>(null);
  const [scrubValue, setScrubValue] = useState<number | null>(null);
  const name = String(attributes.name ?? '');
  const axis = name.endsWith('[y]') ? 'y' : 'x';

  const register = focalPoint?.register;
  useEffect(() => {
    register?.(axis, {
      name,
      value: scrubValue ?? attributes.value,
      element: ref.current?.querySelector('input') ?? null,
      fieldContext,
    });
  }, [register, axis, name, attributes.value, scrubValue, fieldContext]);

  const scrubTo = (e: React.PointerEvent) => {
    if (!scrubStart.current) {
      return null;
    }
    const delta = (e.clientX - scrubStart.current.x) / SCRUB_PIXELS_PER_PERCENT;
    return Math.round(
      Math.min(100, Math.max(0, scrubStart.current.value + delta)),
    );
  };

  return (
    <div ref={ref} className={styles.coordinate}>
      <span
        className={styles.axis}
        aria-hidden="true"
        onPointerDown={(e) => {
          e.preventDefault();
          e.currentTarget.setPointerCapture?.(e.pointerId);
          scrubStart.current = {
            x: e.clientX,
            value: Number(attributes.value) || 0,
          };
        }}
        onPointerMove={(e) => {
          const value = scrubTo(e);
          if (value !== null) {
            setScrubValue(value);
          }
        }}
        onPointerUp={(e) => {
          const value = scrubTo(e);
          scrubStart.current = null;
          setScrubValue(null);
          if (value !== null && value !== Number(attributes.value)) {
            fieldContext?.triggerChange(value);
          }
        }}
      >
        {axis.toUpperCase()}
      </span>
      <TextField
        attributes={{
          ...a2p(attributes, {}, { skipAttributes: ['value'] }),
          value:
            scrubValue ?? focalPoint?.draft?.[axis] ?? attributes.value ?? '',
          placeholder: focalPoint?.inherited?.[axis]?.toString(),
        }}
      />
      <span className={styles.unit} aria-hidden="true">
        %
      </span>
    </div>
  );
};

export default withRHF(DrupalFocalPointCoordinate);
