import { CanDeactivateFn } from '@angular/router';

/** Pantalla que puede tener una entrega única sin descargar (Aprobaciones). Definido aparte para no arrastrar la pantalla al paquete inicial. */
export interface HasPendingDelivery {
  hasPendingDelivery(): boolean;
}

/** Al salir con una entrega única sin descargar, pide confirmación: perderla obliga a recrear el lote (revisión P10, hallazgo 6). */
export const pendingDeliveryGuard: CanDeactivateFn<HasPendingDelivery> = (c) =>
  !c.hasPendingDelivery() || confirm('Hay un archivo de entrega única sin descargar. Si sales, se pierde y no se puede recuperar. ¿Salir de todos modos?');
