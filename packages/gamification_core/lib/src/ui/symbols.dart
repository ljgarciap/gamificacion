import 'package:flutter/material.dart';

import '../models/prize.dart';

/// Iconos de los símbolos decorativos que el servidor puede poner en un tablero (raspa, Concéntrese...).
/// Un símbolo desconocido devuelve null: quien lo dibuje lo muestra como texto, así el backend puede agregar
/// símbolos nuevos sin romper clientes viejos.
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

IconData? decorativeSymbolIcon(String symbolId) => _decorative[symbolId];

/// Icono del tipo de premio. Compartido por todos los juegos para que un premio se vea igual en cualquiera.
IconData prizeIcon(PrizeType type) => switch (type) {
  PrizeType.money => Icons.payments_rounded,
  PrizeType.coupon => Icons.local_activity_rounded,
  PrizeType.points => Icons.stars_rounded,
  PrizeType.extraSpin => Icons.replay_rounded,
  PrizeType.none => Icons.sentiment_neutral_rounded,
  PrizeType.unknown => Icons.card_giftcard_rounded,
};
