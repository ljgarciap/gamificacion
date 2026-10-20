import 'game_module.dart';

class UnknownGameException implements Exception {
  const UnknownGameException(this.gameType);
  final String gameType;
  @override
  String toString() => 'UnknownGameException: no hay un juego registrado para "$gameType"';
}

class GameRegistry {
  final _modules = <String, GameModule>{};

  void register(GameModule module) {
    if (_modules.containsKey(module.id)) {
      throw StateError('Ya existe un juego registrado con id "${module.id}"');
    }
    _modules[module.id] = module;
  }

  bool has(String gameType) => _modules.containsKey(gameType);

  GameModule resolve(String gameType) => _modules[gameType] ?? (throw UnknownGameException(gameType));

  Iterable<String> get ids => _modules.keys;
}
