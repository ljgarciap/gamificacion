/// Un código de prueba con lo que dice su descripción («Juego: premio»).
class DemoCode {
  const DemoCode(this.code, this.description);

  final String code;
  final String description;

  /// Lo que va después de «Juego:» (el premio).
  String get detail =>
      description.contains(':') ? description.substring(description.indexOf(':') + 1).trim() : description;

  bool get isNoPrize => detail.toLowerCase().contains('sin premio');

  /// Monto («\$ 50.000») o puntos («250 puntos») que dice la descripción, para ordenar de menor a mayor premio; null si no trae (un cupón, un
  /// giro extra…). Los demás números de la descripción (p. ej. «segmento 3») no cuentan.
  int? get amount {
    final m =
        RegExp(r'\$\s*(\d[\d.]*)').firstMatch(detail) ??
        RegExp(r'(\d[\d.]*)\s*puntos', caseSensitive: false).firstMatch(detail);
    return m == null ? null : int.tryParse(m.group(1)!.replaceAll('.', ''));
  }
}

/// Los códigos de un juego, separados en con premio y sin premio. Los que no son de un juego (p. ej. los errores simulados) van en [other].
class DemoGroup {
  DemoGroup(this.title);

  final String title;
  final List<DemoCode> withPrize = [];
  final List<DemoCode> withoutPrize = [];
  final List<DemoCode> other = [];

  int get total => withPrize.length + withoutPrize.length + other.length;
}

/// Agrupa los códigos de prueba por juego (lo que va antes de «:» en la descripción), en el orden en que aparecen, y cada juego en con premio
/// (de menor a mayor premio) y sin premio. Un grupo «Error» no tiene premio: queda todo en [DemoGroup.other].
List<DemoGroup> groupDemoCodes(Map<String, String> codes) {
  final groups = <String, DemoGroup>{};
  for (final e in codes.entries) {
    final code = DemoCode(e.key, e.value);
    final title = e.value.contains(':') ? e.value.substring(0, e.value.indexOf(':')).trim() : 'Otros';
    final g = groups.putIfAbsent(title, () => DemoGroup(title));
    if (title.toLowerCase() == 'error' || title == 'Otros') {
      g.other.add(code);
    } else if (code.isNoPrize) {
      g.withoutPrize.add(code);
    } else {
      g.withPrize.add(code);
    }
  }
  for (final g in groups.values) {
    // Orden estable por monto ascendente; los que no traen número conservan su lugar al final.
    final indexed = [for (var i = 0; i < g.withPrize.length; i++) (i, g.withPrize[i])];
    indexed.sort((a, b) {
      final x = a.$2.amount, y = b.$2.amount;
      if (x != null && y != null && x != y) return x.compareTo(y);
      if (x == null && y != null) return 1;
      if (x != null && y == null) return -1;
      return a.$1.compareTo(b.$1);
    });
    g.withPrize
      ..clear()
      ..addAll(indexed.map((e) => e.$2));
  }
  return groups.values.toList();
}
