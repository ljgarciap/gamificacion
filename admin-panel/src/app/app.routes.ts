import { Routes } from '@angular/router';
import { authGuard, permissionGuard } from './core/guards';

/** Rutas y permisos de docs/ux-spec-003-panel.md §3. P8 reemplaza cada «Pronto» por su pantalla. */
const dashboard = () => import('./features/dashboard/dashboard').then((m) => m.DashboardPage);
const audit = () => import('./features/audit/audit').then((m) => m.AuditScreen);
const campaigns = () => import('./features/campaigns/campaign-list').then((m) => m.CampaignList);
const campaign = () => import('./features/campaigns/campaign-detail').then((m) => m.CampaignDetail);
const users = () => import('./features/users/users').then((m) => m.UsersScreen);
const hosts = () => import('./features/hosts/hosts').then((m) => m.HostsScreen);
const settings = () => import('./features/settings/settings').then((m) => m.SettingsScreen);
const batches = () => import('./features/batches/batch-list').then((m) => m.BatchList);
const wizard = () => import('./features/batches/batch-wizard').then((m) => m.BatchWizard);
const batch = () => import('./features/batches/batch-detail').then((m) => m.BatchDetail);
const approvals = () => import('./features/approvals/approvals').then((m) => m.ApprovalsScreen);
const outbox = () => import('./features/outbox/outbox').then((m) => m.OutboxScreen);
const codes = () => import('./features/codes/codes').then((m) => m.CodesScreen);

export const routes: Routes = [
  { path: 'acceso', loadComponent: () => import('./features/login/login').then((m) => m.Login), title: 'Acceso' },
  {
    path: '',
    loadComponent: () => import('./features/shell/shell').then((m) => m.Shell),
    canActivate: [authGuard],
    children: [
      { path: '', pathMatch: 'full', loadComponent: dashboard, canActivate: [permissionGuard], data: { permission: 'dashboard.view', title: 'Resumen' }, title: 'Resumen' },
      { path: 'campanas', loadComponent: campaigns, canActivate: [permissionGuard], data: { permission: 'campaigns.view', title: 'Campañas' }, title: 'Campañas' },
      { path: 'campanas/nueva', loadComponent: campaign, canActivate: [permissionGuard], data: { permission: 'campaigns.manage', title: 'Nueva campaña' }, title: 'Nueva campaña' },
      { path: 'campanas/:id', loadComponent: campaign, canActivate: [permissionGuard], data: { permission: 'campaigns.view', title: 'Campaña' }, title: 'Campaña' },
      { path: 'lotes', loadComponent: batches, canActivate: [permissionGuard], data: { permission: 'batches.view', title: 'Lotes' }, title: 'Lotes' },
      { path: 'lotes/nuevo', loadComponent: wizard, canActivate: [permissionGuard], data: { permission: 'batches.manage', title: 'Nuevo lote' }, title: 'Nuevo lote' },
      { path: 'lotes/:id', loadComponent: batch, canActivate: [permissionGuard], data: { permission: 'batches.view', title: 'Lote' }, title: 'Lote' },
      { path: 'aprobaciones', loadComponent: approvals, canActivate: [permissionGuard], data: { permission: 'approvals.view', title: 'Aprobaciones' }, title: 'Aprobaciones' },
      { path: 'avisos', loadComponent: outbox, canActivate: [permissionGuard], data: { permission: 'outbox.view', title: 'Avisos al host' }, title: 'Avisos al host' },
      { path: 'codigos', loadComponent: codes, canActivate: [permissionGuard], data: { permission: 'batches.view', title: 'Buscar código' }, title: 'Buscar código' },
      { path: 'auditoria', loadComponent: audit, canActivate: [permissionGuard], data: { permission: 'audit.view', title: 'Bitácora' }, title: 'Bitácora' },
      { path: 'hosts', loadComponent: hosts, canActivate: [permissionGuard], data: { permission: 'hosts.manage', title: 'Hosts' }, title: 'Hosts' },
      { path: 'parametros', loadComponent: settings, canActivate: [permissionGuard], data: { permission: 'settings.manage', title: 'Parámetros' }, title: 'Parámetros' },
      { path: 'usuarios', loadComponent: users, canActivate: [permissionGuard], data: { permission: 'users.manage', title: 'Usuarios' }, title: 'Usuarios' },
      { path: 'cuenta', loadComponent: () => import('./features/account/account').then((m) => m.AccountPage), canActivate: [permissionGuard], data: { permission: 'account.self', title: 'Mi cuenta' }, title: 'Mi cuenta' },
      { path: 'sin-acceso', loadComponent: () => import('./features/shell/no-access').then((m) => m.NoAccess), title: 'Sin acceso' },
    ],
  },
  { path: '**', redirectTo: '' },
];
