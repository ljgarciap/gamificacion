import 'dart:async';
import 'dart:convert';

import 'package:gamification_core/gamification_core.dart';
import 'package:http/http.dart' as http;

import 'token_provider.dart';

/// Cliente de la muestra real (SPEC-006 y SPEC-006A): `/v1/demo/status|auth/request|auth/verify|account|session|purchase`. Además es el
/// [TokenProvider] del [HttpRepository] de la muestra: la persona entra con su correo y un código de un solo uso (`verifyCode`); el token dura
/// pocos minutos y se renueva SOLO, reanudando la misma cuenta con la clave `subject` que devolvió el ingreso (el servidor ya no abre sesiones
/// anónimas: sin ingreso no hay token). El cliente no decide nada de la compra: manda ids y cantidades y el servidor calcula el resto.
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
  Future<void>? _renewing;

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
  Future<DemoCodeRequest> requestCode(String email) async {
    final r = await _send(
      () => _client.post(
        _url('/v1/demo/auth/request'),
        headers: const {'Content-Type': 'application/json', 'Accept': 'application/json'},
        body: jsonEncode({'email': email}),
      ),
    );
    if (r.statusCode != 200) throw _failure(r);
    try {
      final j = jsonDecode(r.body) as Map<String, dynamic>;
      return DemoCodeRequest(retryAfter: Duration(seconds: (j['retryAfter'] as num).toInt()));
    } catch (_) {
      throw const DemoException(DemoFailure.unknown);
    }
  }

  @override
  Future<DemoSession> verifyCode(String email, String code) async {
    final r = await _send(
      () => _client.post(
        _url('/v1/demo/auth/verify'),
        headers: const {'Content-Type': 'application/json', 'Accept': 'application/json'},
        body: jsonEncode({'email': email, 'code': code}),
      ),
    );
    if (r.statusCode != 200) throw _failure(r);
    return _readSession(r);
  }

  @override
  void signOut() {
    _token = null;
    _subject = null;
    _validUntil = null;
  }

  DemoSession _readSession(http.Response r) {
    try {
      final j = jsonDecode(r.body) as Map<String, dynamic>;
      final rules = (j['rules'] as Map).cast<String, dynamic>();
      final session = DemoSession(
        displayName: j['displayName'] as String,
        balance: (j['balance'] as num).toInt(),
        resumed: j['resumed'] as bool,
        isNew: (j['isNew'] as bool?) ?? false,
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

  /// Renueva el token reanudando la cuenta del ingreso. Sin ingreso previo no hay nada que reanudar. Varias peticiones simultáneas comparten
  /// UNA renovación.
  Future<void> _renew() => _renewing ??= _renewOnce().whenComplete(() => _renewing = null);

  Future<void> _renewOnce() async {
    final subject = _subject;
    if (subject == null) throw const DemoException(DemoFailure.sessionInvalid);
    final r = await _send(
      () => _client.post(
        _url('/v1/demo/session'),
        headers: const {'Content-Type': 'application/json', 'Accept': 'application/json'},
        body: jsonEncode({'resume': subject}),
      ),
    );
    if (r.statusCode != 200) {
      final failure = _failure(r);
      // La cuenta se borró (o la clave ya no sirve): se olvida la sesión para que nadie siga con un token muerto.
      if (failure.reason == DemoFailure.sessionInvalid) signOut();
      throw failure;
    }
    _readSession(r);
  }

  /// Token vigente de la cuenta; si está por vencer lo renueva. Sin ingreso previo falla (`unauthorized`).
  @override
  Future<String> token({bool forceRefresh = false}) async {
    final valid = _token != null && _validUntil != null && _clock().isBefore(_validUntil!.subtract(refreshMargin));
    if (!forceRefresh && valid) return _token!;
    try {
      await _renew();
    } on DemoException catch (e) {
      throw GamificationException(e.reason == DemoFailure.network ? FailureReason.network : FailureReason.unauthorized);
    }
    final token = _token;
    if (token == null) throw const GamificationException(FailureReason.unauthorized);
    return token;
  }

  /// Petición con el token de la cuenta. Token vencido o sesión desconocida: se renueva y se reintenta UNA vez (con la misma clave de
  /// idempotencia en las compras, así que no cobra dos veces).
  Future<http.Response> _authed(Future<http.Response> Function(String token) request) async {
    Future<http.Response> once(bool refresh) async {
      final String token;
      try {
        token = await this.token(forceRefresh: refresh);
      } on GamificationException catch (e) {
        throw DemoException(e.reason == FailureReason.network ? DemoFailure.network : DemoFailure.sessionInvalid);
      }
      return _send(() => request(token));
    }

    var r = await once(false);
    if (r.statusCode == 401 || (r.statusCode == 403 && _code(r) == 'demoSessionInvalid')) r = await once(true);
    return r;
  }

  @override
  Future<DemoAccount> account() async {
    final r = await _authed(
      (token) => _client.get(
        _url('/v1/demo/account'),
        headers: {'Accept': 'application/json', 'Authorization': 'Bearer $token'},
      ),
    );
    if (r.statusCode != 200) throw _failure(r);
    try {
      final j = jsonDecode(r.body) as Map<String, dynamic>;
      return DemoAccount(
        balance: (j['balance'] as num).toInt(),
        codes: [
          for (final c in j['codes'] as List)
            DemoOwnedCode(code: (c as Map)['code'] as String, game: c['game'] as String, played: c['played'] as bool),
        ],
      );
    } catch (_) {
      throw const DemoException(DemoFailure.unknown);
    }
  }

  @override
  Future<DemoOrder> purchase(List<DemoLine> lines, {required String idempotencyKey}) async {
    final body = jsonEncode({
      'items': [
        for (final l in lines) {'productId': l.productId, 'quantity': l.quantity},
      ],
    });
    final r = await _authed(
      (token) => _client.post(
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
      'invalidEmail' => const DemoException(DemoFailure.invalidEmail),
      'invalidCode' => const DemoException(DemoFailure.invalidCode),
      'mailUnavailable' => const DemoException(DemoFailure.mailUnavailable),
      'accountsFull' => const DemoException(DemoFailure.accountsFull),
      'invalidPurchase' => DemoException(DemoFailure.invalidPurchase, message: message),
      'demoSessionInvalid' || 'unauthenticated' => const DemoException(DemoFailure.sessionInvalid),
      'rateLimited' => const DemoException(DemoFailure.rateLimited),
      _ => switch (r.statusCode) {
        404 => const DemoException(DemoFailure.off),
        401 => const DemoException(DemoFailure.sessionInvalid),
        429 => const DemoException(DemoFailure.rateLimited),
        422 => const DemoException(DemoFailure.invalidEmail),
        502 || 503 || 504 => const DemoException(DemoFailure.network),
        _ => const DemoException(DemoFailure.unknown),
      },
    };
  }

  void close() => _client.close();
}
