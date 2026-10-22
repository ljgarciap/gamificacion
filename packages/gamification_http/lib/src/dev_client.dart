import 'dart:async';
import 'dart:convert';

import 'package:gamification_core/gamification_core.dart';
import 'package:http/http.dart' as http;

import 'token_provider.dart';

/// Utilidades de la app independiente para autenticarse y mostrar códigos de prueba sin una app host.
///
/// Dos modos del servidor: desarrollo local (`/v1/dev/*`, solo existen con APP_ENV=local) y modo demo ([demoMode], `/v1/demo/*`, el servidor
/// los activa a propósito con GAMIF_DEMO_MODE=true y vale también en un servidor de demostración).
class DevClient {
  DevClient({
    required String baseUrl,
    http.Client? client,
    this.timeout = const Duration(seconds: 10),
    this.demoMode = false,
  }) : _base = Uri.parse(baseUrl.trim()),
       _client = client ?? http.Client();

  /// Usa las rutas `/v1/demo/*` en lugar de `/v1/dev/*`.
  final bool demoMode;
  String get _tokenPath => demoMode ? '/v1/demo/token' : '/v1/dev/token';
  String get _codesPath => demoMode ? '/v1/demo/codes' : '/v1/dev/demo-codes';

  final Uri _base;
  final http.Client _client;
  final Duration timeout;

  Uri _url(String path) {
    final prefix = _base.path.endsWith('/') ? _base.path.substring(0, _base.path.length - 1) : _base.path;
    return _base.replace(path: '$prefix$path');
  }

  /// Pide un token para [userId].
  Future<DevToken> requestToken(String userId) async {
    final response = await _call(
      () => _client.post(
        _url(_tokenPath),
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
        body: jsonEncode({'userId': userId}),
      ),
    );
    if (response.statusCode != 200) throw const GamificationException(FailureReason.unauthorized);
    try {
      final json = jsonDecode(response.body) as Map<String, dynamic>;
      return DevToken(
        token: json['token'] as String,
        expiresIn: Duration(seconds: (json['expiresIn'] as num).toInt()),
        userId: json['userId'] as String,
      );
    } catch (_) {
      throw const GamificationException(FailureReason.unknown);
    }
  }

  /// Códigos de demostración que todavía se pueden canjear: código -> descripción. Si falla, devuelve un mapa vacío (es solo una ayuda).
  Future<Map<String, String>> demoCodes() async {
    try {
      final response = await _call(() => _client.get(_url(_codesPath), headers: {'Accept': 'application/json'}));
      if (response.statusCode != 200) return const {};
      final list = (jsonDecode(response.body) as Map<String, dynamic>)['codes'] as List;
      return {for (final c in list) (c as Map)['code'] as String: c['description'] as String};
    } catch (_) {
      return const {};
    }
  }

  Future<http.Response> _call(Future<http.Response> Function() request) async {
    try {
      return await request().timeout(timeout);
    } on TimeoutException {
      throw const GamificationException(FailureReason.network);
    } on http.ClientException {
      throw const GamificationException(FailureReason.network);
    }
  }

  void close() => _client.close();
}

class DevToken {
  const DevToken({required this.token, required this.expiresIn, required this.userId});
  final String token;
  final Duration expiresIn;
  final String userId;
}

/// [TokenProvider] de desarrollo: pide el token al propio backend y lo reutiliza hasta poco antes de que venza.
class DevTokenProvider implements TokenProvider {
  DevTokenProvider({
    required this.client,
    this.userId = 'demo-user',
    this.refreshMargin = const Duration(seconds: 30),
    DateTime Function()? clock,
  }) : _clock = clock ?? DateTime.now;

  final DevClient client;
  final String userId;

  /// Se renueva este tiempo ANTES de que venza, para no enviar un token que caduca en el camino.
  final Duration refreshMargin;
  final DateTime Function() _clock;

  DevToken? _current;
  DateTime? _validUntil;
  Future<DevToken>? _inFlight;

  @override
  Future<String> token({bool forceRefresh = false}) async {
    final valid = _current != null && _validUntil != null && _clock().isBefore(_validUntil!.subtract(refreshMargin));
    if (!forceRefresh && valid) return _current!.token;

    // Varias peticiones simultáneas comparten UNA petición de token.
    final fetched = await (_inFlight ??= client.requestToken(userId).whenComplete(() => _inFlight = null));
    _current = fetched;
    _validUntil = _clock().add(fetched.expiresIn);
    return fetched.token;
  }
}
