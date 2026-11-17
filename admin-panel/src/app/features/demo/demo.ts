import { NgTemplateOutlet } from '@angular/common';
import { Component, DestroyRef, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { Api, ApiError } from '../../core/api';
import { DemoAccountList, DemoAccountRow, DemoConfig, DemoMixEntry, DemoMode, DemoPlan, DemoRun, DemoState, PRIZE_TYPE_LABEL, PrizeType, ResetAccounts, canonical, isPlans, mailMode, mixTotals, percent, planForAmount, planProbability, planTotals, poolSize, previewPurchase, relinkPlans, toPlansMode, toStepsMode, validateDemo } from '../../core/demo';
import { dateBogota } from '../../core/format';
import { GameType } from '../../core/models';
import { ErrorState } from '../../shared/state';
import { GAME_LABEL } from '../campaigns/campaign-list';

type Confirm = 'enable' | 'disable' | 'reset' | null;

const POLL_MS = 2000;
const ACCOUNTS_PER_PAGE = 20;

/**
 * Muestra real (SPEC-006, solo superadmin): escenario, reglas de carga, mezcla de premios, estado e interruptor. El servidor valida todo
 * y manda; aquí solo se ayuda (totales en vivo, mensajes). Encender, apagar y reiniciar piden confirmación en la propia página.
 */
@Component({
  selector: 'app-demo',
  imports: [FormsModule, MatButtonModule, NgTemplateOutlet, ErrorState],
  styles: `
    h2 { font-size: 16px; margin: 0 0 8px; }
    section { background: var(--gp-surface); border-radius: 12px; padding: 16px; margin: 16px 0; }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 12px; }
    label.f { display: flex; flex-direction: column; gap: 4px; font-size: 12px; color: var(--gp-muted); }
    input, select { font: inherit; padding: 6px 8px; border-radius: 8px; border: 1px solid #9aa3b5; background: var(--gp-surface); color: var(--gp-navy); }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--gp-background); vertical-align: middle; }
    th { color: var(--gp-muted); font-weight: 600; }
    .table-wrap { overflow-x: auto; }
    .chip { display: inline-block; padding: 2px 10px; border-radius: 10px; font-weight: 700; background: var(--gp-background); }
    .chip.on { background: var(--gp-navy); color: #fff; }
    .chip.expired { background: var(--gp-warning-surface); color: var(--gp-warning); }
    .actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; align-items: center; }
    .game { border: 1px solid var(--gp-background); border-radius: 8px; padding: 12px; margin: 12px 0; }
    .game h3 { margin: 0 0 8px; font-size: 14px; display: flex; gap: 12px; align-items: center; }
    .total { margin-left: auto; font-weight: 400; }
    .errors { color: var(--gp-error); margin: 8px 0 0; padding-left: 18px; }
    .ok { color: var(--gp-navy); font-weight: 600; }
    .confirm { margin-top: 12px; }
    .choices { display: flex; flex-direction: column; gap: 6px; margin: 8px 0; border: 0; padding: 0; }
    .choices legend { font-weight: 600; padding: 0; margin-bottom: 4px; }
    .choices label { display: flex; gap: 8px; align-items: center; }
    .pager { display: flex; gap: 8px; align-items: center; margin-top: 8px; }
    .num { width: 120px; }
    .wide-mail { width: 280px; max-width: 100%; }
    .wide { width: 100%; box-sizing: border-box; }
    .plan { border: 1px solid var(--gp-background); border-radius: 8px; padding: 12px; margin: 12px 0; }
    .plan h3 { margin: 0 0 8px; font-size: 14px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
    label.f.check { justify-content: flex-end; flex-direction: row; align-items: center; color: var(--gp-navy); font-size: 13px; }
    dl { display: grid; grid-template-columns: max-content 1fr; gap: 4px 16px; margin: 0; }
    dt { color: var(--gp-muted); }
    dd { margin: 0; }
  `,
  template: `
    <h1>Muestra</h1>
    <p class="gp-muted">Escenario de demostración: un usuario simulado entra, compra en línea, recibe saldo y un código sorteado al azar de un lote cuyo resultado ya está decidido. Solo se enciende desde aquí.</p>
    <div role="status" aria-live="polite">
      @if (message()) { <p class="ok" data-testid="message">{{ message() }}</p> }
      @if (fail()) { <div class="gp-banner error" role="alert" data-testid="fail">{{ fail() }}</div> }
    </div>

    @if (error()) {
      <app-error-state [message]="error()!" (retry)="load()" />
    } @else if (state() === null) {
      <p class="gp-muted" role="status">Cargando…</p>
    } @else {
      @let st = state()!;
      @let d = draft()!;
      <section aria-labelledby="h-estado">
        <h2 id="h-estado">Estado</h2>
        <p>
          <span class="chip" [class.on]="st.status.active" [class.expired]="st.status.enabled && !st.status.active" data-testid="status-chip">{{ chipLabel() }}</span>
          @if (st.status.active && st.status.enabledUntil) { <span class="gp-muted"> Caduca el {{ bogota(st.status.enabledUntil) }}</span> }
        </p>
        @if (!st.status.serverAllowed) {
          <div class="gp-banner warning" role="alert" data-testid="not-allowed">Este servidor no es de demostración (falta GAMIF_DEMO_ALLOWED=true): la muestra no puede sembrarse ni encenderse.</div>
        }
        <dl>
          <dt>Códigos disponibles</dt><dd data-testid="pool-available">{{ st.pool.available }}</dd>
          <dt>Entregados</dt><dd data-testid="pool-assigned">{{ st.pool.assigned }}</dd>
          <dt>Retirados</dt><dd data-testid="pool-retired">{{ st.pool.retired }}</dd>
          <dt>Última corrida</dt>
          <dd data-testid="last-run">{{ runLabel(st.lastRun) }}</dd>
        </dl>
        @if (st.poolByPlan.length > 0) {
          <h3 class="gp-muted">Existencias por plan</h3>
          <div class="table-wrap">
            <table>
              <caption class="gp-visually-hidden">Códigos disponibles y entregados por plan</caption>
              <thead><tr><th scope="col">Plan</th><th scope="col">Disponibles</th><th scope="col">Entregados</th><th scope="col">Probabilidad de premio</th></tr></thead>
              <tbody>
                @for (p of st.poolByPlan; track p.id) {
                  <tr data-testid="plan-stock-row" [attr.data-plan]="p.id">
                    <td>{{ p.name }}</td><td data-testid="plan-available">{{ p.available }}</td><td data-testid="plan-assigned">{{ p.assigned }}</td><td>{{ pct(p.probability) }}</td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
        }
        @if (st.lastRun?.state === 'failed' && st.lastRun?.error) { <p class="gp-banner error" role="alert" data-testid="run-error">{{ st.lastRun!.error }}</p> }
        <div class="actions">
          <button mat-stroked-button type="button" (click)="seed()" [disabled]="!canSeed()" data-testid="seed">Sembrar</button>
          <button mat-stroked-button type="button" (click)="ask('reset')" [disabled]="!canReset()" data-testid="reset">Reiniciar</button>
          @if (st.status.enabled) {
            <button mat-flat-button type="button" (click)="ask('disable')" [disabled]="busy()" data-testid="disable">Apagar</button>
          } @else {
            <button mat-flat-button type="button" (click)="ask('enable')" [disabled]="!canEnable()" data-testid="enable">Encender</button>
          }
        </div>
        @if (dirty()) { <p class="gp-muted" data-testid="dirty-note">Hay cambios sin guardar: guárdalos antes de sembrar, reiniciar o encender.</p> }
        @if (confirm(); as c) {
          <div class="gp-banner warning confirm" role="alertdialog" aria-labelledby="confirm-text" data-testid="confirm">
            <span id="confirm-text">{{ confirmText(c) }}</span>
            @if (c === 'reset') {
              <fieldset class="choices" data-testid="reset-choice">
                <legend>¿Qué pasa con las cuentas de la muestra y sus saldos?</legend>
                <label><input type="radio" name="resetAccounts" value="keep" [ngModel]="resetAccounts()" (ngModelChange)="resetAccounts.set($event)" data-testid="reset-keep" /> Conservar cuentas y saldos (las personas vuelven a entrar con su correo)</label>
                <label><input type="radio" name="resetAccounts" value="delete" [ngModel]="resetAccounts()" (ngModelChange)="resetAccounts.set($event)" data-testid="reset-delete" /> Borrar todas las cuentas (los saldos acumulados dejan de ser alcanzables)</label>
              </fieldset>
            }
            <button mat-flat-button type="button" (click)="doConfirm()" [disabled]="busy() || (c === 'reset' && resetAccounts() === null)" data-testid="confirm-yes">Confirmar</button>
            <button mat-button type="button" (click)="confirm.set(null)" data-testid="confirm-no">Cancelar</button>
          </div>
        }
      </section>

      <section aria-labelledby="h-cuentas">
        <h2 id="h-cuentas">Cuentas</h2>
        <p class="gp-muted">Cada persona entra con su correo y un código de un solo uso; su saldo y sus códigos se conservan entre visitas. Aquí el correo se ve siempre enmascarado.</p>
        <dl>
          <dt>Cuentas</dt><dd data-testid="accounts-total">{{ st.accounts.total }}</dd>
          <dt>Con ingreso en los últimos 7 días</dt><dd data-testid="accounts-active">{{ st.accounts.active7d }}</dd>
          <dt>Envío de correo</dt><dd data-testid="mail-mode">{{ mailLabel() }}</dd>
        </dl>
        @if (!mailSends()) { <div class="gp-banner warning" role="alert" data-testid="mail-warning">Este servidor no envía correos reales: las personas no recibirán su código. Configura MAIL_MAILER (SMTP o Mailpit) en el servidor.</div> }
        <div class="actions">
          <label class="f">Enviar un correo de prueba a<input type="email" class="wide-mail" name="mailTo" autocomplete="off" [ngModel]="mailTo()" (ngModelChange)="mailTo.set($event)" data-testid="mail-to" /></label>
          <button mat-stroked-button type="button" (click)="sendTest()" [disabled]="busy() || mailTo().trim() === ''" data-testid="mail-test">Enviar correo de prueba</button>
        </div>
        <div class="table-wrap">
          <table>
            <caption class="gp-visually-hidden">Cuentas de la muestra</caption>
            <thead><tr><th scope="col">Correo</th><th scope="col">Saldo</th><th scope="col">Creada</th><th scope="col">Último ingreso</th><th scope="col"><span class="gp-visually-hidden">Acción</span></th></tr></thead>
            <tbody>
              @for (a of accounts()?.items ?? []; track a.id) {
                <tr data-testid="account-row">
                  <td>{{ a.email }}</td><td>{{ money(a.balance) }}</td><td>{{ bogota(a.createdAt) }}</td><td>{{ bogota(a.lastLoginAt) }}</td>
                  <td>
                    @if (savedPlans()) {
                      <button mat-button type="button" (click)="simTarget.set(a)" [disabled]="busy()" [attr.aria-label]="'Simular una semana sin comprar para la cuenta ' + a.email" data-testid="account-simulate">Simular una semana sin comprar</button>
                    }
                    <button mat-button type="button" (click)="delTarget.set(a)" [disabled]="busy()" [attr.aria-label]="'Borrar la cuenta ' + a.email" data-testid="account-delete">Borrar cuenta</button>
                  </td>
                </tr>
              } @empty {
                <tr><td colspan="5" class="gp-muted" data-testid="accounts-empty">Todavía no hay cuentas.</td></tr>
              }
            </tbody>
          </table>
        </div>
        @if (pages() > 1) {
          <div class="pager">
            <button mat-button type="button" (click)="goPage(-1)" [disabled]="page() <= 1" data-testid="accounts-prev">Anterior</button>
            <span data-testid="accounts-page">Página {{ page() }} de {{ pages() }}</span>
            <button mat-button type="button" (click)="goPage(1)" [disabled]="page() >= pages()" data-testid="accounts-next">Siguiente</button>
          </div>
        }
        @if (simTarget(); as t) {
          <div class="gp-banner warning confirm" role="alertdialog" aria-labelledby="sim-text" data-testid="simulate-confirm">
            <span id="sim-text">Se marcará la cuenta {{ t.email }} como si llevara {{ st.config.triggers?.reactivationDays }} días sin comprar: en su próximo ingreso recibirá el turno de reactivación. Queda en la bitácora. ¿Simular?</span>
            <button mat-flat-button type="button" (click)="simulate()" [disabled]="busy()" data-testid="simulate-confirm-yes">Simular</button>
            <button mat-button type="button" (click)="simTarget.set(null)" data-testid="simulate-confirm-no">Cancelar</button>
          </div>
        }
        @if (delTarget(); as t) {
          <div class="gp-banner warning confirm" role="alertdialog" aria-labelledby="del-text" data-testid="account-confirm">
            <span id="del-text">Se borrará la cuenta {{ t.email }}: esa persona ya no podrá ingresar y su saldo ({{ money(t.balance) }}) quedará inaccesible. ¿Borrar?</span>
            <button mat-flat-button type="button" (click)="deleteAccount()" [disabled]="busy()" data-testid="account-confirm-yes">Borrar</button>
            <button mat-button type="button" (click)="delTarget.set(null)" data-testid="account-confirm-no">Cancelar</button>
          </div>
        }
      </section>

      <form (input)="touch()" (change)="touch()" (submit)="$event.preventDefault()" aria-label="Configuración de la muestra">
        <section aria-labelledby="h-modo">
          <h2 id="h-modo">Cómo se reparten los turnos</h2>
          <fieldset class="choices" data-testid="mode-choice">
            <legend class="gp-visually-hidden">Modo de la muestra</legend>
            <label><input type="radio" name="mode" value="plans" [ngModel]="mode()" (ngModelChange)="setMode($event)" data-testid="mode-plans" /> Por planes (Ruleta de Aguinaldos): el monto de la compra, el primer ingreso o una semana sin comprar deciden qué plan de premios se juega</label>
            <label><input type="radio" name="mode" value="steps" [ngModel]="mode()" (ngModelChange)="setMode($event)" data-testid="mode-steps" /> Por pasos (anterior): un código por cada tramo de monto, todos de la misma mezcla</label>
          </fieldset>
          @if (modeChanged()) { <p class="gp-muted" data-testid="mode-note">Cambiar de modo no toca el pool ya sembrado: guarda y vuelve a sembrar (Reiniciar) para que aplique.</p> }
        </section>
        <section aria-labelledby="h-escenario">
          <h2 id="h-escenario">Escenario</h2>
          <div class="grid">
            <label class="f">Nombre del usuario simulado<input name="displayName" [(ngModel)]="d.user.displayName" maxlength="60" data-testid="displayName" /></label>
            <label class="f">Prefijo del id de host<input name="prefix" [(ngModel)]="d.user.hostUserPrefix" maxlength="32" data-testid="prefix" /></label>
            <label class="f">Saldo inicial (COP)<input class="num" type="number" step="1" min="0" name="initialBalance" [(ngModel)]="d.user.initialBalance" data-testid="initialBalance" /></label>
            <label class="f">Caducidad de la muestra (horas, vacío = sin caducar)<input class="num" type="number" step="1" min="1" name="ttl" [ngModel]="d.ttlHours" (ngModelChange)="setTtl($event)" data-testid="ttl" /></label>
          </div>
          <h2 class="gp-muted">Catálogo</h2>
          <div class="table-wrap">
            <table>
              <caption class="gp-visually-hidden">Productos de la tienda de muestra</caption>
              <thead><tr><th scope="col">Id</th><th scope="col">Nombre</th><th scope="col">Precio (COP)</th>@if (plans()) { <th scope="col">Participa</th> }<th scope="col"><span class="gp-visually-hidden">Acción</span></th></tr></thead>
              <tbody>
                @for (p of d.products; track $index; let i = $index) {
                  <tr data-testid="product-row">
                    <td><input [name]="'pid' + i" [(ngModel)]="p.id" maxlength="32" [attr.aria-label]="'Id del producto ' + (i + 1)" /></td>
                    <td><input [name]="'pname' + i" [(ngModel)]="p.name" maxlength="80" [attr.aria-label]="'Nombre del producto ' + (i + 1)" /></td>
                    <td><input class="num" type="number" step="1" min="1" [name]="'pprice' + i" [(ngModel)]="p.price" [attr.aria-label]="'Precio del producto ' + (i + 1)" /></td>
                    @if (plans()) { <td><input type="checkbox" [name]="'pparticipates' + i" [ngModel]="p.participates !== false" (ngModelChange)="p.participates = $event" [attr.aria-label]="'El producto ' + (i + 1) + ' participa'" data-testid="participates" /></td> }
                    <td><button mat-button type="button" (click)="removeProduct(i)" [disabled]="d.products.length <= 1" [attr.aria-label]="'Quitar el producto ' + (i + 1)">Quitar</button></td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
          <div class="actions"><button mat-stroked-button type="button" (click)="addProduct()" [disabled]="d.products.length >= st.limits.maxProducts" data-testid="add-product">Agregar producto</button></div>
        </section>

        <section aria-labelledby="h-reglas">
          <h2 id="h-reglas">Reglas de carga</h2>
          <div class="grid">
            <label class="f">Saldo cargado (% de la compra)<input class="num" type="number" step="1" min="0" max="100" name="pct" [(ngModel)]="d.credit.balancePercent" data-testid="balancePercent" /></label>
            @if (!plans()) {
              <label class="f">Compra mínima (COP)<input class="num" type="number" step="1" min="0" name="minPurchase" [(ngModel)]="d.credit.minPurchase" data-testid="minPurchase" /></label>
              <label class="f">Un código por cada (COP)<input class="num" type="number" step="1" min="1" name="step" [(ngModel)]="d.credit.codeStepAmount" data-testid="codeStepAmount" /></label>
              <label class="f">Máximo de códigos por compra<input class="num" type="number" step="1" min="1" [max]="st.limits.maxCodesPerPurchase" name="perPurchase" [(ngModel)]="d.credit.maxCodesPerPurchase" data-testid="maxCodesPerPurchase" /></label>
            }
            <label class="f">Máximo de códigos por sesión<input class="num" type="number" step="1" min="1" max="50" name="perSession" [(ngModel)]="d.credit.maxCodesPerSession" data-testid="maxCodesPerSession" /></label>
          </div>
          @if (plans()) {
            <h2 class="gp-muted">Vista previa por producto</h2>
            <div class="table-wrap">
              <table>
                <caption class="gp-visually-hidden">Plan que recibe quien compra cada producto</caption>
                <thead><tr><th scope="col">Producto</th><th scope="col">Precio</th><th scope="col">Saldo cargado</th><th scope="col">Plan de la ruleta</th></tr></thead>
                <tbody>
                  @for (r of planPreview(); track $index) {
                    <tr data-testid="preview-row"><td>{{ r.name }}</td><td>{{ money(r.price) }}</td><td>{{ money(r.balance) }}</td><td>{{ r.plan }}</td></tr>
                  }
                </tbody>
              </table>
            </div>
          } @else {
          <h2 class="gp-muted">Vista previa por producto</h2>
            <div class="table-wrap">
              <table>
                <caption class="gp-visually-hidden">Lo que recibe quien compra cada producto</caption>
                <thead><tr><th scope="col">Producto</th><th scope="col">Precio</th><th scope="col">Saldo cargado</th><th scope="col">Códigos</th></tr></thead>
                <tbody>
                  @for (r of preview(); track $index) {
                    <tr data-testid="preview-row">
                      <td>{{ r.name }}</td><td>{{ money(r.price) }}</td>
                      @if (r.allowed) { <td>{{ money(r.balance) }}</td><td>{{ r.codes }}</td> } @else { <td colspan="2" class="gp-muted">Por debajo de la compra mínima</td> }
                    </tr>
                  }
                </tbody>
              </table>
            </div>
          }
        </section>

        <section aria-labelledby="h-parametros">
          <h2 id="h-parametros">Parámetros de las cuentas</h2>
          <div class="grid">
            <label class="f">Borrar cuentas sin ingresar durante (días)<input class="num" type="number" step="1" min="1" [max]="st.limits.maxRetentionDays" name="retentionDays" [(ngModel)]="d.accounts.retentionDays" data-testid="retentionDays" /></label>
            <label class="f">Vigencia del código de ingreso (minutos)<input class="num" type="number" step="1" [min]="st.limits.minCodeTtlMinutes" [max]="st.limits.maxCodeTtlMinutes" name="codeTtlMinutes" [(ngModel)]="d.accounts.codeTtlMinutes" data-testid="codeTtlMinutes" /></label>
            <label class="f">Máximo de cuentas<input class="num" type="number" step="1" min="1" [max]="st.limits.maxAccounts" name="maxAccounts" [(ngModel)]="d.accounts.maxAccounts" data-testid="maxAccounts" /></label>
          </div>
        </section>

        <ng-template #mixTpl let-mix let-pfx="pfx" let-label="label">
          @for (g of gameKeysOf(mix); track g) {
            @let e = mix[g]!;
            <div class="game" data-testid="mix-game" [attr.data-game]="g">
              <h3>{{ lab(label, g) }}
                <span class="total gp-muted" data-testid="mix-total">{{ totals(e).winners }} con premio + {{ totals(e).none }} sin premio = {{ totals(e).total }} códigos</span>
                <button mat-button type="button" (click)="removeGameFrom(mix, g)" [disabled]="gameKeysOf(mix).length <= 1" [attr.aria-label]="'Quitar ' + lab(label, g)">Quitar juego</button>
              </h3>
              <div class="table-wrap">
                <table>
                  <caption class="gp-visually-hidden">Premios de {{ lab(label, g) }}</caption>
                  <thead><tr><th scope="col">Tipo</th><th scope="col">Monto</th><th scope="col">Cantidad</th><th scope="col"><span class="gp-visually-hidden">Acción</span></th></tr></thead>
                  <tbody>
                    @for (p of e.prizes; track $index; let j = $index) {
                      <tr data-testid="prize-row">
                        <td>
                          <select [name]="pfx + g + 'type' + j" [(ngModel)]="p.type" [attr.aria-label]="lab(label, g) + ' premio ' + (j + 1) + ': tipo'">
                            @for (t of prizeTypes; track t[0]) { <option [value]="t[0]">{{ t[1] }}</option> }
                          </select>
                        </td>
                        <td><input class="num" type="number" step="1" min="0" [name]="pfx + g + 'amount' + j" [(ngModel)]="p.amount" [attr.aria-label]="lab(label, g) + ' premio ' + (j + 1) + ': monto'" /></td>
                        <td><input class="num" type="number" step="1" min="1" [name]="pfx + g + 'count' + j" [(ngModel)]="p.count" [attr.aria-label]="lab(label, g) + ' premio ' + (j + 1) + ': cantidad'" /></td>
                        <td><button mat-button type="button" (click)="removePrize(e, j)" [attr.aria-label]="'Quitar el premio ' + (j + 1) + ' de ' + lab(label, g)">Quitar</button></td>
                      </tr>
                    }
                  </tbody>
                </table>
              </div>
              <div class="actions">
                <button mat-stroked-button type="button" (click)="addPrize(e)" [attr.data-testid]="'add-prize-' + pfx + g">Agregar premio</button>
                <label class="f">Códigos sin premio<input class="num" type="number" step="1" min="0" [name]="pfx + g + 'none'" [(ngModel)]="e.noPrizeCount" [attr.data-testid]="'none-' + pfx + g" /></label>
              </div>
            </div>
          }
          @if (freeGamesOf(mix).length > 0) {
            <div class="actions">
              <label class="f">Agregar juego{{ label ? ' a ' + label : '' }}
                <select [name]="pfx + 'newGame'" [ngModel]="''" (ngModelChange)="addGameTo(mix, $event)" [attr.data-testid]="'add-game' + (pfx ? '-' + pfx : '')">
                  <option value="">Elegir…</option>
                  @for (g of freeGamesOf(mix); track g) { <option [value]="g">{{ gameLabel[g] }}</option> }
                </select>
              </label>
            </div>
          }
        </ng-template>

        @if (!plans()) {
          <section aria-labelledby="h-mezcla">
            <h2 id="h-mezcla">Mezcla de premios</h2>
            <p class="gp-muted">Cada código del pool nace con su resultado ya decidido. La compra sortea uno al azar entre los disponibles.</p>
            <ng-container *ngTemplateOutlet="mixTpl; context: { $implicit: d.mix, pfx: '', label: '' }" />
            <p data-testid="pool-total"><strong>Pool total: {{ size() }}</strong> <span class="gp-muted">de máximo {{ st.limits.maxPool }} códigos</span></p>
          </section>
        } @else {
          <section aria-labelledby="h-disparadores">
            <h2 id="h-disparadores">Disparadores</h2>
            <p class="gp-muted">Cuándo se entrega un turno de la ruleta: al comprar (el plan sale del monto de lo que participa), al ingresar por primera vez y al volver tras unos días sin comprar (ambos del plan de bienvenida).</p>
            <div class="grid">
              <label class="f">Días sin comprar para la reactivación<input class="num" type="number" step="1" min="1" [max]="st.limits.maxReactivationDays" name="reactivationDays" [(ngModel)]="d.triggers!.reactivationDays" data-testid="reactivationDays" /></label>
              <label class="f">Turnos por compra<input class="num" type="number" step="1" min="1" [max]="st.limits.maxCodesPerPurchase" name="turnsPerPurchase" [(ngModel)]="d.triggers!.turnsPerPurchase" data-testid="turnsPerPurchase" /></label>
              <label class="f check"><span><input type="checkbox" name="welcomeOnFirstLogin" [(ngModel)]="d.triggers!.welcomeOnFirstLogin" data-testid="welcomeOnFirstLogin" /> Turno de bienvenida al primer ingreso</span></label>
            </div>
          </section>

          <section aria-labelledby="h-planes">
            <h2 id="h-planes">Planes de premio</h2>
            <p class="gp-muted">Cada plan tiene su propio pool con su mezcla exacta: la probabilidad de premio es premios ÷ códigos del plan. El plan por monto lo decide el servidor con lo que participa de la compra.</p>
            @for (pl of d.plans!; track pl.id; let i = $index) {
              <div class="plan" data-testid="plan-card" [attr.data-plan]="pl.id">
                <h3>{{ pl.name }}
                  <span class="total gp-muted" data-testid="plan-summary">{{ planSum(pl).winners }} con premio + {{ planSum(pl).none }} sin premio = {{ planSum(pl).total }} códigos · probabilidad <strong data-testid="plan-probability">{{ pct(planProb(pl)) }}</strong></span>
                </h3>
                <div class="grid">
                  <label class="f">Nombre del plan<input [name]="pl.id + 'name'" [(ngModel)]="pl.name" [attr.maxlength]="st.limits.maxPlanName" [attr.data-testid]="'plan-name-' + pl.id" /></label>
                  @if (pl.kind === 'amount') {
                    <label class="f">Desde (COP)<input class="num" type="number" step="1" min="1" [name]="pl.id + 'min'" [(ngModel)]="pl.minAmount" [readonly]="isLinked(pl)" [attr.data-testid]="'plan-min-' + pl.id" /></label>
                    <label class="f">Hasta (COP)
                      @if (isLastAmount(pl)) {
                        <input class="num" name="lastMax" value="Sin tope" disabled [attr.data-testid]="'plan-max-' + pl.id" />
                      } @else {
                        <input class="num" type="number" step="1" min="1" [name]="pl.id + 'max'" [ngModel]="pl.maxAmount" (ngModelChange)="setMax(pl, $event)" [attr.data-testid]="'plan-max-' + pl.id" />
                      }
                    </label>
                  } @else {
                    <p class="gp-muted">Primer ingreso y regreso tras {{ d.triggers!.reactivationDays }} días sin comprar.</p>
                  }
                  <label class="f">Premio máximo en dinero (COP)<input class="num" type="number" step="1" min="1" [name]="pl.id + 'cap'" [(ngModel)]="pl.maxMoneyPrize" [attr.data-testid]="'plan-cap-' + pl.id" /></label>
                </div>
                <ng-container *ngTemplateOutlet="mixTpl; context: { $implicit: pl.mix, pfx: pl.id + '-', label: pl.name }" />
              </div>
            }
            <p data-testid="pool-total"><strong>Pool total: {{ size() }}</strong> <span class="gp-muted">de máximo {{ st.limits.maxPool }} códigos</span></p>
          </section>
        }

        @if (problems().length > 0) {
          <ul class="errors" role="alert" data-testid="problems">@for (m of problems(); track m) { <li>{{ m }}</li> }</ul>
        }
        <div class="actions">
          <button mat-flat-button type="button" (click)="save()" [disabled]="!dirty() || problems().length > 0 || busy()" data-testid="save">Guardar configuración</button>
          <button mat-button type="button" (click)="discard()" [disabled]="!dirty() || busy()" data-testid="discard">Descartar cambios</button>
        </div>
      </form>
    }
  `,
})
export class DemoScreen implements OnInit {
  private readonly api = inject(Api);
  protected readonly state = signal<DemoState | null>(null);
  protected readonly draft = signal<DemoConfig | null>(null);
  protected readonly error = signal<string | null>(null);
  protected readonly message = signal<string | null>(null);
  protected readonly fail = signal<string | null>(null);
  protected readonly busy = signal(false);
  protected readonly confirm = signal<Confirm>(null);
  /** Elección al reiniciar: sin valor por defecto, quien reinicia tiene que decidir (borrar cuentas es irreversible). */
  protected readonly resetAccounts = signal<ResetAccounts | null>(null);
  protected readonly accounts = signal<DemoAccountList | null>(null);
  protected readonly page = signal(1);
  protected readonly delTarget = signal<DemoAccountRow | null>(null);
  protected readonly simTarget = signal<DemoAccountRow | null>(null);
  protected readonly mailTo = signal('');
  protected readonly pages = computed(() => Math.max(1, Math.ceil((this.accounts()?.total ?? 0) / ACCOUNTS_PER_PAGE)));
  protected readonly mailLabel = computed(() => mailMode(this.state()!.mail).label);
  protected readonly mailSends = computed(() => mailMode(this.state()!.mail).sends);
  /** Se incrementa en cada edición del formulario: el borrador es un objeto mutable (ngModel) y los cálculos dependen de este contador. */
  private readonly rev = signal(0);
  protected readonly bogota = dateBogota;
  protected readonly gameLabel = GAME_LABEL;
  protected readonly prizeTypes = Object.entries(PRIZE_TYPE_LABEL) as [PrizeType, string][];
  private timer?: ReturnType<typeof setTimeout>;
  private destroyed = false;

  /** El borrador está en el modo por planes (SPEC-007). */
  protected readonly plans = computed(() => {
    this.rev();
    const d = this.draft();
    return d !== null && isPlans(d);
  });
  /** La configuración GUARDADA está en el modo por planes: de eso depende lo que se puede hacer con las cuentas (simular reactivación). */
  protected readonly savedPlans = computed(() => {
    const s = this.state();
    return s !== null && isPlans(s.config);
  });
  protected readonly mode = computed<DemoMode>(() => (this.plans() ? 'plans' : 'steps'));
  protected readonly modeChanged = computed(() => {
    this.rev();
    const s = this.state();
    return s !== null && this.plans() !== isPlans(s.config);
  });
  protected readonly dirty = computed(() => {
    this.rev();
    const s = this.state();
    const d = this.draft();
    return s !== null && d !== null && canonical(d) !== canonical(s.config);
  });
  protected readonly problems = computed(() => {
    this.rev();
    const s = this.state();
    const d = this.draft();
    return s && d ? validateDemo(d, s.limits) : [];
  });
  protected readonly size = computed(() => {
    this.rev();
    const d = this.draft();
    return d ? poolSize(d) : 0;
  });
  protected readonly planPreview = computed(() => {
    this.rev();
    const d = this.draft();
    if (!d || !isPlans(d)) return [];
    return d.products.map((p) => {
      const plan = p.participates === false ? null : planForAmount(d.plans ?? [], p.price);
      return {
        name: p.name,
        price: p.price,
        balance: Math.floor((p.price * d.credit.balancePercent) / 100),
        plan: p.participates === false ? 'No participa (no da ruleta)' : (plan?.name ?? 'Sin ruleta (por debajo del primer plan)'),
      };
    });
  });
  protected readonly preview = computed(() => {
    this.rev();
    const d = this.draft();
    return (d?.products ?? []).map((p) => ({ name: p.name, price: p.price, ...previewPurchase(d!.credit, p.price) }));
  });
  private readonly running = computed(() => ['pending', 'running'].includes(this.state()?.lastRun?.state ?? ''));
  protected readonly canSeed = computed(() => {
    const s = this.state();
    return !!s && s.configured && s.status.serverAllowed && !this.dirty() && s.pool.available === 0 && !this.busy() && !this.running();
  });
  protected readonly canReset = computed(() => {
    const s = this.state();
    return !!s && s.configured && s.status.serverAllowed && !this.dirty() && !this.busy() && !this.running();
  });
  protected readonly canEnable = computed(() => {
    const s = this.state();
    return !!s && s.configured && s.status.serverAllowed && !this.dirty() && s.pool.available > 0 && !this.busy() && !this.running();
  });

  constructor() {
    inject(DestroyRef).onDestroy(() => {
      this.destroyed = true;
      clearTimeout(this.timer);
    });
  }

  ngOnInit(): void {
    void this.load();
  }

  protected touch(): void {
    this.rev.update((n) => n + 1);
  }

  protected money(v: number): string {
    return `$ ${v.toLocaleString('es-CO')}`;
  }

  protected totals(e: DemoMixEntry) {
    return mixTotals(e);
  }

  protected chipLabel(): string {
    const s = this.state()!.status;
    return s.active ? 'Encendida' : s.enabled ? 'Caducada' : 'Apagada';
  }

  protected runLabel(r: DemoRun | null): string {
    if (!r) return 'Ninguna';
    const kind = r.kind === 'seed' ? 'Siembra' : 'Reinicio';
    const st = { pending: 'en cola', running: 'en curso', done: 'terminada', failed: 'con error' }[r.state];
    return `${kind} ${st} · ${this.bogota(r.finishedAt ?? r.startedAt ?? r.createdAt)}`;
  }

  protected confirmText(c: Exclude<Confirm, null>): string {
    const s = this.state()!;
    if (c === 'enable') return `Se abrirá el acceso público a la muestra${s.config.ttlHours ? ` por ${s.config.ttlHours} horas` : ' sin caducidad'}. Quien llegue a la app podrá simular compras y recibir códigos. ¿Encender?`;
    if (c === 'disable') return 'La muestra dejará de responder de inmediato: quienes estén en ella perderán la sesión. ¿Apagar?';
    return 'Se apagará la muestra, se retirarán los códigos actuales (también los ya entregados) y se sembrará un pool nuevo. Elige qué pasa con las cuentas:';
  }

  protected async load(): Promise<void> {
    this.error.set(null);
    try {
      const s = await this.api.get<DemoState>('/demo');
      this.state.set(s);
      this.draft.set(structuredClone(s.config));
      this.touch();
      await this.loadAccounts();
      if (s.lastRun && ['pending', 'running'].includes(s.lastRun.state)) this.poll(s.lastRun.id);
    } catch (e) {
      this.error.set(e instanceof ApiError ? e.message : 'No se pudo cargar la muestra.');
    }
  }

  /** Recarga solo el estado (sin pisar lo que se está editando). */
  private async refreshStatus(): Promise<void> {
    const s = await this.api.get<DemoState>('/demo');
    this.state.set(s);
    await this.loadAccounts();
  }

  private async loadAccounts(): Promise<void> {
    try {
      const q = () => ({ page: this.page(), perPage: ACCOUNTS_PER_PAGE });
      let list = await this.api.get<DemoAccountList>('/demo/accounts', q());
      if (list.items.length === 0 && this.page() > 1) {
        this.page.set(Math.max(1, Math.ceil(list.total / ACCOUNTS_PER_PAGE)));
        list = await this.api.get<DemoAccountList>('/demo/accounts', q());
      }
      this.accounts.set(list);
    } catch {
      this.accounts.set(null);
    }
  }

  protected async goPage(delta: number): Promise<void> {
    this.page.update((p) => Math.min(this.pages(), Math.max(1, p + delta)));
    await this.loadAccounts();
  }

  protected async deleteAccount(): Promise<void> {
    const t = this.delTarget();
    if (!t) return;
    this.delTarget.set(null);
    if (await this.act('Cuenta borrada.', () => this.api.delete(`/demo/accounts/${t.id}`))) await this.refreshStatus();
  }

  protected async simulate(): Promise<void> {
    const t = this.simTarget();
    if (!t) return;
    this.simTarget.set(null);
    await this.act('Listo: en su próximo ingreso con código recibirá el turno de reactivación.', () => this.api.post(`/demo/accounts/${t.id}/simulate-inactivity`));
  }

  protected async sendTest(): Promise<void> {
    await this.act('Correo de prueba enviado: revisa la bandeja (o Mailpit).', () => this.api.post('/demo/mail/test', { to: this.mailTo().trim() }));
  }

  protected setTtl(v: number | null | string): void {
    this.draft()!.ttlHours = v === null || v === '' ? null : Number(v);
    this.touch();
  }

  protected addProduct(): void {
    const d = this.draft()!;
    let n = d.products.length + 1;
    while (d.products.some((p) => p.id === `producto-${n}`)) n++;
    d.products.push({ id: `producto-${n}`, name: `Producto ${n}`, price: 10_000 });
    this.touch();
  }

  protected removeProduct(i: number): void {
    this.draft()!.products.splice(i, 1);
    this.touch();
  }

  protected gameKeysOf(mix: Partial<Record<GameType, DemoMixEntry>>): GameType[] {
    this.rev();
    return (Object.keys(GAME_LABEL) as GameType[]).filter((g) => mix[g] !== undefined);
  }

  protected freeGamesOf(mix: Partial<Record<GameType, DemoMixEntry>>): GameType[] {
    this.rev();
    return (this.state()?.limits.games ?? []).filter((g) => mix[g] === undefined);
  }

  protected addGameTo(mix: Partial<Record<GameType, DemoMixEntry>>, g: string): void {
    if (!g) return;
    mix[g as GameType] = { prizes: [{ type: 'money', amount: 5_000, count: 1 }], noPrizeCount: 4 };
    this.touch();
  }

  protected removeGameFrom(mix: Partial<Record<GameType, DemoMixEntry>>, g: GameType): void {
    delete mix[g];
    this.touch();
  }

  /** Nombre de un juego, con el plan delante cuando hay varios (para etiquetas accesibles únicas). */
  protected lab(label: string, g: GameType): string {
    return label ? `${label} · ${GAME_LABEL[g]}` : GAME_LABEL[g];
  }

  protected pct(p: number): string {
    return percent(p);
  }

  protected planSum(p: DemoPlan) {
    return planTotals(p);
  }

  protected planProb(p: DemoPlan): number {
    return planProbability(p);
  }

  private amountPlans(): DemoPlan[] {
    return (this.draft()?.plans ?? []).filter((x) => x.kind === 'amount');
  }

  /** Los planes por monto salvo el primero empiezan solos donde termina el anterior (sin huecos ni solapes). */
  protected isLinked(p: DemoPlan): boolean {
    return this.amountPlans()[0] !== p;
  }

  /** El último plan por monto no tiene tope. */
  protected isLastAmount(p: DemoPlan): boolean {
    const a = this.amountPlans();
    return a[a.length - 1] === p;
  }

  protected setMax(p: DemoPlan, v: number | string | null): void {
    p.maxAmount = v === null || v === '' ? null : Number(v);
    relinkPlans(this.draft()!.plans!);
    this.touch();
  }

  protected setMode(m: DemoMode): void {
    const s = this.state()!;
    if (m === this.mode()) return;
    this.draft.set(m === 'plans' ? toPlansMode(this.draft()!, s.limits.maxProducts) : toStepsMode(this.draft()!));
    this.touch();
  }

  protected addPrize(e: DemoMixEntry): void {
    e.prizes.push({ type: 'money', amount: 1_000, count: 1 });
    this.touch();
  }

  protected removePrize(e: DemoMixEntry, j: number): void {
    e.prizes.splice(j, 1);
    this.touch();
  }

  protected discard(): void {
    this.draft.set(structuredClone(this.state()!.config));
    this.touch();
  }

  private async act(ok: string, run: () => Promise<unknown>): Promise<boolean> {
    this.busy.set(true);
    this.message.set(null);
    this.fail.set(null);
    try {
      await run();
      this.message.set(ok);
      return true;
    } catch (e) {
      this.fail.set(e instanceof ApiError ? e.message : 'No se pudo completar la acción.');
      return false;
    } finally {
      this.busy.set(false);
    }
  }

  protected async save(): Promise<void> {
    if (await this.act('Configuración guardada.', () => this.api.put('/demo/config', this.draft()))) await this.load();
  }

  protected async seed(): Promise<void> {
    await this.startRun('Siembra pedida: el worker la procesa en menos de un minuto.', '/demo/seed');
  }

  protected ask(c: Exclude<Confirm, null>): void {
    this.resetAccounts.set(null);
    this.confirm.set(c);
  }

  protected async doConfirm(): Promise<void> {
    const c = this.confirm();
    this.confirm.set(null);
    if (c === 'enable') {
      if (await this.act('Muestra encendida.', () => this.api.post('/demo/enable'))) await this.refreshStatus();
    } else if (c === 'disable') {
      if (await this.act('Muestra apagada.', () => this.api.post('/demo/disable'))) await this.refreshStatus();
    } else if (c === 'reset') {
      await this.startRun('Reinicio pedido: el worker lo procesa en menos de un minuto.', '/demo/reset', { accounts: this.resetAccounts() });
    }
  }

  private async startRun(ok: string, path: string, body?: unknown): Promise<void> {
    let id = 0;
    if (!(await this.act(ok, async () => (id = (await this.api.post<{ runId: number }>(path, body)).runId)))) return;
    await this.refreshStatus();
    this.poll(id);
  }

  /** Consulta la corrida hasta que termine; al terminar recarga el estado (pool y última corrida). */
  private poll(id: number): void {
    clearTimeout(this.timer);
    this.timer = setTimeout(async () => {
      if (this.destroyed) return;
      try {
        const run = await this.api.get<DemoRun>(`/demo/runs/${id}`);
        if (run.state === 'pending' || run.state === 'running') {
          this.poll(id);
          return;
        }
        await this.refreshStatus();
        if (run.state === 'done') this.message.set('La corrida terminó.');
      } catch {
        this.poll(id);
      }
    }, POLL_MS);
  }
}
