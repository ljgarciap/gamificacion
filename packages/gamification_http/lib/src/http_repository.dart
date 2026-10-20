import 'dart:async';
import 'dart:convert';

import 'package:gamification_core/gamification_core.dart';
import 'package:http/http.dart' as http;

import 'token_provider.dart';

/// Implementación de [GamificationRepository] contra la API v1 (docs/api-v1.md, docs/openapi.yaml).
///
/// - Autenticación: `Authorization: Bearer <JWT>` que entrega el [TokenProvider]. Ante un 401 pide UN token nuevo y reintenta una vez
///   (seguro: lleva la misma `Idempotency-Key`, así que el servidor nunca juega dos veces); si vuelve a fallar, [FailureReason.unauthorized].
/// - Idempotencia: la clave la genera el flujo (una por intento) y se reutiliza al reintentar tras un fallo de red; aquí solo se envía.
/// - Errores: todo fallo se convierte en [GamificationException] con un [FailureReason]; nunca se filtra una excepción de red o de formato.
/// - Sin `dart:io`: funciona igual en móvil y en Flutter Web.
class HttpRepository implements GamificationRepository {
  HttpRepository({
    required String baseUrl,
    required this.tokenProvider,
    http.Client? client,
    this.timeout = const Duration(seconds: 15),
  }) : _base = _normalize(baseUrl),
       _client = client ?? http.Client();

  final Uri _base;
  final TokenProvider tokenProvider;
  final http.Client _client;

  /// Tiempo máximo de espera por petición. Al vencer se trata como un fallo de red (reintentable).
  final Duration timeout;

  static Uri _normalize(String baseUrl) {
    final uri = Uri.parse(baseUrl.trim());
    if (!uri.hasScheme || uri.host.isEmpty) {
      throw ArgumentError.value(baseUrl, 'baseUrl', 'debe ser una URL absoluta, p. ej. https://api.ejemplo.com');
    }
    return uri;
  }

  Uri _url(String path) {
    final prefix = _base.path.endsWith('/') ? _base.path.substring(0, _base.path.length - 1) : _base.path;
    return _base.replace(path: '$prefix$path');
  }

  @override
  Future<Redemption> redeem(String code, {required String idempotencyKey}) async {
    final response = await _send(
      'POST',
      '/v1/codes/redeem',
      headers: {'Idempotency-Key': idempotencyKey, 'Content-Type': 'application/json'},
      body: jsonEncode({'code': code}),
    );
    if (response.statusCode != 200) throw _failure(response);
    try {
      return Redemption.fromJson(_decodeObject(response.body));
    } catch (_) {
      // Un 200 que no se puede leer (cuerpo roto, campos que faltan) es un error del servidor o del contrato, no del usuario.
      throw const GamificationException(FailureReason.unknown);
    }
  }

  @override
  Future<Balance> getBalance() async {
    final response = await _send('GET', '/v1/balance');
    if (response.statusCode != 200) throw _failure(response);
    try {
      return Balance.fromJson(_decodeObject(response.body));
    } catch (_) {
      throw const GamificationException(FailureReason.unknown);
    }
  }

  // --- Transporte ---------------------------------------------------------------------------------------------------

  Future<http.Response> _send(
    String method,
    String path, {
    Map<String, String> headers = const {},
    String? body,
  }) async {
    var response = await _once(method, path, headers, body, forceRefresh: false);
    if (response.statusCode == 401) {
      // El servidor rechazó el token: se pide uno nuevo y se reintenta UNA vez.
      response = await _once(method, path, headers, body, forceRefresh: true);
    }
    return response;
  }

  Future<http.Response> _once(
    String method,
    String path,
    Map<String, String> headers,
    String? body, {
    required bool forceRefresh,
  }) async {
    final String token;
    try {
      token = await tokenProvider.token(forceRefresh: forceRefresh);
    } on GamificationException {
      rethrow;
    } catch (_) {
      throw const GamificationException(FailureReason.unauthorized);
    }

    final request = http.Request(method, _url(path))
      ..headers.addAll({'Accept': 'application/json', 'Authorization': 'Bearer $token', ...headers});
    if (body != null) request.body = body;

    try {
      final streamed = await _client.send(request).timeout(timeout);
      return await http.Response.fromStream(streamed).timeout(timeout);
    } on TimeoutException {
      throw const GamificationException(FailureReason.network);
    } on http.ClientException {
      // Sin conexión, DNS, CORS bloqueado, conexión cortada... En web todo esto llega como ClientException.
      throw const GamificationException(FailureReason.network);
    }
  }

  // --- Errores ------------------------------------------------------------------------------------------------------

  GamificationException _failure(http.Response response) {
    final status = response.statusCode;
    String? code;
    int? retryAfter;
    try {
      final error = (_decodeObject(response.body)['error'] as Map?)?.cast<String, dynamic>();
      code = error?['code'] as String?;
      retryAfter = (error?['retryAfter'] as num?)?.toInt();
    } catch (_) {
      // Cuerpo que no es el formato de error esperado (p. ej. una página HTML de un proxy): se decide por el estado HTTP.
    }
    retryAfter ??= int.tryParse(response.headers['retry-after'] ?? '');

    final reason = switch (code) {
      'invalidCode' => FailureReason.invalidCode,
      'alreadyUsed' => FailureReason.alreadyUsed,
      'expired' => FailureReason.expired,
      'campaignInactive' => FailureReason.campaignInactive,
      'rateLimited' => FailureReason.rateLimited,
      'unauthenticated' => FailureReason.unauthorized,
      // idempotencyKeyReuse / idempotencyKeyRequired / serverError: errores de uso o internos, no accionables por el usuario.
      String() => FailureReason.unknown,
      null => switch (status) {
        401 => FailureReason.unauthorized,
        429 => FailureReason.rateLimited,
        // Puerta de enlace caída o saturada: es transitorio, igual que una caída de red.
        502 || 503 || 504 => FailureReason.network,
        _ => FailureReason.unknown,
      },
    };
    return GamificationException(
      reason,
      retryAfter: reason == FailureReason.rateLimited && retryAfter != null ? Duration(seconds: retryAfter) : null,
    );
  }

  static Map<String, dynamic> _decodeObject(String body) {
    final decoded = jsonDecode(body);
    if (decoded is! Map) throw const FormatException('se esperaba un objeto JSON');
    return decoded.cast<String, dynamic>();
  }

  void close() => _client.close();
}
