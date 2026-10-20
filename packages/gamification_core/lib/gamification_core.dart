/// Núcleo de la plataforma de minijuegos: modelos, contrato con el backend, eventos y
/// registro de juegos. Los juegos y el SDK dependen de este paquete, nunca al revés.
library;

export 'src/data/mock_repository.dart';
export 'src/data/repository.dart';
export 'src/events.dart';
export 'src/games/feedback.dart';
export 'src/games/game_module.dart';
export 'src/games/game_registry.dart';
export 'src/models/campaign.dart';
export 'src/models/failure.dart';
export 'src/models/money.dart';
export 'src/models/prize.dart';
export 'src/models/redemption.dart';
export 'src/models/theme.dart';
export 'src/ui/scope.dart';
export 'src/ui/strings.dart';
