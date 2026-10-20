import 'package:flutter/material.dart';
import 'package:gamification_core/gamification_core.dart';

/// Contenido de una casilla. Si el símbolo es el id del premio, muestra el icono y el valor del premio.
class ScratchSymbol extends StatelessWidget {
  const ScratchSymbol({
    super.key,
    required this.symbolId,
    required this.prize,
    required this.strings,
    required this.color,
  });

  final String symbolId;
  final Prize prize;
  final GamificationStrings strings;
  final Color color;

  @override
  Widget build(BuildContext context) {
    final isPrize = symbolId == prize.id;
    final icon = isPrize ? prizeIcon(prize.type) : decorativeSymbolIcon(symbolId);

    return Padding(
      padding: const EdgeInsets.all(6),
      child: FittedBox(
        fit: BoxFit.scaleDown,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            if (icon != null) Icon(icon, size: 56, color: color),
            if (isPrize || icon == null)
              Text(
                isPrize ? strings.prizeDescription(prize) : symbolId,
                textAlign: TextAlign.center,
                style: TextStyle(fontWeight: FontWeight.w800, fontSize: 16, color: color),
              ),
          ],
        ),
      ),
    );
  }
}
