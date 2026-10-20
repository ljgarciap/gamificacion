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


class VectorsTests(unittest.TestCase):
    def test_los_vectores_publicados_estan_al_dia(self):
        # vectors.json es la fuente de verdad para otras implementaciones: debe coincidir con lo que genera el código.
        with open(os.path.join(HERE, "vectors.json"), encoding="utf-8") as fh:
            published = json.load(fh)
        self.assertEqual(json.loads(json.dumps(g.make_vectors(), sort_keys=True)), published)


if __name__ == "__main__":
    unittest.main()
