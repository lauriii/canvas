import { useEffect } from 'react';
import type { RefObject } from 'react';
import { getDrupal } from '@/utils/drupal-globals';

const Drupal = getDrupal();

export function useDrupalBehaviors(
  ref: RefObject<HTMLElement>,
  dependency: React.ReactNode,
) {
  useEffect(() => {
    let element: HTMLElement | null = null;

    setTimeout(() => {
      if (dependency && ref.current) {
        Drupal.attachBehaviors(ref.current);
        element = ref.current;
      }
    });

    return () => {
      if (element) {
        Drupal.detachBehaviors(element);
      }
    };
  }, [ref, dependency]);
}
