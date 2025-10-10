import {
  getPreviewHtml,
  getSelectedComponentUuid,
  subscribeToSelectedComponentUuid,
} from '@drupal-canvas/extensions';

document.addEventListener('DOMContentLoaded', async () => {
  // Example: Get preview HTML (one-time).
  const previewHtml = await getPreviewHtml();
  const getElement = document.getElementById('canvas-data-get');
  if (getElement) {
    getElement.textContent = previewHtml
      ? previewHtml.substring(0, 200) + '...'
      : 'No HTML';
  }

  // Example: Subscribe to selected component UUID changes (continuous).
  subscribeToSelectedComponentUuid((uuid) => {
    const subscribeElement = document.getElementById('canvas-data-subscribe');
    if (subscribeElement) {
      subscribeElement.textContent = uuid || 'No component selected';
    }
  });

  // Example: Get selected component UUID (one-time).
  const selectedComponentUuid = await getSelectedComponentUuid();
  const selectedElement = document.getElementById(
    'canvas-data-get-selected-component-uuid',
  );
  if (selectedElement) {
    selectedElement.textContent =
      selectedComponentUuid || 'No component selected';
  }
});
