enum FailureReason {
  invalidCode,
  alreadyUsed,
  expired,
  campaignInactive,
  rateLimited,
  network,

  /// El token del host no es válido o venció (HTTP 401). El cliente reintenta una vez pidiendo un token nuevo; si persiste, se muestra esto.
  unauthorized,
  unknown;

  static FailureReason parse(String? raw) {
    for (final r in FailureReason.values) {
      if (r.name == raw) return r;
    }
    return FailureReason.unknown;
  }
}

/// Error de negocio o de red al redimir un código. La UI lo traduce a texto (i18n).
class GamificationException implements Exception {
  const GamificationException(this.reason, {this.retryAfter});
  final FailureReason reason;

  /// Para [FailureReason.rateLimited]: cuánto esperar antes de reintentar.
  final Duration? retryAfter;

  @override
  String toString() => 'GamificationException($reason)';
}
