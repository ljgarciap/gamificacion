import 'package:flutter/material.dart';
import 'package:game_roulette/game_roulette.dart';
import 'package:game_scratch/game_scratch.dart';
import 'package:gamification_core/gamification_core.dart';
import 'package:gamification_sdk/gamification_sdk.dart';

void main() => runApp(const GamificationApp());

/// App independiente y host de demostración: usa el backend simulado y muestra los códigos de prueba.
class GamificationApp extends StatefulWidget {
  const GamificationApp({super.key});

  @override
  State<GamificationApp> createState() => _GamificationAppState();
}

class _GamificationAppState extends State<GamificationApp> {
  late final _repository = MockRepository(latency: const Duration(milliseconds: 400));
  late final _registry = GameRegistry()
    ..register(RouletteModule())
    ..register(ScratchModule());
  final _events = GamificationEvents();

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
        body: GamificationFlow(
          repository: _repository,
          registry: _registry,
          events: _events,
          helpText: 'Tu código está en el comprobante de tu transacción.',
          demoCodes: MockRepository.demoCodes,
        ),
      ),
    );
  }
}
