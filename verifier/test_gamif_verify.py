"""Pruebas del verificador de referencia. Solo biblioteca estándar: `python3 -m unittest discover verifier`."""
import copy
import json
import os
import unittest
from collections import Counter

import gamif_verify as g

HERE = os.path.dirname(os.path.abspath(__file__))


def sample_export():
    pool = [("none", 6), ("p10k", 3), ("p50k", 1)]
    seed = g._key("export-seed")
    beacon = g._key("export-beacon")
    chain = g._key("export-chain")
    hashes = [g._key(f"export-code-{i}") for i in range(10)]
    digest = g.codes_digest(hashes)
    return {
        "version": 1,
        "batch_id": "lote-ejemplo",
        "chain_hash": chain.hex(),
        "beacon_round": 777,
        "beacon_value": beacon.hex(),
        "pool": [{"prize_id": p, "quantity": q} for p, q in pool],
        "codes_digest": digest.hex(),
        "commitment": g.commitment("lote-ejemplo", pool, digest, chain, 777, seed).hex(),
        "seed": seed.hex(),
        "code_hashes": [h.hex() for h in hashes],
        "assignments": g.assign(pool, seed, beacon, "lote-ejemplo"),
    }


def reserve_export(reserve=3):
    """Lote con reserva: 12 posiciones = 9 códigos + 3 casillas de reserva (compromiso v2)."""
    pool = [("none", 6), ("p10k", 3), ("extra", 2), ("p50k", 1)]
    seed, beacon, chain = g._key("v2-seed"), g._key("v2-beacon"), g._key("v2-chain")
    hashes = [g._key(f"v2-code-{i}") for i in range(12 - reserve)]
    digest = g.codes_digest(hashes)
    codes, slots = g.assign_deck(pool, reserve, seed, beacon, "lote-v2")
    return {
        "version": 1, "commit_version": 2, "batch_id": "lote-v2", "chain_hash": chain.hex(), "beacon_round": 9,
        "beacon_value": beacon.hex(), "pool": [{"prize_id": p, "quantity": q} for p, q in pool], "codes_digest": digest.hex(),
        "commitment": g.commitment_v2("lote-v2", pool, reserve, digest, chain, 9, seed).hex(), "seed": seed.hex(),
        "code_hashes": [h.hex() for h in hashes], "assignments": codes, "reserve_assignments": slots,
    }


class ReserveTests(unittest.TestCase):
    def test_con_reserva_cero_el_barajado_es_exactamente_el_v1(self):
        pool = [("none", 6), ("p10k", 3), ("p50k", 1)]
        s, b = g._key("s"), g._key("b")
        self.assertEqual(g.assign_deck(pool, 0, s, b, "x"), (g.assign(pool, s, b, "x"), []))

    def test_exportacion_con_reserva_valida(self):
        self.assertEqual(g.verify_export(reserve_export()), [])

    def test_el_stock_cuenta_codigos_y_reserva(self):
        e = reserve_export()
        counts = Counter(e["assignments"] + e["reserve_assignments"])
        self.assertEqual(counts, Counter({"none": 6, "p10k": 3, "extra": 2, "p50k": 1}))

    def test_detecta_una_casilla_de_reserva_alterada(self):
        e = reserve_export()
        slots = e["reserve_assignments"]
        other = "p50k" if slots[0] != "p50k" else "none"
        slots[0] = other
        self.assertTrue(g.verify_export(e))

    def test_detecta_dos_casillas_intercambiadas_aunque_el_stock_no_cambie(self):
        e = reserve_export()
        slots = e["reserve_assignments"]
        i, j = next((a, b) for a in range(len(slots)) for b in range(len(slots)) if slots[a] != slots[b])
        slots[i], slots[j] = slots[j], slots[i]
        self.assertTrue(any("reserva difiere" in f for f in g.verify_export(e)))

    def test_detecta_un_intercambio_entre_codigo_y_reserva(self):
        e = reserve_export()
        i = next(k for k, p in enumerate(e["assignments"]) if p != e["reserve_assignments"][0])
        e["assignments"][i], e["reserve_assignments"][0] = e["reserve_assignments"][0], e["assignments"][i]
        self.assertTrue(g.verify_export(e), "el stock total no cambia, pero la baraja sí")

    def test_la_reserva_cambia_la_baraja_y_el_compromiso(self):
        a, b = reserve_export(3), reserve_export(2)
        self.assertNotEqual(a["commitment"], b["commitment"])

    def test_v2_con_reserva_cero_se_rechaza(self):
        e = sample_export()
        e["commit_version"] = 2
        self.assertTrue(any("versión de compromiso" in f for f in g.verify_export(e)))

    def test_v1_con_reserva_se_rechaza(self):
        e = reserve_export()
        e["commit_version"] = 1
        self.assertTrue(any("versión de compromiso" in f for f in g.verify_export(e)))

    def test_el_compromiso_v2_liga_la_reserva(self):
        e = reserve_export(3)
        e["reserve_assignments"] = e["reserve_assignments"][:2]
        e["assignments"] = e["assignments"] + ["none"]
        self.assertTrue(g.verify_export(e))

    def test_reservas_invalidas(self):
        pool = [("none", 3)]
        for bad in (0, -1, 3, 4, True, 1.5):
            with self.assertRaises(ValueError):
                g.check_reserve(pool, bad)


class UniformTests(unittest.TestCase):
    def test_sin_sesgo_exacto_en_rango_reducido(self):
        # La lógica de descarte, comprobada de forma exacta: con un "rango" de 256 valores, todo resultado posible
        # debe salir de exactamente el mismo número de entradas (floor(256 / n)).
        for n in range(2, 256):
            limit = (256 // n) * n
            counts = Counter(x % n for x in range(256) if x < limit)
            self.assertEqual(set(counts.values()), {256 // n}, f"n={n}")
            self.assertEqual(len(counts), n, f"n={n}")

    def test_n_uno_no_consume_bytes(self):
        d = g.Drbg(g._key("a"))
        self.assertEqual(d.uniform(1), 0)
        self.assertEqual(d.counter, 0)

    def test_rango_invalido(self):
        d = g.Drbg(g._key("a"))
        for bad in (0, -1, g.MAX_WORD + 1):
            with self.assertRaises(ValueError):
                d.uniform(bad)

    def test_los_descartes_ocurren_con_n_grande(self):
        # Con n = 3e9 aproximadamente 30 % de las lecturas se descartan: se debe consumir más de 4 bytes por resultado.
        d = g.Drbg(g._key("rechazo"))
        for _ in range(200):
            d.uniform(3_000_000_000)
        consumed = d.counter * 32 - len(d.buffer)
        self.assertGreater(consumed, 200 * 4)


class ShuffleTests(unittest.TestCase):
    def test_es_una_permutacion_y_determinista(self):
        items = list(range(50))
        a = g.shuffle(items, g.Drbg(g._key("s")))
        b = g.shuffle(items, g.Drbg(g._key("s")))
        self.assertEqual(a, b)
        self.assertEqual(sorted(a), items)

    def test_semillas_distintas_barajas_distintas(self):
        items = list(range(30))
        self.assertNotEqual(g.shuffle(items, g.Drbg(g._key("1"))), g.shuffle(items, g.Drbg(g._key("2"))))

    def test_el_orden_de_entrada_del_pool_no_cambia_el_resultado(self):
        seed, beacon = g._key("x"), g._key("y")
        a = g.assign([("a", 2), ("b", 3), ("c", 1)], seed, beacon, "L")
        b = g.assign([("c", 1), ("a", 2), ("b", 3)], seed, beacon, "L")
        self.assertEqual(a, b)

    def test_conteos_exactos_en_pools_aleatorios(self):
        import random
        rnd = random.Random(12345)  # solo para generar casos de prueba; el motor no usa random
        for _ in range(200):
            pool = [(f"p{i}", rnd.randint(0, 20)) for i in range(rnd.randint(1, 8))]
            if sum(q for _, q in pool) == 0:
                continue
            out = g.assign(pool, g._key("seed"), g._key("beacon"), "L")
            self.assertEqual(Counter(out), Counter({p: q for p, q in pool if q > 0}))

    def test_batch_id_distinto_baraja_distinta(self):
        pool = [("a", 20), ("b", 20)]
        s, b = g._key("s"), g._key("b")
        self.assertNotEqual(g.assign(pool, s, b, "uno"), g.assign(pool, s, b, "dos"))


class PoolTests(unittest.TestCase):
    def test_invalidos(self):
        for bad in ([], [("a", 0)], [("a", -1), ("b", 5)], [("a", 1), ("a", 2)], [("", 1)], [("x" * 65, 1)]):
            with self.assertRaises(ValueError, msg=str(bad)):
                g.validate_pool(bad)

    def test_hash_ignora_el_orden_pero_no_los_datos(self):
        self.assertEqual(g.pool_hash([("a", 1), ("b", 2)]), g.pool_hash([("b", 2), ("a", 1)]))
        self.assertNotEqual(g.pool_hash([("a", 1), ("b", 2)]), g.pool_hash([("a", 2), ("b", 1)]))


class VerifyExportTests(unittest.TestCase):
    def test_exportacion_valida(self):
        self.assertEqual(g.verify_export(sample_export()), [])

    def test_detecta_una_asignacion_alterada(self):
        e = sample_export()
        i = e["assignments"].index("p50k")
        j = e["assignments"].index("none")
        e["assignments"][i], e["assignments"][j] = e["assignments"][j], e["assignments"][i]
        self.assertTrue(g.verify_export(e), "un intercambio de premios debe detectarse")

    def test_detecta_un_solo_registro_cambiado(self):
        e = sample_export()
        e["assignments"][0] = "p50k" if e["assignments"][0] != "p50k" else "none"
        failures = g.verify_export(e)
        self.assertTrue(any("difieren" in f or "stock" in f for f in failures), failures)

    def test_detecta_semilla_falsa(self):
        e = sample_export()
        e["seed"] = g._key("otra").hex()
        self.assertTrue(any("compromiso" in f for f in g.verify_export(e)))

    def test_detecta_pool_alterado(self):
        e = sample_export()
        e["pool"][0]["quantity"] += 1
        self.assertTrue(g.verify_export(e))

    def test_detecta_baliza_alterada(self):
        e = sample_export()
        e["beacon_value"] = g._key("falsa").hex()
        self.assertTrue(g.verify_export(e), "otra baliza produce otra baraja")

    def test_detecta_ronda_distinta(self):
        e = sample_export()
        e["beacon_round"] += 1
        self.assertTrue(any("compromiso" in f for f in g.verify_export(e)))

    def test_detecta_huellas_alteradas(self):
        e = sample_export()
        e["code_hashes"][3] = g._key("intruso").hex()
        self.assertTrue(any("codes_digest" in f for f in g.verify_export(e)))

    def test_exportacion_incompleta(self):
        e = sample_export()
        del e["seed"]
        self.assertTrue(any("incompleta" in f for f in g.verify_export(e)))

    def test_no_muta_la_entrada(self):
        e = sample_export()
        copia = copy.deepcopy(e)
        g.verify_export(e)
        self.assertEqual(e, copia)


def load_fixture(name):
    with open(os.path.join(HERE, "fixtures", name), encoding="utf-8") as fh:
        return json.load(fh)


def rehash_from(entries, start):
    """Reescribe la historia COHERENTEMENTE: recalcula los hashes desde `start` para que la cadena siga siendo válida."""
    prev = bytes.fromhex(entries[start]["prev_hash"])
    for e in entries[start:]:
        e["prev_hash"] = prev.hex()
        prev = g.audit_entry_hash(prev, e)
        e["hash"] = prev.hex()
    return entries[-1]["hash"]


class AuditLogTests(unittest.TestCase):
    """Sobre una exportación REAL generada por el backend (verifier/fixtures; se regenera con UPDATE_VERIFIER_FIXTURE=1)."""

    def setUp(self):
        self.export = load_fixture("export-sample.json")
        self.anchor = load_fixture("anchor-sample.json")

    def index_of(self, action):
        return next(i for i, e in enumerate(self.export["audit_log"]) if e["action"] == action and e["subject_id"] == self.export["batch_id"])

    def test_la_exportacion_real_y_su_ancla_verifican(self):
        self.assertEqual(g.verify_export(self.export, self.anchor), [])

    def test_la_cadena_reproduce_el_hash_del_backend(self):
        e = self.export["audit_log"][0]
        self.assertEqual(bytes.fromhex(e["prev_hash"]), g.AUDIT_GENESIS)
        self.assertEqual(g.audit_entry_hash(g.AUDIT_GENESIS, e).hex(), e["hash"])

    def test_detecta_una_entrada_alterada(self):
        self.export["audit_log"][2]["actor"] = "intruso"
        self.assertTrue(any("alterada" in f for f in g.verify_export(self.export)))

    def test_detecta_entradas_reordenadas(self):
        log = self.export["audit_log"]
        log[1], log[2] = log[2], log[1]
        self.assertTrue(any("rota o reordenada" in f for f in g.verify_export(self.export)))

    def test_detecta_una_entrada_eliminada_en_el_medio(self):
        del self.export["audit_log"][3]
        self.assertTrue(any("rota o reordenada" in f for f in g.verify_export(self.export)))

    def test_borrar_el_final_sin_ancla_NO_se_detecta_con_ancla_si(self):
        """El límite conocido (R4): sin ancla, una cadena truncada es válida. Con ancla publicada, se detecta."""
        del self.export["audit_log"][-1]
        failures_sin_ancla = g.verify_export(self.export)
        self.assertFalse(any("bitácora" in f and "final" in f for f in failures_sin_ancla))
        self.assertTrue(any("se perdió el final" in f for f in g.verify_export(self.export, self.anchor)))

    def test_reescribir_la_historia_coherentemente_lo_detecta_el_ancla(self):
        log = self.export["audit_log"]
        log[0]["actor"] = "otro"
        rehash_from(log, 0)
        self.assertEqual([f for f in g.verify_export(self.export) if "alterada" in f or "rota" in f], [], "la cadena reescrita es válida")
        self.assertTrue(any("reescrita" in f for f in g.verify_export(self.export, self.anchor)))

    def test_detecta_un_ancla_de_otra_historia(self):
        self.anchor["head_hash"] = "00" * 32
        self.assertTrue(any("reescrita" in f for f in g.verify_export(self.export, self.anchor)))

    def test_la_semilla_exportada_debe_coincidir_con_la_registrada(self):
        i = self.index_of("batch.revealed")
        data = json.loads(self.export["audit_log"][i]["data"])
        data["seed"] = g._key("otra").hex()
        self.export["audit_log"][i]["data"] = json.dumps(data, sort_keys=True, separators=(",", ":"))
        rehash_from(self.export["audit_log"], i)
        self.assertTrue(any("semilla" in f for f in g.verify_export(self.export)))

    def test_el_compromiso_exportado_debe_coincidir_con_el_registrado(self):
        i = self.index_of("batch.committed")
        data = json.loads(self.export["audit_log"][i]["data"])
        data["commitment"] = "11" * 32
        self.export["audit_log"][i]["data"] = json.dumps(data, sort_keys=True, separators=(",", ":"))
        rehash_from(self.export["audit_log"], i)
        self.assertTrue(any("compromiso" in f and "bitácora" in f for f in g.verify_export(self.export)))

    def test_sin_bitacora_en_una_exportacion_v2_es_un_fallo(self):
        del self.export["audit_log"]
        self.assertTrue(any("audit_log" in f for f in g.verify_export(self.export)))

    def test_una_exportacion_v1_sigue_verificando_el_sorteo(self):
        e = sample_export()
        self.assertEqual(g.verify_export(e), [])

    def test_ancla_mal_formada(self):
        self.assertTrue(any("ancla mal formada" in f for f in g.verify_export(self.export, {"head_id": "x"})))


class VectorsTests(unittest.TestCase):
    def test_los_vectores_publicados_estan_al_dia(self):
        # vectors.json es la fuente de verdad para otras implementaciones: debe coincidir con lo que genera el código.
        with open(os.path.join(HERE, "vectors.json"), encoding="utf-8") as fh:
            published = json.load(fh)
        self.assertEqual(json.loads(json.dumps(g.make_vectors(), sort_keys=True)), published)


if __name__ == "__main__":
    unittest.main()
