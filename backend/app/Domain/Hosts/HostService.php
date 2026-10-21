<?php

namespace App\Domain\Hosts;

use App\Domain\Admin\AdminException;
use App\Domain\Admin\SecretBox;
use App\Domain\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Apps host registradas (SPEC-003 RF-49). El secreto de firma lo GENERA el servidor (32 bytes aleatorios), se guarda cifrado y se muestra UNA vez, al
 * crear el host o al rotarlo; después nunca sale (ni por la API ni por la bitácora). La rotación deja vivos dos secretos: el nuevo firma, el anterior
 * sigue válido para que el host pueda verificar avisos ya en camino hasta que se retire.
 */
final class HostService
{
    public function __construct(
        private readonly AuditLog $audit,
        private readonly SecretBox $box,
        private readonly HostUrlGuard $guard,
    ) {}

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return array_map(fn (object $r): array => $this->out($r), DB::select(
            'SELECT id, issuer, callback_url, balance_url, active, (secret_previous_enc IS NOT NULL) AS has_previous, created_at, updated_at FROM hosts ORDER BY issuer'));
    }

    /**
     * @param  array<string, mixed>  $in
     * @return array{id: int, secret: string} el secreto en claro, única vez
     */
    public function create(array $in, string $actor): array
    {
        $issuer = $in['issuer'] ?? null;
        if (! is_string($issuer) || preg_match('/^[^\p{Cc}\s]{1,190}$/u', $issuer) !== 1) {
            throw $this->invalid('«issuer» es obligatorio (hasta 190 caracteres, sin espacios).');
        }
        if (! array_key_exists($issuer, (array) config('gamification.hosts'))) {
            throw $this->invalid('El emisor no está en la configuración de tokens (GAMIF_HOSTS): primero debe poder autenticar usuarios.');
        }
        [$callback, $balance] = [$this->url($in, 'callbackUrl'), $this->url($in, 'balanceUrl')];
        $secret = $this->newSecret();

        return DB::transaction(function () use ($issuer, $callback, $balance, $secret, $actor): array {
            if (DB::table('hosts')->where('issuer', $issuer)->exists()) {
                throw new AdminException('duplicate', 'Ya hay un host con ese emisor.', 409);
            }
            $row = DB::selectOne(
                "INSERT INTO hosts (issuer, callback_url, balance_url, secret_current_enc) VALUES (?, ?, ?, decode(repeat('00', 64), 'hex')) RETURNING id",
                [$issuer, $callback, $balance],
            );
            $this->setSecret((int) $row->id, $issuer, 'secret_current_enc', $secret);
            $this->audit->append($actor, 'host.created', 'host', (string) $row->id, ['issuer' => $issuer, 'callback_url' => $callback, 'balance_url' => $balance]);

            return ['id' => (int) $row->id, 'secret' => $secret];
        });
    }

    /** @param array<string, mixed> $in */
    public function update(int $id, array $in, string $actor): void
    {
        $changes = [];
        foreach (['callbackUrl' => 'callback_url', 'balanceUrl' => 'balance_url'] as $field => $col) {
            if (array_key_exists($field, $in)) {
                $changes[$col] = $this->url($in, $field);
            }
        }
        if (array_key_exists('active', $in)) {
            if (! is_bool($in['active'])) {
                throw $this->invalid('«active» debe ser verdadero o falso.');
            }
            $changes['active'] = $in['active'];
        }
        if ($changes === []) {
            throw $this->invalid('No hay nada que cambiar.');
        }
        DB::transaction(function () use ($id, $changes, $actor): void {
            $h = $this->lock($id);
            $sets = implode(', ', array_map(static fn (string $c): string => "$c = ?", array_keys($changes)));
            DB::update("UPDATE hosts SET $sets, updated_at = now() WHERE id = ?", [...array_values($changes), $id]);
            $this->audit->append($actor, 'host.updated', 'host', (string) $id, ['issuer' => $h->issuer] + $changes);
        });
    }

    /**
     * Genera un secreto nuevo; el actual pasa a «anterior» (si ya había uno anterior, se descarta).
     *
     * @return string el secreto nuevo en claro, única vez
     */
    public function rotate(int $id, string $actor): string
    {
        $secret = $this->newSecret();

        return DB::transaction(function () use ($id, $actor, $secret): string {
            $h = $this->lock($id);
            $current = $this->box->open(hex2bin($h->secret_current_enc_hex), $this->context($id, $h->issuer, 'secret_current_enc'));
            $this->setSecret($id, $h->issuer, 'secret_previous_enc', $current);
            $this->setSecret($id, $h->issuer, 'secret_current_enc', $secret);
            $this->audit->append($actor, 'host.secret_rotated', 'host', (string) $id, ['issuer' => $h->issuer]);

            return $secret;
        });
    }

    /** Retira el secreto anterior: desde ya solo vale el actual. */
    public function retirePrevious(int $id, string $actor): void
    {
        DB::transaction(function () use ($id, $actor): void {
            $h = $this->lock($id);
            if (DB::selectOne('SELECT secret_previous_enc IS NOT NULL AS has FROM hosts WHERE id = ?', [$id])->has !== true) {
                throw new AdminException('wrongState', 'El host no tiene un secreto anterior.', 409);
            }
            DB::update('UPDATE hosts SET secret_previous_enc = NULL, updated_at = now() WHERE id = ?', [$id]);
            $this->audit->append($actor, 'host.secret_retired', 'host', (string) $id, ['issuer' => $h->issuer]);
        });
    }

    /**
     * Secretos con los que firmar/verificar (para P5b). El primero firma; el segundo (si existe) solo se acepta al verificar.
     *
     * @return array{current: string, previous: ?string}
     */
    public function secretsFor(int $id): array
    {
        $h = $this->lock($id, false);

        return [
            'current' => $this->box->open(hex2bin($h->secret_current_enc_hex), $this->context($id, $h->issuer, 'secret_current_enc')),
            'previous' => $h->secret_previous_enc_hex === null ? null : $this->box->open(hex2bin($h->secret_previous_enc_hex), $this->context($id, $h->issuer, 'secret_previous_enc')),
        ];
    }

    // --- Internos --------------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $in */
    private function url(array $in, string $field): string
    {
        $url = $in[$field] ?? null;
        if (! is_string($url)) {
            throw $this->invalid("«{$field}» es obligatoria.");
        }
        try {
            $this->guard->check($url);
        } catch (HostUrlRejected $e) {
            throw $this->invalid("«{$field}»: ".$e->getMessage());
        }

        return $url;
    }

    private function newSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function context(int $id, string $issuer, string $column): string
    {
        return "hosts|$id|$column";
    }

    private function setSecret(int $id, string $issuer, string $column, string $plain): void
    {
        $sealed = $this->box->seal($plain, $this->context($id, $issuer, $column));
        DB::update("UPDATE hosts SET $column = decode(?, 'hex'), updated_at = now() WHERE id = ?", [bin2hex($sealed), $id]);
    }

    private function lock(int $id, bool $forUpdate = true): object
    {
        return DB::selectOne(
            "SELECT id, issuer, encode(secret_current_enc, 'hex') AS secret_current_enc_hex, encode(secret_previous_enc, 'hex') AS secret_previous_enc_hex FROM hosts WHERE id = ?".($forUpdate ? ' FOR UPDATE' : ''),
            [$id],
        ) ?? throw new AdminException('notFound', 'El host no existe.', 404);
    }

    /** @return array<string, mixed> */
    private function out(object $r): array
    {
        return [
            'id' => (int) $r->id, 'issuer' => $r->issuer, 'callbackUrl' => $r->callback_url, 'balanceUrl' => $r->balance_url,
            'active' => (bool) $r->active, 'hasPreviousSecret' => (bool) $r->has_previous, 'createdAt' => $r->created_at, 'updatedAt' => $r->updated_at,
        ];
    }

    private function invalid(string $message): AdminException
    {
        return new AdminException('validationFailed', $message, 422);
    }
}
