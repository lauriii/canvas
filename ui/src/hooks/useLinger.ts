import { useEffect, useState } from 'react';

/**
 * Stays true for a moment after its input goes false.
 *
 * For chrome that floats outside the thing it belongs to and holds a control:
 * the pointer has to travel to reach that control, and a path that clips the
 * corner of the element leaves it before arriving. Without a grace period the
 * control is snatched away mid-reach, which reads as the affordance being
 * broken rather than merely fussy.
 */
export const useLinger = (active: boolean, ms = 200): boolean => {
  const [lingering, setLingering] = useState(active);

  useEffect(() => {
    if (active) {
      setLingering(true);
      return;
    }
    const timer = setTimeout(() => setLingering(false), ms);
    return () => clearTimeout(timer);
  }, [active, ms]);

  return active || lingering;
};

export default useLinger;
