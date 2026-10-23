import { useEffect, useRef } from 'react';

import TextField from '@/components/form/components/TextField';
import { useFieldContext } from '@/components/form/contexts/FieldContext';
import { withRHF } from '@/components/form/react-hook-form/withRHF';
import { a2p } from '@/local_packages/utils.js';

import { useFocalPointContext } from './FocalPointPicker';

import type { Attributes } from '@/types/DrupalAttribute';

/**
 * The x or y number input of the focal point widget.
 *
 * Renders like any number input, and registers with the surrounding
 * FocalPointPicker so dragging its marker can update this input.
 */
const DrupalFocalPointCoordinate = ({
  attributes = {},
}: {
  attributes?: Attributes;
}) => {
  const focalPoint = useFocalPointContext();
  const fieldContext = useFieldContext();
  const ref = useRef<HTMLDivElement | null>(null);
  const name = String(attributes.name ?? '');
  const axis = name.endsWith('[y]') ? 'y' : 'x';

  useEffect(() => {
    focalPoint?.register(axis, {
      name,
      value: attributes.value,
      element: ref.current?.querySelector('input') ?? null,
      fieldContext,
    });
  }, [focalPoint, axis, name, attributes.value, fieldContext]);

  return (
    <div ref={ref}>
      <TextField
        attributes={{
          ...a2p(attributes, {}, { skipAttributes: ['value'] }),
          value: attributes.value ?? '',
        }}
      />
    </div>
  );
};

export default withRHF(DrupalFocalPointCoordinate);
