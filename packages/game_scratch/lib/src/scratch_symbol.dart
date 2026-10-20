import 'package:flutter/material.dart';
import 'package:gamification_core/gamification_core.dart';

/// Iconos de los símbolos decorativos que el servidor puede poner en la cuadrícula.
/// Un símbolo desconocido se muestra como texto, así el backend puede agregar nuevos sin romper clientes.
const _decorative = <String, IconData>{
  'star': Icons.star_rounded,
  'moon': Icons.nightlight_round,
  'sun': Icons.wb_sunny_rounded,
  'coin': Icons.monetization_on_rounded,
  'gem': Icons.diamond_rounded,
  'heart': Icons.favorite_rounded,
  'bell': Icons.notifications_rounded,
  'flash': Icons.bolt_rounded,
};

IconData _prizeIcon(PrizeType type) => switch (type) {
  PrizeType.money => Icons.payments_rounded,
  PrizeType.coupon => Icons.local_activity_rounded,
  PrizeType.points => Icons.stars_rounded,
  PrizeType.extraSpin => Icons.replay_rounded,
  PrizeType.none => Icons.sentiment_neutral_rounded,
  PrizeType.unknown => Icons.card_giftcard_rounded,
};

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
    final icon = isPrize ? _prizeIcon(prize.type) : _decorative[symbolId];

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
