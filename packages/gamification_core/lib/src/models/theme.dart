import 'dart:ui' show Color;

/// Tokens de diseño (ver docs/ux-spec-001.md). Por defecto, la paleta de SuperGIROS.
class GamificationTheme {
  const GamificationTheme({
    this.primary = const Color(0xFF0B4EF0),
    this.navy = const Color(0xFF14235C),
    this.accent = const Color(0xFFFFC800),
    this.info = const Color(0xFF21C1F3),
    this.background = const Color(0xFFF2F4F7),
    this.surface = const Color(0xFFFFFFFF),
    this.error = const Color(0xFFD32F2F),
    this.success = const Color(0xFF1B9E5A),
    this.fontFamily,
    this.logoUrl,
  });

  /// Lee solo las claves presentes; el resto conserva el valor por defecto.
  factory GamificationTheme.fromJson(Map<String, dynamic> json) {
    const d = GamificationTheme();
    Color c(String key, Color fallback) => _parseHex(json[key] as String?) ?? fallback;
    return GamificationTheme(
      primary: c('primary', d.primary),
      navy: c('navy', d.navy),
      accent: c('accent', d.accent),
      info: c('info', d.info),
      background: c('background', d.background),
      surface: c('surface', d.surface),
      error: c('error', d.error),
      success: c('success', d.success),
      fontFamily: json['fontFamily'] as String?,
      logoUrl: json['logoUrl'] as String?,
    );
  }

  final Color primary;
  final Color navy;
  final Color accent;
  final Color info;
  final Color background;
  final Color surface;
  final Color error;
  final Color success;
  final String? fontFamily;
  final String? logoUrl;

  /// Colores que alternan en los segmentos de la ruleta.
  List<Color> get segmentColors => [primary, info, navy, accent];

  @override
  bool operator ==(Object other) =>
      other is GamificationTheme &&
      other.primary == primary &&
      other.navy == navy &&
      other.accent == accent &&
      other.info == info &&
      other.background == background &&
      other.surface == surface &&
      other.error == error &&
      other.success == success &&
      other.fontFamily == fontFamily &&
      other.logoUrl == logoUrl;

  @override
  int get hashCode => Object.hash(
      primary, navy, accent, info, background, surface, error, success, fontFamily, logoUrl);

  static Color? _parseHex(String? hex) {
    if (hex == null) return null;
    final h = hex.startsWith('#') ? hex.substring(1) : hex;
    if (h.length != 6) return null;
    final v = int.tryParse(h, radix: 16);
    return v == null ? null : Color(0xFF000000 | v);
  }
}
