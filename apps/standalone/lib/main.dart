import 'package:flutter/material.dart';
import 'package:game_bingo/game_bingo.dart';
import 'package:game_bomb/game_bomb.dart';
import 'package:game_dice/game_dice.dart';
import 'package:game_memory/game_memory.dart';
import 'package:game_poker/game_poker.dart';
import 'package:game_roulette/game_roulette.dart';
import 'package:game_scratch/game_scratch.dart';
import 'package:gamification_core/gamification_core.dart';
import 'package:gamification_http/gamification_http.dart';
import 'package:gamification_sdk/gamification_sdk.dart';
import 'package:http/http.dart' as http;

/// URL del backend. Se fija al compilar: `--dart-define=GAMIF_API_URL=http://localhost:8000`. Vacía = backend SIMULADO (sin servidor).
const _apiUrlFromEnv = String.fromEnvironment('GAMIF_API_URL');

/// Usuario de desarrollo con el que se pide el token al backend (solo modo backend real, solo desarrollo local).
const _devUserFromEnv = String.fromEnvironment('GAMIF_DEV_USER', defaultValue: 'demo-user');

/// Modo demo del servidor (`GAMIF_DEMO_MODE=true`): usa /v1/demo/* en vez de /v1/dev/*. Se fija al compilar: `--dart-define=GAMIF_DEMO_MODE=true`.
const _demoModeFromEnv = bool.fromEnvironment('GAMIF_DEMO_MODE');

void main() => runApp(const GamificationApp());

/// App independiente y host de demostración. Con [apiUrl] vacío usa el backend simulado; con una URL, el backend real:
/// el token lo pide al endpoint de desarrollo y los códigos de prueba salen del servidor (los que aún no se han canjeado).
class GamificationApp extends StatefulWidget {
  const GamificationApp({
    super.key,
    this.apiUrl = _apiUrlFromEnv,
    this.devUser = _devUserFromEnv,
    this.demoMode = _demoModeFromEnv,
    this.httpClient,
  });

  final String apiUrl;
  final String devUser;
  final bool demoMode;

  /// Solo para pruebas.
  final http.Client? httpClient;

  bool get usesBackend => apiUrl.trim().isNotEmpty;

  @override
  State<GamificationApp> createState() => _GamificationAppState();
}

class _GamificationAppState extends State<GamificationApp> {
  late final GamificationRepository _repository;
  Future<Map<String, String>> Function()? _demoCodesLoader;
  late final _registry = GameRegistry()
    ..register(RouletteModule())
    ..register(ScratchModule())
    ..register(MemoryModule())
    ..register(DiceModule())
    ..register(BombModule())
    ..register(BingoModule())
    ..register(PokerModule());
  final _events = GamificationEvents();

  @override
  void initState() {
    super.initState();
    if (widget.usesBackend) {
      final dev = DevClient(baseUrl: widget.apiUrl, client: widget.httpClient, demoMode: widget.demoMode);
      _repository = HttpRepository(
        baseUrl: widget.apiUrl,
        tokenProvider: DevTokenProvider(client: dev, userId: widget.devUser),
        client: widget.httpClient,
      );
      _demoCodesLoader = dev.demoCodes;
    } else {
      _repository = MockRepository(latency: const Duration(milliseconds: 400));
    }
  }

  @override
  void dispose() {
    _events.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Gamificación',
      debugShowCheckedModeBanner: false,
      theme: const GamificationTheme().toThemeData(),
      home: Scaffold(
        body: Column(
          children: [
            Expanded(
              child: GamificationFlow(
                repository: _repository,
                registry: _registry,
                events: _events,
                helpText: 'Tu código está en el comprobante de tu transacción.',
                demoCodes: widget.usesBackend ? null : MockRepository.demoCodes,
                demoCodesLoader: _demoCodesLoader,
              ),
            ),
            _ModeFooter(
              widget.usesBackend
                  ? 'Backend: ${widget.apiUrl} · usuario ${widget.devUser}'
                  : 'Modo demostración (backend simulado)',
            ),
          ],
        ),
      ),
    );
  }
}

/// Indica contra qué backend se está probando, para no confundir una demostración simulada con el servidor real.
class _ModeFooter extends StatelessWidget {
  const _ModeFooter(this.text);
  final String text;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      color: const Color(0xFFE6E9EF),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      child: SafeArea(
        top: false,
        child: Text(
          text,
          key: const ValueKey('mode-footer'),
          textAlign: TextAlign.center,
          style: const TextStyle(fontSize: 11, color: Color(0xFF5B6475)),
        ),
      ),
    );
  }
}
