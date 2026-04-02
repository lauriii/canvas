import { CanvasBase } from './canvas/CanvasBase.js';
import { CanvasCodeComponentsMixin } from './canvas/CanvasCodeComponents.js';
import { CanvasComponentsMixin } from './canvas/CanvasComponents.js';
import { CanvasFoldersMixin } from './canvas/CanvasFolders.js';
import { CanvasGlobalRegionsMixin } from './canvas/CanvasGlobalRegions.js';
import { CanvasMediaMixin } from './canvas/CanvasMedia.js';
import { CanvasNavigationMixin } from './canvas/CanvasNavigation.js';
import { CanvasNotificationsMixin } from './canvas/CanvasNotifications.js';
import { CanvasTemplatesMixin } from './canvas/CanvasTemplates.js';
import { CanvasUtilitiesMixin } from './canvas/CanvasUtilities.js';

export class Canvas extends CanvasNotificationsMixin(
  CanvasFoldersMixin(
    CanvasMediaMixin(
      CanvasCodeComponentsMixin(
        CanvasComponentsMixin(
          CanvasNavigationMixin(
            CanvasTemplatesMixin(
              CanvasUtilitiesMixin(CanvasGlobalRegionsMixin(CanvasBase)),
            ),
          ),
        ),
      ),
    ),
  ),
) {}
