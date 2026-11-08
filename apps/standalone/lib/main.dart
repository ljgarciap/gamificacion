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

void main() => runApp(const GamificationApp());

/// App independiente y host de demostración. Con [apiUrl] vacío usa el backend simulado; con una URL, el backend real. Al arrancar pregunta
/// `GET /v1/demo/status`: si la MUESTRA está encendida desde el panel (SPEC-006) ofrece el recorrido completo (acceso, tienda, saldo, códigos
/// y juego) con un usuario simulado; si no, el flujo de desarrollo local de siempre (token de `/v1/dev/token` y códigos de prueba del servidor).
class GamificationApp extends StatefulWidget {
  const GamificationApp({super.key, this.apiUrl = _apiUrlFromEnv, this.devUser = _devUserFromEnv, this.httpClient});

  final String apiUrl;
  final String devUser;

  /// Solo para pruebas.
  final http.Client? httpClient;

  bool get usesBackend => apiUrl.trim().isNotEmpty;

  @override
  State<GamificationApp> createState() => _GamificationAppState();
}

class _GamificationAppState extends State<GamificationApp> {
  late final GamificationRepository _repository;
  Future<Map<String, String>> Function()? _demoCodesLoader;

  /// Recorrido de la muestra (solo con backend): se decide al arrancar según `/v1/demo/status`. Null mientras se consulta.
  DemoApi? _demoApi;
  GamificationRepository? _demoRepository;
  bool? _demoOn;
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
      final dev = DevClient(baseUrl: widget.apiUrl, client: widget.httpClient);
      _repository = HttpRepository(
        baseUrl: widget.apiUrl,
        tokenProvider: DevTokenProvider(client: dev, userId: widget.devUser),
        client: widget.httpClient,
      );
      _demoCodesLoader = dev.demoCodes;
      _demoApi = DemoApi(baseUrl: widget.apiUrl, client: widget.httpClient);
      _demoRepository = HttpRepository(baseUrl: widget.apiUrl, tokenProvider: _demoApi!, client: widget.httpClient);
      _checkDemo();
    } else {
      _repository = MockRepository(latency: const Duration(milliseconds: 400));
    }
  }

  Future<void> _checkDemo() async {
    final on = await _demoApi!.status();
    if (mounted) setState(() => _demoOn = on);
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
      home: Scaffold(body: _body()),
    );
  }

  Widget _body() {
    if (widget.usesBackend && _demoOn == null) {
      // Un instante: se pregunta al servidor si la muestra está encendida antes de decidir qué pantalla ofrecer.
      return const Center(child: CircularProgressIndicator(key: ValueKey('mode-checking')));
    }
    if (_demoOn == true) {
      return DemoJourney(backend: _demoApi!, repository: _demoRepository!, registry: _registry, events: _events);
    }
    return Column(
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
