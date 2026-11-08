import 'dart:async';
import 'dart:convert';

import 'package:gamification_core/gamification_core.dart';
import 'package:http/http.dart' as http;

import 'token_provider.dart';

/// Cliente de la muestra real (SPEC-006): `/v1/demo/status|session|purchase`. Además es el [TokenProvider] del [HttpRepository] de la
/// muestra: el token de la sesión simulada dura pocos minutos y se renueva SOLO, reanudando la misma sesión (mismo usuario y saldo) con la
/// clave `subject` que devolvió la primera. El cliente no decide nada de la compra: manda ids y cantidades y el servidor calcula el resto.
class DemoApi implements DemoBackend, TokenProvider {
  DemoApi({
    required String baseUrl,
    http.Client? client,
    this.timeout = const Duration(seconds: 10),
    this.refreshMargin = const Duration(seconds: 30),
    this.statusRetries = 2,
    this.statusRetryDelay = const Duration(milliseconds: 500),
    DateTime Function()? clock,
  }) : _base = Uri.parse(baseUrl.trim()),
       _client = client ?? http.Client(),
       _clock = clock ?? DateTime.now;

  final Uri _base;
  final http.Client _client;
  final Duration timeout;
  final Duration refreshMargin;

  /// Reintentos de `status` ante un fallo TRANSITORIO (sin red, tiempo agotado, 5xx): un corte al arrancar no debe hacer creer que la muestra está
  /// apagada y mandar al flujo de desarrollo. Un 200 o un 404 son respuestas definitivas y no se reintentan.
  final int statusRetries;
  final Duration statusRetryDelay;
  final DateTime Function() _clock;

  String? _token;
  String? _subject;
  DateTime? _validUntil;
  Future<DemoSession>? _opening;

  Uri _url(String path) {
    final prefix = _base.path.endsWith('/') ? _base.path.substring(0, _base.path.length - 1) : _base.path;
    return _base.replace(path: '$prefix$path');
  }

  @override
  Future<bool> status() async {
    for (var attempt = 0; ; attempt++) {
      try {
        final r = await _send(
          () => _client.get(_url('/v1/demo/status'), headers: const {'Accept': 'application/json'}),
        );
        if (r.statusCode >= 500 && attempt < statusRetries) {
          await Future<void>.delayed(statusRetryDelay);
          continue;
        }
        return r.statusCode == 200 && (jsonDecode(r.body) as Map)['enabled'] == true;
      } on DemoException {
        if (attempt >= statusRetries) return false;
        await Future<void>.delayed(statusRetryDelay);
      } catch (_) {
        return false;
      }
    }
  }

  @override
  Future<DemoSession> openSession() => _open(resume: _subject);

  /// Varias peticiones simultáneas comparten UNA apertura de sesión.
  Future<DemoSession> _open({String? resume}) => _opening ??= _openOnce(resume).whenComplete(() => _opening = null);

  Future<DemoSession> _openOnce(String? resume) async {
    final r = await _send(
      () => _client.post(
        _url('/v1/demo/session'),
        headers: const {'Content-Type': 'application/json', 'Accept': 'application/json'},
        body: jsonEncode({if (resume != null) 'resume': resume}),
      ),
    );
    if (r.statusCode != 200) throw _failure(r);
    try {
      final j = jsonDecode(r.body) as Map<String, dynamic>;
      final rules = (j['rules'] as Map).cast<String, dynamic>();
      final session = DemoSession(
        displayName: j['displayName'] as String,
        balance: (j['balance'] as num).toInt(),
        resumed: j['resumed'] as bool,
        products: [
          for (final p in j['products'] as List)
            DemoProduct(id: (p as Map)['id'] as String, name: p['name'] as String, price: (p['price'] as num).toInt()),
        ],
        rules: DemoRules(
          balancePercent: (rules['balancePercent'] as num).toInt(),
          minPurchase: (rules['minPurchase'] as num).toInt(),
          codeStepAmount: (rules['codeStepAmount'] as num).toInt(),
          maxCodesPerPurchase: (rules['maxCodesPerPurchase'] as num).toInt(),
          maxCodesPerSession: (rules['maxCodesPerSession'] as num).toInt(),
        ),
      );
      _token = j['token'] as String;
      _subject = j['subject'] as String;
      _validUntil = _clock().add(Duration(seconds: (j['expiresIn'] as num).toInt()));
      return session;
    } catch (_) {
      throw const DemoException(DemoFailure.unknown);
    }
  }

  /// Token vigente de la sesión; si aún no hay sesión la abre, y si está por vencer la renueva reanudándola.
  @override
  Future<String> token({bool forceRefresh = false}) async {
    final valid = _token != null && _validUntil != null && _clock().isBefore(_validUntil!.subtract(refreshMargin));
    if (!forceRefresh && valid) return _token!;
    try {
      await _open(resume: _subject);
    } on DemoException catch (e) {
      throw GamificationException(e.reason == DemoFailure.network ? FailureReason.network : FailureReason.unauthorized);
    }
    return _token!;
  }

  @override
  Future<DemoOrder> purchase(List<DemoLine> lines, {required String idempotencyKey}) async {
    final body = jsonEncode({
      'items': [
        for (final l in lines) {'productId': l.productId, 'quantity': l.quantity},
      ],
    });
    Future<http.Response> once(bool refresh) async {
      final String token;
      try {
        token = await this.token(forceRefresh: refresh);
      } on GamificationException catch (e) {
        throw DemoException(e.reason == FailureReason.network ? DemoFailure.network : DemoFailure.sessionInvalid);
      }
      return _send(
        () => _client.post(
          _url('/v1/demo/purchase'),
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'Authorization': 'Bearer $token',
            'Idempotency-Key': idempotencyKey,
          },
          body: body,
        ),
      );
    }

    var r = await once(false);
    // Token vencido o sesión desconocida: se renueva reanudando la sesión y se reintenta UNA vez (misma clave: no cobra dos veces).
    if (r.statusCode == 401 || (r.statusCode == 403 && _code(r) == 'demoSessionInvalid')) r = await once(true);
    if (r.statusCode != 200) throw _failure(r);
    try {
      final j = jsonDecode(r.body) as Map<String, dynamic>;
      return DemoOrder(
        orderId: j['orderId'] as String,
        total: (j['total'] as num).toInt(),
        credited: (j['credited'] as num).toInt(),
        capReached: j['capReached'] as bool,
        balance: (j['balance'] as num).toInt(),
        replayed: j['replayed'] as bool,
        codes: [
          for (final c in j['codes'] as List)
            DemoDeliveredCode(code: (c as Map)['code'] as String, game: c['game'] as String),
        ],
      );
    } catch (_) {
      throw const DemoException(DemoFailure.unknown);
    }
  }

  Future<http.Response> _send(Future<http.Response> Function() request) async {
    try {
      return await request().timeout(timeout);
    } on TimeoutException {
      throw const DemoException(DemoFailure.network);
    } on http.ClientException {
      throw const DemoException(DemoFailure.network);
    }
  }

  String? _code(http.Response r) {
    try {
      return ((jsonDecode(r.body) as Map)['error'] as Map?)?['code'] as String?;
    } catch (_) {
      return null;
    }
  }

  DemoException _failure(http.Response r) {
    final code = _code(r);
    String? message;
    try {
      message = ((jsonDecode(r.body) as Map)['error'] as Map?)?['message'] as String?;
    } catch (_) {}
    return switch (code) {
      'demoPoolExhausted' => const DemoException(DemoFailure.poolExhausted),
      'invalidPurchase' => DemoException(DemoFailure.invalidPurchase, message: message),
      'demoSessionInvalid' || 'unauthenticated' => const DemoException(DemoFailure.sessionInvalid),
      'rateLimited' => const DemoException(DemoFailure.rateLimited),
      _ => switch (r.statusCode) {
        404 => const DemoException(DemoFailure.off),
        401 => const DemoException(DemoFailure.sessionInvalid),
        429 => const DemoException(DemoFailure.rateLimited),
        502 || 503 || 504 => const DemoException(DemoFailure.network),
        _ => const DemoException(DemoFailure.unknown),
      },
    };
  }

  void close() => _client.close();
}
