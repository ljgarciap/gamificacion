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
