// Drag and drop functionality for e2e Cypress tests using cypress-real-events.
// @see https://github.com/dmtrKovalenko/cypress-real-events/pull/17
import { fireCdpCommand } from "cypress-real-events/fireCdpCommand";
import {
  getCypressElementCoordinates,
} from "cypress-real-events/getCypressElementCoordinates";

function isJQuery(obj) {
  return Boolean(obj.jquery);
}

export async function realDnd(
  subject,
  destination,
  options = {}
) {
  if (!destination) {
    throw new Error(
      "destination is required when using cy.realDnd(destination)"
    );
  }

  const startCoords = getCypressElementCoordinates(subject, options.position);
  const endCoords = isJQuery(destination)
    ? getCypressElementCoordinates(destination, options.position)
    : destination;

  const log = Cypress.log({
    $el: subject,
    name: "realClick",
    consoleProps: () => ({
      Dragged: subject.get(0),
      From: startCoords,
      End: endCoords,
    }),
  });

  log.snapshot("before");
  await fireCdpCommand("Input.dispatchMouseEvent", {
    type: "mousePressed",
    ...startCoords,
    clickCount: 1,
    buttons: 1,
    pointerType: options.pointer ?? "mouse",
    button: "left",
  });

  console.log(endCoords)
  await fireCdpCommand("Input.dispatchMouseEvent", {
    ...endCoords,
    type: "mouseMoved",
    button: "left",
    pointerType: options.pointer ?? "mouse",
  });

  await fireCdpCommand("Input.dispatchMouseEvent", {
    type: "mouseReleased",
    ...endCoords,
    clickCount: 1,
    buttons: 1,
    pointerType: options.pointer ?? "mouse",
    button: "left",
  });

  log.snapshot("after").end();

  return subject;
}
