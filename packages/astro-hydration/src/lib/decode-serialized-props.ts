interface CanvasIslandLikeElement {
  getAttribute(qualifiedName: string): string | null;
}

export const decodeSerializedProps = (
  props: Record<string, unknown>,
  element: CanvasIslandLikeElement,
): Record<string, unknown> => {
  const decodedProps: Record<string, unknown> = {};

  for (const [propName, propValue] of Object.entries(props)) {
    if (typeof propValue !== 'string') {
      decodedProps[propName] = propValue;
      continue;
    }

    try {
      decodedProps[propName] = JSON.parse(propValue);
    } catch (error) {
      const uid = element.getAttribute('uid') ?? '<unknown>';
      const componentUrl = element.getAttribute('component-url') ?? '<unknown>';
      console.error(
        `[hydrate] Error decoding prop "${propName}" for component ${componentUrl} (uid ${uid})`,
        propValue,
        error,
      );
      decodedProps[propName] = propValue;
    }
  }

  return decodedProps;
};
