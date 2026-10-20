#!/usr/bin/env python3
"""Verificador independiente del sorteo de premios (SPEC-002 §4).

Implementación de referencia, SOLO con la biblioteca estándar de Python, escrita aparte del backend a
propósito: si dos implementaciones independientes coinciden byte a byte, la especificación es inequívoca,
y un tercero puede comprobar una campaña sin confiar en nuestro servidor.

Uso:
    python3 gamif_verify.py verify export.json     # verifica la exportación de auditoría de un lote
    python3 gamif_verify.py vectors > vectors.json # genera los vectores de prueba del motor

Lo que verifica y lo que NO:
  - SÍ: que el compromiso publicado se reproduce con la semilla revelada, que la baraja recalculada coincide con
    las asignaciones usadas y que los conteos son exactamente el stock del pool.
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
DOMAIN_SHUFFLE = b"gamif/v1/shuffle"

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


# --- Verificación de una exportación de auditoría ---------------------------------------------------------

def verify_export(export: dict):
    """Devuelve la lista de fallos encontrados (vacía = todo coincide)."""
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
    except (KeyError, ValueError, TypeError) as exc:
        return [f"exportación incompleta o mal formada: {exc!r}"]

    if "code_hashes" in export:
        digest_calc = codes_digest([bytes.fromhex(h) for h in export["code_hashes"]])
        if digest_calc != digest_pub:
            failures.append("codes_digest no coincide con la lista de huellas publicada")
        if len(export["code_hashes"]) != len(assignments):
            failures.append("la cantidad de huellas no coincide con la de asignaciones")

    try:
        calc = commitment(batch_id, pool, digest_pub, chain_hash, beacon_round, seed)
    except ValueError as exc:
        return failures + [f"pool inválido: {exc}"]
    if calc != commit_pub:
        failures.append("el compromiso NO se reproduce con la semilla, el pool y la baliza publicados")

    expected = assign(pool, seed, beacon, batch_id)
    if len(expected) != len(assignments):
        failures.append(f"se esperaban {len(expected)} asignaciones y hay {len(assignments)}")
    else:
        bad = [i for i, (a, b) in enumerate(zip(expected, assignments)) if a != b]
        if bad:
            failures.append(f"{len(bad)} asignaciones difieren de la baraja recalculada (primer índice: {bad[0]})")

    counts = {}
    for p in assignments:
        counts[p] = counts.get(p, 0) + 1
    for pid, qty in sorted_entries(pool):
        if counts.get(pid, 0) != qty:
            failures.append(f"el premio {pid!r} se asignó {counts.get(pid, 0)} veces y el stock era {qty}")
    extra = set(counts) - {pid for pid, _ in pool}
    if extra:
        failures.append(f"hay asignaciones a premios que no están en el pool: {sorted(extra)}")
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

    return {
        "version": 1,
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
    if len(argv) == 3 and argv[1] == "verify":
        with open(argv[2], encoding="utf-8") as fh:
            failures = verify_export(json.load(fh))
        if failures:
            print("FALLA la verificación:")
            for f in failures:
                print(" -", f)
            return 1
        print("OK: el compromiso se reproduce, la baraja coincide con las asignaciones y los conteos son el stock.")
        print("Recuerda comprobar tú mismo el valor de la baliza contra drand (ver el encabezado de este archivo).")
        return 0
    print(__doc__)
    return 2


if __name__ == "__main__":
    sys.exit(main(sys.argv))
