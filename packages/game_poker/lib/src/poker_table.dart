import 'poker_config.dart';

/// Lógica pura de la mesa (sin Flutter): el crupier abre el flop (3 cartas), el turn (1) y el river (1). La mejor mano que se va viendo en
/// cada etapa la manda el servidor.
class PokerTable {
  PokerTable(this.config);

  final PokerConfig config;
  int _stage = 0; // 0 = nada abierto; 1 = flop; 2 = turn; 3 = river

  /// Cuántas cartas de la mesa están abiertas (0, 3, 4 o 5).
  int get opened => const [0, 3, 4, 5][_stage];

  bool get isOver => _stage >= 3;

  /// La mejor mano que se tiene con lo abierto hasta ahora (null antes del flop).
  String? get current => _stage == 0 ? null : config.stages[_stage - 1];

  /// Abre la siguiente etapa. Devuelve false si ya estaba todo abierto.
  bool next() {
    if (isOver) return false;
    _stage++;
    return true;
  }

  /// "Revelar todo": abre lo que falta. No cambia el resultado.
  void revealAll() => _stage = 3;

  /// ¿Esta carta de la mesa (posición 0 a 4) ya está abierta?
  bool isBoardOpen(int index) => index < opened;
}
