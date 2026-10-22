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
