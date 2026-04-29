// Drag and drop functionality for e2e Cypress tests using cypress-real-events.
// @see https://github.com/dmtrKovalenko/cypress-real-events/pull/17
import { fireCdpCommand } from 'cypress-real-events/fireCdpCommand.js';
import { getCypressElementCoordinates } from 'cypress-real-events/getCypressElementCoordinates.js';

function isJQuery(obj) {
  return Boolean(obj.jquery);
}

export async function realDnd(subject, destination, options = {}) {
  if (!destination) {
    throw new Error(
      'destination is required when using cy.realDnd(destination)',
    );
  }

  await new Promise((resolve) =>
    setTimeout(resolve, options?.initialWait ?? 1000),
  );

  const startCoords = getCypressElementCoordinates(
    subject,
    options.position,
    options.scrollBehavior,
  );
  const endCoords = isJQuery(destination)
    ? getCypressElementCoordinates(
        destination,
        options.position,
        options.scrollBehavior,
      )
    : destination;

  await new Cypress.Promise((resolve, reject) => {
    const timeout = Cypress.config('defaultCommandTimeout');
    const interval = 50;
    const start = Date.now();

    const check = () => {
      const pointerEvents = window.getComputedStyle(
        subject.get(0),
      ).pointerEvents;
      if (pointerEvents !== 'none') {
        resolve();
      } else if (Date.now() - start >= timeout) {
        reject(
          new Error(
            'realDnd: timed out waiting for subject to not have pointer-events: none',
          ),
        );
      } else {
        setTimeout(check, interval);
      }
    };

    check();
  });

  const log = Cypress.log({
    $el: subject,
    name: 'realClick',
    consoleProps: () => ({
      Dragged: subject.get(0),
      From: startCoords,
      End: endCoords,
    }),
  });
  await new Promise((resolve) =>
    setTimeout(resolve, options?.preClickWait || 200),
  );

  log.snapshot('before');
  await fireCdpCommand('Input.dispatchMouseEvent', {
    type: 'mousePressed',
    ...startCoords,
    clickCount: 1,
    buttons: 1,
    pointerType: options.pointer ?? 'mouse',
    button: 'left',
  });
  await new Promise((resolve) =>
    setTimeout(resolve, options?.preMoveWait || 200),
  );

  console.log(endCoords);
  await fireCdpCommand('Input.dispatchMouseEvent', {
    ...endCoords,
    type: 'mouseMoved',
    button: 'left',
    pointerType: options.pointer ?? 'mouse',
  });

  await new Promise((resolve) =>
    setTimeout(resolve, options?.preReleaseWait || 200),
  );

  await fireCdpCommand('Input.dispatchMouseEvent', {
    type: 'mouseReleased',
    ...endCoords,
    clickCount: 1,
    buttons: 1,
    pointerType: options.pointer ?? 'mouse',
    button: 'left',
  });
  await new Promise((resolve) => setTimeout(resolve, 200));

  log.snapshot('after').end();

  return subject;
}
