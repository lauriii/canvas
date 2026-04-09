import { useCallback, useEffect, useRef } from 'react';

const InfiniteScrollObserver = ({
  onLoadMore,
}: {
  onLoadMore?: () => void;
}) => {
  const observedRef = useRef<HTMLDivElement>(null);
  const onLoadMoreRef = useRef(onLoadMore);
  onLoadMoreRef.current = onLoadMore;

  const handleIntersect = useCallback(
    (entries: IntersectionObserverEntry[]) => {
      if (entries[0].isIntersecting) {
        onLoadMoreRef.current?.();
      }
    },
    [],
  );

  useEffect(() => {
    const el = observedRef.current;
    if (!el) return;
    const observer = new IntersectionObserver(handleIntersect, {
      rootMargin: '100px',
    });
    observer.observe(el);
    return () => observer.disconnect();
  }, [handleIntersect]);

  return <div ref={observedRef} style={{ height: 1 }} />;
};

export default InfiniteScrollObserver;
