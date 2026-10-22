/** Formas de docs/openapi-admin.yaml que el panel consume. */
export type Role = 'superadmin' | 'operator' | 'auditor';

export interface Me {
  id: number;
  email: string;
  name: string;
  role: Role;
  permissions: string[];
}

export interface MeResponse extends Me {
  session: { idleMinutes: number };
}

export type LoginStep = 'password' | 'enroll' | 'mfa';

export interface ApiErrorBody {
  error: { code: string; message: string; retryAfter?: number };
}

export interface StockPrize {
  prizeId: string;
  total: number;
  delivered: number;
  remaining: number;
}

export interface Dashboard {
  campaigns: number;
  batches: Record<'draft' | 'committed' | 'active' | 'closed' | 'revealed', number>;
  activeCodes: { total: number; redeemed: number };
  redemptions: { total: number; last24h: number };
  outbox: { pending: number; sent: number; failed: number; oldestPendingAt: string | null };
  approvals: { pending: number; awaitingExecution: number };
  admins: number;
  stockByBatch: { batchId: string; name: string; prizes: StockPrize[] }[];
  redemptionsByDay: { day: string; count: number }[];
}

export interface AuditEntry {
  id: number;
  occurredAt: string;
  actor: string;
  action: string;
  subjectType: string;
  subjectId: string;
  data: unknown;
}

export interface AuditPage {
  items: AuditEntry[];
  nextBeforeId: number | null;
  perPage: number;
}

export interface VerifySegment {
  checked: number;
  brokenAt: number | null;
  lastId: number;
  lastHash: string;
  done: boolean;
}

export interface Anchor {
  version: number;
  head_id: number;
  head_hash: string;
  created_at: string;
}

export interface OutboxItem {
  id: number;
  redemptionId: string;
  status: 'pending' | 'sent' | 'failed';
  attempts: number;
  nextAttemptAt: string | null;
  lastError: string | null;
  createdAt: string;
  sentAt: string | null;
  userId: string | null;
  batchId: string | null;
  prizeId: string | null;
  prizeType: string | null;
}

export interface OutboxPage {
  items: OutboxItem[];
  total: number;
  counts: Record<'pending' | 'sent' | 'failed', number>;
  page: number;
  perPage: number;
}

export interface CodeLookup {
  batchId: string;
  batchName: string;
  batchState: 'draft' | 'committed' | 'active' | 'closed' | 'revealed';
  index: number;
  redeemed: boolean;
  redeemedAt: string | null;
  redeemedBy: string | null;
  prizeId: string | null;
}

export type GameType = 'roulette' | 'scratch' | 'memory' | 'dice' | 'bomb' | 'bingo' | 'poker';
export type PrizeType = 'money' | 'coupon' | 'points' | 'extraSpin' | 'none';

export interface CampaignRow {
  id: string;
  name: string;
  gameType: GameType;
  usedCodeBehavior: 'showPrevious' | 'rejectOnly';
  title: string | null;
  prizeCount: number;
  batchCount: number;
  frozen: boolean;
  createdAt: string;
  updatedAt: string;
}

export interface CampaignPrize {
  prizeId: string;
  type: PrizeType;
  label: string;
  amount: number | null;
  meta: Record<string, unknown> | unknown[] | null;
}

export interface Campaign {
  id: string;
  name: string;
  gameType: GameType;
  usedCodeBehavior: 'showPrevious' | 'rejectOnly';
  title: string | null;
  theme: Record<string, string>;
  gameConfig: Record<string, unknown>;
  frozen: boolean;
  prizes: CampaignPrize[];
}

export interface AdminUser {
  id: number;
  email: string;
  name: string;
  role: Role;
  active: boolean;
  mfaEnrolled: boolean;
  mustChangePassword: boolean;
  lastLoginAt: string | null;
}

export interface Host {
  id: number;
  issuer: string;
  callbackUrl: string;
  balanceUrl: string;
  active: boolean;
  hasPreviousSecret: boolean;
  createdAt: string;
  updatedAt: string;
}

export interface Setting {
  key: string;
  label: string;
  unit: string;
  min: number;
  max: number;
  security: boolean;
  default: number;
  value: number;
  overridden: boolean;
  updatedBy: string | null;
  updatedAt: string | null;
}

export interface SettingChange {
  id: number;
  old: number | null;
  new: number;
  actor: string;
  changedAt: string;
}

export type BatchState = 'draft' | 'committed' | 'active' | 'closed' | 'revealed';

export interface BatchRow {
  id: string;
  campaignId: string;
  name: string;
  state: BatchState;
  totalCodes: number;
  reserveSize: number;
  generatedCodes: number;
  redeemed: number;
  redeemableUntil: string | null;
  createdAt: string;
  committedAt: string | null;
  activatedAt: string | null;
  closedAt: string | null;
  revealedAt: string | null;
}

export interface BatchDetail extends BatchRow {
  pool: { prize_id: string; quantity: number }[];
  poolHash: string;
  redeemedByPrize: { prizeId: string; count: number }[];
  createdBy: string;
  commitment: string | null;
  codesDigest: string | null;
  chainHash: string | null;
  beaconRound: number | null;
  beaconValue: string | null;
  seed: string | null;
  reserveConsumed?: number;
  pendingSpins?: number;
  spinsPlayed?: number;
  commitVersion?: number;
}

export interface BatchRedemption {
  id: string;
  userId: string;
  prizeId: string;
  index: number | null;
  reserveIndex: number | null;
  parentRedemptionId: string | null;
  redeemedAt: string;
}

export interface RedemptionPage {
  items: BatchRedemption[];
  total: number;
  page: number;
  perPage: number;
}

export type ApprovalAction = 'batch.activate' | 'codes.generate' | 'audit.export' | 'setting.change';
export type ApprovalStatus = 'pending' | 'approved' | 'executed' | 'expired' | string;

export interface Approval {
  id: number;
  action: ApprovalAction;
  subjectType: string;
  subjectId: string;
  params: Record<string, unknown>;
  status: ApprovalStatus;
  requestedBy: { id: number; name: string };
  approvedBy: { id: number; name: string } | null;
  requestedAt: string;
  expiresAt: string;
  approvedAt: string | null;
  executedAt: string | null;
}

export interface ApprovalList {
  approvals: Approval[];
  fewApprovers: boolean;
}

/** Resultado de ejecutar `codes.generate` o `audit.export`: el archivo se entrega una sola vez. */
export interface Delivery {
  sha256: string;
  downloadUntil: string;
  count?: number;
  entries?: number;
  anchor?: unknown;
}
