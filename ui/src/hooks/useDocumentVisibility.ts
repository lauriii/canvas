import { useEffect, useState } from 'react';

export function useDocumentVisibility(): boolean {
  const [isVisible, setIsVisible] = useState(
    document.visibilityState === 'visible',
  );

  useEffect(() => {
    const handler = () => {
      setIsVisible(document.visibilityState === 'visible');
    };
    document.addEventListener('visibilitychange', handler);
    return () => document.removeEventListener('visibilitychange', handler);
  }, []);

  return isVisible;
}
