/// Estado de una carta: tapada, destapada esperando su pareja, o ya resuelta (pareja hallada o revelada).
enum CardState { down, up, matched }

enum FlipOutcome {
  /// El toque no hizo nada (carta ya destapada, o se está resolviendo una pareja distinta).
  ignored,

  /// Primera carta de un turno.
  first,

  /// Segunda carta: coincide con la primera.
  match,

  /// Segunda carta: no coincide; hay que taparlas de nuevo con [MemoryBoard.hidePending].
  mismatch,
}

/// Lógica pura del tablero (sin Flutter): qué cartas están destapadas y cuándo hay pareja.
/// No decide nada: la disposición viene del servidor y aquí solo se comparan símbolos iguales.
class MemoryBoard {
  MemoryBoard(this.cards) : _state = List.filled(cards.length, CardState.down);

  final List<String> cards;
  final List<CardState> _state;
  int? _first;
  List<int> _pending = const [];

  /// Símbolo de la última pareja hallada.
  String? lastMatch;

  CardState stateAt(int index) => _state[index];

  /// Hay dos cartas distintas a la vista esperando taparse: no se aceptan más toques hasta [hidePending].
  bool get hasPending => _pending.isNotEmpty;

  int get foundPairs => _state.where((s) => s == CardState.matched).length ~/ 2;

  bool get allResolved => _state.every((s) => s == CardState.matched);

  FlipOutcome flip(int index) {
    if (hasPending || _state[index] != CardState.down) return FlipOutcome.ignored;
    _state[index] = CardState.up;

    final first = _first;
    if (first == null) {
      _first = index;
      return FlipOutcome.first;
    }
    _first = null;
    if (cards[first] == cards[index]) {
      _state[first] = CardState.matched;
      _state[index] = CardState.matched;
      lastMatch = cards[index];
      return FlipOutcome.match;
    }
    _pending = [first, index];
    return FlipOutcome.mismatch;
  }

  /// Tapa de nuevo las dos cartas distintas.
  void hidePending() {
    for (final i in _pending) {
      _state[i] = CardState.down;
    }
    _pending = const [];
  }

  /// Destapa todo (botón "Revelar todo").
  void revealAll() {
    for (var i = 0; i < _state.length; i++) {
      _state[i] = CardState.matched;
    }
    _first = null;
    _pending = const [];
  }
}
