import { createContext } from 'react';
import type { ComponentsMap, SlotsMap } from '@/types/AnnotationMaps';

// Create a Context with default values (could be null or empty objects)
const ComponentHtmlMapContext = createContext<{
  slotsMap: SlotsMap;
  componentsMap: ComponentsMap;
}>({
  slotsMap: {},
  componentsMap: {},
});

export default ComponentHtmlMapContext;
