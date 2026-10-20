/// De dónde sale el JWT que se envía a la API. La app host lo entrega (sabe quién es el usuario); en desarrollo local lo emite el propio
/// backend (ver `DevTokenProvider`).
abstract interface class TokenProvider {
  /// Devuelve un token vigente. Con [forceRefresh] debe pedir uno NUEVO aunque el que tenga aún no haya vencido
  /// (el servidor respondió 401: el token se rechazó por algo que el cliente no ve).
  Future<String> token({bool forceRefresh = false});
}

/// Un token fijo, p. ej. el que la app host pasa al abrir el módulo. No se renueva.
class StaticTokenProvider implements TokenProvider {
  const StaticTokenProvider(this._token);
  final String _token;

  @override
  Future<String> token({bool forceRefresh = false}) async => _token;
}

/// Adaptador a una función: la forma más simple de enchufar el sistema de sesión de la app host.
class CallbackTokenProvider implements TokenProvider {
  const CallbackTokenProvider(this._fetch);
  final Future<String> Function({required bool forceRefresh}) _fetch;

  @override
  Future<String> token({bool forceRefresh = false}) => _fetch(forceRefresh: forceRefresh);
}
