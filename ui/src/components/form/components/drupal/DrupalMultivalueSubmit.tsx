import { a2p } from '@/local_packages/utils';

import type { Attributes } from '@/types/DrupalAttribute';

const DrupalMultivalueSubmit = ({ attributes }: { attributes: Attributes }) => {
  return (
    <input
      type="submit"
      {...a2p(attributes)}
      value={attributes.value || ''}
      ref={(node) => {
        if (
          attributes?.['data-multivalue-add-new'] &&
          node &&
          !(node as any).__listenersAdded
        ) {
          (node as any).__listenersAdded = true;
          // Add vanilla event listener - on* attributes added to this element
          // were not responding.
          node.addEventListener('mousedown', () => {
            document.body.setAttribute('data-canvas-ajax-behaviors', 'true');
            const outerWrapper = node.closest('[data-canvas-multiple-values]');
            const tbody =
              outerWrapper &&
              outerWrapper.querySelector('table.field-multiple-table tbody');
            if (tbody && attributes.name) {
              // Clone the last real row so the skeleton matches the exact column
              // widths, classes, and padding already in the table.
              const lastRow = tbody.querySelector('tr.draggable:last-child');
              setTimeout(() => {
                if (
                  lastRow &&
                  !lastRow.hasAttribute('[data-canvas-optimistic]')
                ) {
                  const skeleton = lastRow.cloneNode(true) as Element;
                  skeleton.setAttribute('data-canvas-optimistic', 'add');
                  skeleton.querySelectorAll('input').forEach(function (
                    input: HTMLInputElement,
                  ) {
                    input.value = '';
                    input.checked = false;
                  });

                  // Remove the weight select from the skeleton entirely — it
                  // must not be submitted as part of the AJAX POST because its
                  // cloned name/value would duplicate a real row's weight entry
                  // and corrupt PHP's sort order.
                  skeleton
                    .querySelectorAll('[name*="_weight"]')
                    .forEach((el) => el.removeAttribute('name'));

                  // Replace the content of the input cell (2nd td) with a shimmer.
                  // The input cell is the one that contains the custom element / text.
                  const inputCell = skeleton.querySelector('td:nth-child(2)');
                  if (inputCell) {
                    inputCell.innerHTML = '';
                    const shimmer = document.createElement('div');
                    shimmer.className = 'canvas-skeleton-shimmer';
                    inputCell.appendChild(shimmer);
                  }

                  // Empty the actions cell so no stale remove button is cloned.
                  const actionsCell = skeleton.querySelector(
                    '.canvas-remove-action',
                  );
                  if (actionsCell) {
                    actionsCell.innerHTML = '';
                  }
                  tbody.appendChild(skeleton);
                }
              });
            }
          });
        }
      }}
    />
  );
};

export default DrupalMultivalueSubmit;
