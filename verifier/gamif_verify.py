#!/usr/bin/env python3
"""Verificador independiente del sorteo de premios (SPEC-002 §4).

Implementación de referencia, SOLO con la biblioteca estándar de Python, escrita aparte del backend a
propósito: si dos implementaciones independientes coinciden byte a byte, la especificación es inequívoca,
y un tercero puede comprobar una campaña sin confiar en nuestro servidor.

Uso:
    python3 gamif_verify.py verify export.json     # verifica la exportación de auditoría de un lote
    python3 gamif_verify.py verify export.json --anchor ancla.json  # ...y que la bitácora no perdió su final
    python3 gamif_verify.py vectors > vectors.json # genera los vectores de prueba del motor

Lo que verifica y lo que NO:
  - SÍ: que el compromiso publicado se reproduce con la semilla revelada, que la baraja recalculada coincide con
    las asignaciones usadas y que los conteos son exactamente el stock del pool.
  - SÍ: que la bitácora encadenada incluida es íntegra (cada hash se recomputa) y coincide con lo publicado (compromiso,
    valor de baliza y semilla revelada). Con `--anchor`, que el último hash publicado FUERA de la base sigue en la cadena: sin
    ancla, borrar las últimas entradas deja una cadena válida y no se puede detectar.
  - NO (hazlo tú): que el valor de baliza `beacon_value` es realmente el de la ronda `beacon_round` de la
    cadena `chain_hash` de drand. Consúltalo en https://api.drand.sh/<chain_hash>/public/<ronda> y compara el
    campo `randomness`. Este programa no usa la red a propósito.
"""
import hashlib
import hmac
import json
import struct
import sys

DOMAIN_POOL = b"gamif/v1/pool"
DOMAIN_CODES = b"gamif/v1/codes"
DOMAIN_COMMIT = b"gamif/v1/commit"
DOMAIN_MIX = b"gamif/v1/mix"
DOMAIN_COMMIT_V2 = b"gamif/v2/commit"  # lotes con reserva (R > 0); con R = 0 sigue valiendo SOLO v1
DOMAIN_MIX_V2 = b"gamif/v2/mix"
DOMAIN_SHUFFLE = b"gamif/v1/shuffle"
DOMAIN_AUDIT = b"gamif/v1/audit"
AUDIT_GENESIS = b"\x00" * 32

MAX_WORD = 2 ** 32  # el muestreador lee 4 bytes por intento


def be32(n: int) -> bytes:
    return struct.pack(">I", n)


def be64(n: int) -> bytes:
    return struct.pack(">Q", n)


def lp(data: bytes) -> bytes:
    """Campo de largo variable: largo en 4 bytes big-endian + contenido (evita ambigüedades al concatenar)."""
    return be32(len(data)) + data


def sha256(data: bytes) -> bytes:
    return hashlib.sha256(data).digest()


# --- Pool -----------------------------------------------------------------------------------------------

def sorted_entries(pool):
    """Entradas (prize_id, quantity) en orden canónico: por los bytes UTF-8 del id."""
    return sorted(((str(pid), int(q)) for pid, q in pool), key=lambda e: e[0].encode("utf-8"))


def validate_pool(pool, max_total=1_000_000):
    entries = sorted_entries(pool)
    ids = [e[0] for e in entries]
    if not entries:
        raise ValueError("el pool está vacío")
    if len(set(ids)) != len(ids):
        raise ValueError("hay premios repetidos en el pool")
    for pid, qty in entries:
        if not pid or len(pid.encode("utf-8")) > 64:
            raise ValueError("id de premio vacío o de más de 64 bytes")
        if qty < 0:
            raise ValueError("cantidad negativa")
    total = sum(q for _, q in entries)
    if total < 1:
        raise ValueError("el pool no tiene ningún código")
    if total > max_total:
        raise ValueError("el pool supera el máximo de códigos")
    return entries


def pool_hash(pool) -> bytes:
    entries = validate_pool(pool)
    body = be32(len(entries)) + b"".join(lp(pid.encode("utf-8")) + be64(qty) for pid, qty in entries)
    return sha256(DOMAIN_POOL + body)


def expand_pool(pool):
    out = []
    for pid, qty in validate_pool(pool):
        out.extend([pid] * qty)
    return out


# --- Semilla, compromiso y huellas ------------------------------------------------------------------------

def codes_digest(code_hashes) -> bytes:
    """Resumen del conjunto de huellas de código, en orden de índice (cada huella son 32 bytes)."""
    for h in code_hashes:
        if len(h) != 32:
            raise ValueError("cada huella de código debe tener 32 bytes")
    return sha256(DOMAIN_CODES + be32(len(code_hashes)) + b"".join(code_hashes))


def commitment(batch_id: str, pool, digest: bytes, chain_hash: bytes, beacon_round: int, seed: bytes) -> bytes:
    if len(digest) != 32 or len(seed) != 32:
        raise ValueError("digest y semilla deben tener 32 bytes")
    return sha256(
        DOMAIN_COMMIT
        + lp(batch_id.encode("utf-8"))
        + pool_hash(pool)
        + digest
        + lp(chain_hash)
        + be64(beacon_round)
        + seed
    )


def commitment_v2(batch_id: str, pool, reserve: int, digest: bytes, chain_hash: bytes, beacon_round: int, seed: bytes) -> bytes:
    """Compromiso de un lote CON reserva (R >= 1): liga R de forma explícita y usa su propio dominio."""
    if len(digest) != 32 or len(seed) != 32:
        raise ValueError("digest y semilla deben tener 32 bytes")
    check_reserve(pool, reserve)
    return sha256(
        DOMAIN_COMMIT_V2
        + lp(batch_id.encode("utf-8"))
        + be32(reserve)
        + pool_hash(pool)
        + digest
        + lp(chain_hash)
        + be64(beacon_round)
        + seed
    )


def check_reserve(pool, reserve: int):
    """R >= 1 y debe quedar al menos un código: el pool tiene N + R posiciones."""
    total = sum(q for _, q in pool)
    if not isinstance(reserve, int) or isinstance(reserve, bool) or reserve < 1 or reserve >= total:
        raise ValueError("la reserva debe ser un entero >= 1 y menor que el total del pool")


def mix_seed_v2(seed: bytes, beacon: bytes, batch_id: str) -> bytes:
    if len(seed) != 32:
        raise ValueError("la semilla debe tener 32 bytes")
    return hmac.new(seed, DOMAIN_MIX_V2 + lp(beacon) + lp(batch_id.encode("utf-8")), hashlib.sha256).digest()


def mix_seed(seed: bytes, beacon: bytes, batch_id: str) -> bytes:
    """S' = HMAC-SHA256(clave = S, "gamif/v1/mix" || lp(B) || lp(batch_id))."""
    return hmac.new(seed, DOMAIN_MIX + lp(beacon) + lp(batch_id.encode("utf-8")), hashlib.sha256).digest()


# --- Generador determinista y baraja -----------------------------------------------------------------------

class Drbg:
    """Flujo de bytes determinista: el bloque i es HMAC-SHA256(clave, "gamif/v1/shuffle" || be64(i))."""

    def __init__(self, key: bytes):
        if len(key) != 32:
            raise ValueError("la clave debe tener 32 bytes")
        self.key = key
        self.counter = 0
        self.buffer = b""

    def next_bytes(self, n: int) -> bytes:
        while len(self.buffer) < n:
            self.buffer += hmac.new(self.key, DOMAIN_SHUFFLE + be64(self.counter), hashlib.sha256).digest()
            self.counter += 1
        out, self.buffer = self.buffer[:n], self.buffer[n:]
        return out

    def uniform(self, n: int) -> int:
        """Entero uniforme en [0, n) sin sesgo de módulo: se descartan las entradas que no caben en un
        múltiplo exacto de n (rejection sampling). Con n == 1 no se consume nada."""
        if n < 1 or n > MAX_WORD:
            raise ValueError("n fuera de rango")
        if n == 1:
            return 0
        limit = (MAX_WORD // n) * n
        while True:
            x = int.from_bytes(self.next_bytes(4), "big")
            if x < limit:
                return x % n


def shuffle(items, drbg: Drbg):
    """Barajado de Durstenfeld (Fisher-Yates) con el flujo determinista."""
    a = list(items)
    for i in range(len(a) - 1, 0, -1):
        j = drbg.uniform(i + 1)
        a[i], a[j] = a[j], a[i]
    return a


def assign(pool, seed: bytes, beacon: bytes, batch_id: str):
    """Premio de cada índice de código (lista de ids de premio, en orden de índice)."""
    return shuffle(expand_pool(pool), Drbg(mix_seed(seed, beacon, batch_id)))


def assign_deck(pool, reserve: int, seed: bytes, beacon: bytes, batch_id: str):
    """(premios de los N códigos, premios de las R casillas de reserva). Con R = 0 es EXACTAMENTE `assign` (v1)."""
    if reserve == 0:
        return assign(pool, seed, beacon, batch_id), []
    check_reserve(pool, reserve)
    deck = shuffle(expand_pool(pool), Drbg(mix_seed_v2(seed, beacon, batch_id)))
    n = len(deck) - reserve
    return deck[:n], deck[n:]


# --- Bitácora encadenada ----------------------------------------------------------------------------------

def audit_entry_hash(prev: bytes, e: dict) -> bytes:
    """hash = SHA256("gamif/v1/audit" || prev || lp(actor) || lp(action) || lp(subject_type) || lp(subject_id) || lp(data) || be64(µs))."""
    return sha256(
        DOMAIN_AUDIT + prev
        + lp(e["actor"].encode("utf-8")) + lp(e["action"].encode("utf-8"))
        + lp(e["subject_type"].encode("utf-8")) + lp(e["subject_id"].encode("utf-8"))
        + lp(e["data"].encode("utf-8")) + be64(int(e["at_micros"]))
    )


def verify_spins(entries, batch_id, reserve_assignments):
    """Giros hijos de la reserva, comprobados contra la cadena: las casillas se consumieron EN ORDEN (la k-ésima redención hija recibió la
    casilla k), cada una entregó el premio de su casilla, y ningún padre jugó más giros de los que se le concedieron."""
    failures = []
    granted = {}   # id de redención -> giros concedidos
    owner = {}     # id de redención -> usuario (seudónimo)
    played = {}    # id de padre -> giros jugados
    next_slot = 0
    for e in entries:
        if e["action"] not in ("code.redeemed", "spin.redeemed") or e["subject_type"] != "redemption":
            continue
        try:
            data = json.loads(e["data"])
        except ValueError:
            continue
        if data.get("batch_id") != batch_id:
            continue
        rid = e["subject_id"]
        granted[rid] = data.get("spins_granted", 0)
        owner[rid] = data.get("user_ref")
        if e["action"] == "code.redeemed":
            continue
        idx, parent, n = data.get("reserve_idx"), data.get("parent_redemption_id"), data.get("spin_no")
        if idx != next_slot:
            failures.append(f"la redención hija {rid} usó la casilla {idx} y debía usar la {next_slot}: las casillas no se consumieron en orden")
        elif not (0 <= idx < len(reserve_assignments)):
            failures.append(f"la redención hija {rid} usó la casilla {idx}, fuera de la reserva ({len(reserve_assignments)} casillas)")
        elif data.get("prize_id") != reserve_assignments[idx]:
            failures.append(f"la redención hija {rid} entregó {data.get('prize_id')!r} y la casilla {idx} tenía {reserve_assignments[idx]!r}")
        next_slot += 1
        if parent not in granted:
            failures.append(f"la redención hija {rid} cuelga de {parent}, que no es una redención previa de este lote")
            continue
        if owner[parent] != data.get("user_ref"):
            failures.append(f"la redención hija {rid} es de otro usuario que su padre {parent}")
        played[parent] = played.get(parent, 0) + 1
        if n != played[parent] or played[parent] > granted[parent]:
            failures.append(f"el padre {parent} jugó el giro {n} (de {granted[parent]} concedidos): giros fuera de orden o de más")
    return failures


def verify_audit_log(export: dict, anchor=None):
    """Fallos de la bitácora incluida en la exportación (lista vacía = íntegra y coherente con lo publicado)."""
    failures = []
    entries = export.get("audit_log")
    if not isinstance(entries, list) or not entries:
        return ["la exportación no incluye la bitácora (audit_log): no se puede comprobar su integridad"]

    prev = AUDIT_GENESIS
    by_id = {}
    try:
        for e in entries:
            if bytes.fromhex(e["prev_hash"]) != prev:
                failures.append(f"la entrada {e['id']} no cuelga de la anterior: la cadena está rota o reordenada")
                break
            calc = audit_entry_hash(prev, e)
            if calc != bytes.fromhex(e["hash"]):
                failures.append(f"el hash de la entrada {e['id']} no coincide con su contenido: fue alterada")
                break
            by_id[int(e["id"])] = e
            prev = calc
    except (KeyError, ValueError, TypeError, AttributeError) as exc:
        return failures + [f"entrada de bitácora mal formada: {exc!r}"]
    if failures:
        return failures

    batch_id = export.get("batch_id")
    batch_entries = {}
    for e in entries:
        if e["subject_type"] == "batch" and e["subject_id"] == batch_id:
            try:
                batch_entries[e["action"]] = json.loads(e["data"])
            except ValueError:
                failures.append(f"la entrada {e['id']} tiene datos que no son JSON")
    checks = (
        ("batch.committed", "commitment", export.get("commitment"), "el compromiso"),
        ("batch.committed", "codes_digest", export.get("codes_digest"), "el resumen de huellas de código"),
        ("batch.activated", "beacon_value", export.get("beacon_value"), "el valor de baliza"),
        ("batch.revealed", "seed", export.get("seed"), "la semilla revelada"),
    )
    for action, field, published, label in checks:
        data = batch_entries.get(action)
        if data is None:
            failures.append(f"la bitácora no registra {action} para este lote")
        elif data.get(field) != published:
            failures.append(f"{label} de la exportación no coincide con el registrado en la bitácora ({action})")

    # Giro extra: la reserva y la versión del compromiso exportadas son las que la bitácora registró al comprometer.
    reserve_export = export.get("reserve_assignments", [])
    committed = batch_entries.get("batch.committed")
    if committed is not None:
        if committed.get("reserve", 0) != len(reserve_export) or committed.get("commit_version", 1) != export.get("commit_version", 1):
            failures.append("la reserva o la versión del compromiso de la exportación no coinciden con las registradas al comprometer")
    failures += verify_spins(entries, batch_id, reserve_export)

    if anchor is not None:
        try:
            a_id, a_hash = int(anchor["head_id"]), anchor["head_hash"]
        except (KeyError, ValueError, TypeError) as exc:
            return failures + [f"ancla mal formada: {exc!r}"]
        found = by_id.get(a_id)
        if found is None:
            last = entries[-1]["id"]
            failures.append(f"el ancla apunta a la entrada {a_id} pero la bitácora exportada termina en la {last}: se perdió el final de la bitácora")
        elif found["hash"] != a_hash:
            failures.append(f"la entrada {a_id} tiene otro hash que el publicado en el ancla: la historia fue reescrita")
    return failures


# --- Verificación de una exportación de auditoría ---------------------------------------------------------

def verify_export(export: dict, anchor=None):
    """Devuelve la lista de fallos encontrados (vacía = todo coincide). `anchor`: ancla publicada fuera de la base (opcional)."""
    failures = []
    try:
        pool = [(e["prize_id"], e["quantity"]) for e in export["pool"]]
        batch_id = export["batch_id"]
        chain_hash = bytes.fromhex(export["chain_hash"])
        beacon = bytes.fromhex(export["beacon_value"])
        seed = bytes.fromhex(export["seed"])
        digest_pub = bytes.fromhex(export["codes_digest"])
        commit_pub = bytes.fromhex(export["commitment"])
        beacon_round = int(export["beacon_round"])
        assignments = export["assignments"]
        reserve_assignments = export.get("reserve_assignments", [])
        reserve = len(reserve_assignments)
        commit_version = export.get("commit_version", 1)
    except (KeyError, ValueError, TypeError) as exc:
        return [f"exportación incompleta o mal formada: {exc!r}"]

    if "code_hashes" in export:
        digest_calc = codes_digest([bytes.fromhex(h) for h in export["code_hashes"]])
        if digest_calc != digest_pub:
            failures.append("codes_digest no coincide con la lista de huellas publicada")
        if len(export["code_hashes"]) != len(assignments):
            failures.append("la cantidad de huellas no coincide con la de asignaciones")

    # Versión canónica: R = 0 => v1 y R > 0 => v2. Cualquier otra combinación se rechaza (un lote no puede tener dos compromisos válidos).
    if commit_version != (2 if reserve > 0 else 1):
        failures.append(f"versión de compromiso {commit_version!r} no válida para una reserva de {reserve} casillas")
        return failures
    try:
        if reserve == 0:
            calc = commitment(batch_id, pool, digest_pub, chain_hash, beacon_round, seed)
        else:
            calc = commitment_v2(batch_id, pool, reserve, digest_pub, chain_hash, beacon_round, seed)
    except ValueError as exc:
        return failures + [f"pool inválido: {exc}"]
    if calc != commit_pub:
        failures.append("el compromiso NO se reproduce con la semilla, el pool y la baliza publicados")

    expected, expected_reserve = assign_deck(pool, reserve, seed, beacon, batch_id)
    if len(expected) != len(assignments):
        failures.append(f"se esperaban {len(expected)} asignaciones y hay {len(assignments)}")
    else:
        bad = [i for i, (a, b) in enumerate(zip(expected, assignments)) if a != b]
        if bad:
            failures.append(f"{len(bad)} asignaciones difieren de la baraja recalculada (primer índice: {bad[0]})")
    if expected_reserve != reserve_assignments:
        bad = [i for i, (a, b) in enumerate(zip(expected_reserve, reserve_assignments)) if a != b]
        failures.append(f"la reserva difiere de la baraja recalculada (primera casilla: {bad[0] if bad else '?'})")

    counts = {}
    for p in list(assignments) + list(reserve_assignments):
        counts[p] = counts.get(p, 0) + 1
    for pid, qty in sorted_entries(pool):
        if counts.get(pid, 0) != qty:
            failures.append(f"el premio {pid!r} se asignó {counts.get(pid, 0)} veces y el stock era {qty}")
    extra = set(counts) - {pid for pid, _ in pool}
    if extra:
        failures.append(f"hay asignaciones a premios que no están en el pool: {sorted(extra)}")
    if export.get("version", 1) >= 2:
        failures += verify_audit_log(export, anchor)
    return failures


# --- Vectores de prueba -----------------------------------------------------------------------------------

def _key(label: str) -> bytes:
    return sha256(b"gamif-test-vector/" + label.encode())


def make_vectors() -> dict:
    drbg_key = _key("drbg")
    d = Drbg(drbg_key)
    drbg_bytes = d.next_bytes(100)  # cruza más de tres bloques de 32 bytes

    uniform = []
    for n in (2, 3, 5, 7, 10, 100, 1000, 65537, 3_000_000_000):  # el último fuerza descartes (~30 %)
        u = Drbg(_key(f"uniform-{n}"))
        uniform.append({"n": n, "key": _key(f"uniform-{n}").hex(), "draws": [u.uniform(n) for _ in range(40)]})

    items = list("abcdefghij")
    shuffle_key = _key("shuffle")

    pool_small = [("none", 5), ("p10k", 3), ("p50k", 1), ("p100k", 1)]
    pool_unsorted = [("p50k", 1), ("none", 5), ("p100k", 1), ("p10k", 3)]  # el orden de entrada no importa

    seed = _key("seed")
    beacon = _key("beacon")
    code_hashes = [_key(f"code-{i}") for i in range(10)]
    digest = codes_digest(code_hashes)
    chain_hash = _key("chain")

    big_pool = [("none", 700), ("p5k", 200), ("p10k", 80), ("p50k", 19), ("p100k", 1)]
    big = assign(big_pool, seed, beacon, "batch-grande")

    # --- v2: lotes con reserva. Se AGREGA una clave; las demás no cambian ni un byte.
    pool_v2 = [("none", 6), ("p10k", 3), ("extra", 2), ("p50k", 1)]  # 12 posiciones = 9 códigos + 3 de reserva
    codes_v2, reserve_v2 = assign_deck(pool_v2, 3, seed, beacon, "batch-v2")
    big_v2_pool = [("none", 700), ("p5k", 200), ("p10k", 80), ("p50k", 19), ("p100k", 1)]
    big_codes, big_reserve = assign_deck(big_v2_pool, 50, seed, beacon, "batch-grande-v2")
    v2 = {
        "mix": {"seed": seed.hex(), "beacon": beacon.hex(), "batch_id": "batch-v2", "result": mix_seed_v2(seed, beacon, "batch-v2").hex()},
        "commitment": {
            "batch_id": "batch-v2", "entries": [{"prize_id": p, "quantity": q} for p, q in pool_v2], "reserve": 3,
            "chain_hash": chain_hash.hex(), "beacon_round": 4_242_424, "seed": seed.hex(), "codes_digest": digest.hex(),
            "result": commitment_v2("batch-v2", pool_v2, 3, digest, chain_hash, 4_242_424, seed).hex(),
        },
        "assign_small": {
            "batch_id": "batch-v2", "entries": [{"prize_id": p, "quantity": q} for p, q in pool_v2], "reserve": 3,
            "seed": seed.hex(), "beacon": beacon.hex(), "codes": codes_v2, "reserve_slots": reserve_v2,
        },
        "assign_big": {
            "batch_id": "batch-grande-v2", "entries": [{"prize_id": p, "quantity": q} for p, q in big_v2_pool], "reserve": 50,
            "seed": seed.hex(), "beacon": beacon.hex(), "n": len(big_codes),
            "sha256_of_codes": sha256("\n".join(big_codes).encode()).hex(),
            "sha256_of_reserve": sha256("\n".join(big_reserve).encode()).hex(), "first_reserve_10": big_reserve[:10],
        },
    }

    return {
        "version": 1,
        "v2": v2,
        "drbg": {"key": drbg_key.hex(), "first_100_bytes": drbg_bytes.hex()},
        "uniform": uniform,
        "shuffle": {"key": shuffle_key.hex(), "items": items, "result": shuffle(items, Drbg(shuffle_key))},
        "pool": {
            "entries": [{"prize_id": p, "quantity": q} for p, q in pool_small],
            "entries_unsorted": [{"prize_id": p, "quantity": q} for p, q in pool_unsorted],
            "hash": pool_hash(pool_small).hex(),
            "expanded": expand_pool(pool_small),
        },
        "mix": {"seed": seed.hex(), "beacon": beacon.hex(), "batch_id": "batch-1", "result": mix_seed(seed, beacon, "batch-1").hex()},
        "codes_digest": {"hashes": [h.hex() for h in code_hashes], "result": digest.hex()},
        "commitment": {
            "batch_id": "batch-1",
            "chain_hash": chain_hash.hex(),
            "beacon_round": 4_242_424,
            "seed": seed.hex(),
            "codes_digest": digest.hex(),
            "result": commitment("batch-1", pool_small, digest, chain_hash, 4_242_424, seed).hex(),
        },
        "assign_small": {
            "batch_id": "batch-1",
            "seed": seed.hex(),
            "beacon": beacon.hex(),
            "result": assign(pool_small, seed, beacon, "batch-1"),
        },
        "assign_big": {
            "batch_id": "batch-grande",
            "entries": [{"prize_id": p, "quantity": q} for p, q in big_pool],
            "seed": seed.hex(),
            "beacon": beacon.hex(),
            "n": len(big),
            "sha256_of_result": sha256("\n".join(big).encode()).hex(),
            "first_20": big[:20],
        },
    }


def main(argv):
    if len(argv) >= 2 and argv[1] == "vectors":
        json.dump(make_vectors(), sys.stdout, indent=2, ensure_ascii=False, sort_keys=True)
        sys.stdout.write("\n")
        return 0
    if len(argv) in (3, 5) and argv[1] == "verify" and (len(argv) == 3 or argv[3] == "--anchor"):
        with open(argv[2], encoding="utf-8") as fh:
            export = json.load(fh)
        anchor = None
        if len(argv) == 5:
            with open(argv[4], encoding="utf-8") as fh:
                anchor = json.load(fh)
        failures = verify_export(export, anchor)
        if failures:
            print("FALLA la verificación:")
            for f in failures:
                print(" -", f)
            return 1
        print("OK: el compromiso se reproduce, la baraja coincide con las asignaciones y los conteos son el stock.")
        if export.get("version", 1) < 2:
            print("AVISO: exportación versión 1, sin bitácora: no se comprobó su integridad.")
        elif anchor is None:
            print("AVISO: sin --anchor no se puede detectar que se haya borrado el final de la bitácora.")
        print("Recuerda comprobar tú mismo el valor de la baliza contra drand (ver el encabezado de este archivo).")
        return 0
    print(__doc__)
    return 2


if __name__ == "__main__":
    sys.exit(main(sys.argv))
