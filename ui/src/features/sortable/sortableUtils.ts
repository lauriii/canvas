import type React from 'react';

export function customSortableDragImage(
  event: DragEvent | React.DragEvent,
  document: Document,
  name: string,
): void {
  if (document && event) {
    // Create and style the custom drag image
    const customDragImage = document.createElement('div');

    customDragImage.textContent = name || 'Component';
    // CSS here is added inline rather than in css file because the dragImage element may be
    // inserted into the preview iFrame and needs to also be styled in that context.
    customDragImage.style.cssText = `
              all: unset;
              font-family: sans-serif;
              background-color: #fff;
              color: #333;
              width: 200px;
              height: 20px;
              padding: 5px 10px;
              border: 1px solid #333;
              border-radius: 4px;
              box-shadow: 2px 2px rgba(0,0,0,0.2);
              opacity: 0.7;
              position: absolute;
              display: flex;
              justify-content: center;
              top: -9999px;
              pointer-events: none;
            `;
    document.body.appendChild(customDragImage);

    // Set the custom drag image
    event.dataTransfer?.setDragImage(customDragImage, 0, 0);

    // Remove the custom drag image element after a short delay
    window.requestAnimationFrame(() => {
      document?.body.removeChild(customDragImage);
    });
  }
}
