import 'bingo_config.dart';

/// Lo que pasó al sacar una bola.
class BingoDraw {
  const BingoDraw({required this.number, required this.cell, required this.events});

  final int number;

  /// Casilla del cartón que se marcó (null si la bola no está en el cartón).
  final (int, int)? cell;

  /// Patrones que esta bola completó.
  final List<BingoEvent> events;
}

/// Lógica pura de la partida (sin Flutter): las bolas salen en el orden del servidor y se marcan solas en el cartón. Las casillas y los
/// eventos (patrones completados) también los manda el servidor: aquí no se detecta nada.
class BingoBoard {
  BingoBoard(this.config) {
    for (var r = 0; r < BingoConfig.size; r++) {
      for (var c = 0; c < BingoConfig.size; c++) {
        final n = config.card[r][c];
        if (n != null) _cellOf[n] = (r, c);
      }
    }
  }

  final BingoConfig config;
  final Map<int, (int, int)> _cellOf = {};
  final Set<(int, int)> _marked = {(2, 2)}; // el centro es libre
  int _drawn = 0;

  int get drawn => _drawn;

  /// La partida terminó (se sacó la bola `endAt`).
  bool get isOver => _drawn >= config.endAt;

  /// Las últimas [n] bolas, de la más reciente a la más antigua.
  List<int> recent([int n = 5]) => [for (var i = _drawn - 1; i >= 0 && i >= _drawn - n; i--) config.balls[i]];

  bool isMarked(int row, int col) => _marked.contains((row, col));

  /// Eventos ya ocurridos.
  List<BingoEvent> get happened => [
    for (final e in config.events)
      if (e.at <= _drawn) e,
  ];

  /// Saca la siguiente bola. Devuelve null si la partida ya terminó.
  BingoDraw? next() {
    if (isOver) return null;
    final number = config.balls[_drawn];
    _drawn++;
    final cell = _cellOf[number];
    if (cell != null) _marked.add(cell);
    return BingoDraw(
      number: number,
      cell: cell,
      events: [
        for (final e in config.events)
          if (e.at == _drawn) e,
      ],
    );
  }

  /// "Revelar todo": saca las bolas que faltan hasta el final de la partida. No cambia el resultado.
  void revealAll() {
    while (!isOver) {
      next();
    }
  }
}
