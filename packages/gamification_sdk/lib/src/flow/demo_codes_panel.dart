import 'package:flutter/material.dart';
import 'package:gamification_core/gamification_core.dart';

import 'demo_codes.dart';

/// Códigos de prueba agrupados: un bloque desplegable por juego y, dentro, «Con premio» y «Sin premio» (cada uno muestra los primeros y deja
/// ver el resto). Con pocos códigos se muestran en una sola lista. Tocar un código lo entrega con [onPick].
class DemoCodesPanel extends StatelessWidget {
  const DemoCodesPanel({
    super.key,
    required this.codes,
    required this.onPick,
    required this.strings,
    required this.theme,
  });

  /// Cuántos códigos se muestran de entrada en cada bloque antes de «Ver los N».
  static const preview = 4;

  /// Con tan pocos códigos no vale la pena agrupar.
  static const flatLimit = 6;

  final Map<String, String> codes;
  final void Function(String code) onPick;
  final GamificationStrings strings;
  final GamificationTheme theme;

  @override
  Widget build(BuildContext context) {
    if (codes.length <= flatLimit) {
      return Column(children: [for (final e in codes.entries) _tile(DemoCode(e.key, e.value))]);
    }
    final groups = groupDemoCodes(codes);
    return Column(
      children: [
        for (final g in groups)
          ExpansionTile(
            key: ValueKey('demo-group-${g.title}'),
            tilePadding: const EdgeInsets.symmetric(horizontal: 8),
            childrenPadding: const EdgeInsets.only(left: 12),
            title: Text(g.title, style: const TextStyle(fontWeight: FontWeight.w700)),
            subtitle: Text(
              g.other.isNotEmpty
                  ? '${g.total}'
                  : '${strings.demoWithPrize(g.withPrize.length)} · ${strings.demoWithoutPrize(g.withoutPrize.length)}',
              style: TextStyle(fontSize: 12, color: theme.navy.withValues(alpha: 0.6)),
            ),
            children: [
              if (g.withPrize.isNotEmpty)
                _Block(
                  id: '${g.title}-win',
                  title: strings.demoWithPrize(g.withPrize.length),
                  codes: g.withPrize,
                  onPick: onPick,
                  strings: strings,
                ),
              if (g.withoutPrize.isNotEmpty)
                _Block(
                  id: '${g.title}-none',
                  title: strings.demoWithoutPrize(g.withoutPrize.length),
                  codes: g.withoutPrize,
                  onPick: onPick,
                  strings: strings,
                ),
              for (final c in g.other) _tile(c),
            ],
          ),
      ],
    );
  }

  Widget _tile(DemoCode c) => ListTile(
    key: ValueKey('demo-code-${c.code}'),
    dense: true,
    title: Text(c.code, style: const TextStyle(fontWeight: FontWeight.w700)),
    subtitle: Text(c.description),
    onTap: () => onPick(c.code),
  );
}

/// «Con premio» o «Sin premio» de un juego: los primeros códigos y un botón para ver el resto.
class _Block extends StatefulWidget {
  const _Block({
    required this.id,
    required this.title,
    required this.codes,
    required this.onPick,
    required this.strings,
  });

  final String id;
  final String title;
  final List<DemoCode> codes;
  final void Function(String code) onPick;
  final GamificationStrings strings;

  @override
  State<_Block> createState() => _BlockState();
}

class _BlockState extends State<_Block> {
  bool _all = false;

  @override
  Widget build(BuildContext context) {
    final shown = _all ? widget.codes : widget.codes.take(DemoCodesPanel.preview).toList();
    final more = widget.codes.length > DemoCodesPanel.preview;
    return ExpansionTile(
      key: ValueKey('demo-block-${widget.id}'),
      tilePadding: const EdgeInsets.symmetric(horizontal: 8),
      dense: true,
      title: Text(widget.title),
      children: [
        for (final c in shown)
          ListTile(
            key: ValueKey('demo-code-${c.code}'),
            dense: true,
            title: Text(c.code, style: const TextStyle(fontWeight: FontWeight.w700)),
            subtitle: Text(c.detail),
            onTap: () => widget.onPick(c.code),
          ),
        if (more)
          TextButton(
            key: ValueKey('demo-more-${widget.id}'),
            onPressed: () => setState(() => _all = !_all),
            child: Text(_all ? widget.strings.demoShowLess : widget.strings.demoShowAll(widget.codes.length)),
          ),
      ],
    );
  }
}
