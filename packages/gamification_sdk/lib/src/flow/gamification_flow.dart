import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gamification_core/gamification_core.dart';

import 'code_entry_screen.dart';
import 'flow_controller.dart';
import 'flow_state.dart';
import 'result_screen.dart';

/// Flujo común: ingreso de código -> juego de la campaña -> resultado.
/// No usa `Navigator`: alterna etapas dentro del mismo widget para no chocar con las rutas del host.
/// Requiere un `MaterialApp` (u otro ancestro con Directionality y localizaciones) del host.
class GamificationFlow extends StatelessWidget {
  const GamificationFlow({
    super.key,
    required this.repository,
    required this.registry,
    required this.events,
    this.preferences = const GamePreferences(),
    this.locale = const Locale('es'),
    this.theme = const GamificationTheme(),
    this.title,
    this.helpText,
    this.showBalance = true,
    this.demoCodes,
    this.onClose,
    this.onContinue,
    this.onBackHome,
  });

  final GamificationRepository repository;
  final GameRegistry registry;
  final GamificationEvents events;
  final GamePreferences preferences;
  final Locale locale;

  /// Tema de las pantallas previas a conocer la campaña; después manda `campaign.theme`.
  final GamificationTheme theme;
  final String? title;
  final String? helpText;
  final bool showBalance;
  final Map<String, String>? demoCodes;

  /// Si es null no se dibuja el botón cerrar.
  final VoidCallback? onClose;
  final VoidCallback? onContinue;
  final VoidCallback? onBackHome;

  @override
  Widget build(BuildContext context) {
    return ProviderScope(
      overrides: [
        repositoryProvider.overrideWithValue(repository),
        registryProvider.overrideWithValue(registry),
        eventsProvider.overrideWithValue(events),
      ],
      child: _FlowRoot(owner: this),
    );
  }
}

class _FlowRoot extends ConsumerStatefulWidget {
  const _FlowRoot({required this.owner});
  final GamificationFlow owner;

  @override
  ConsumerState<_FlowRoot> createState() => _FlowRootState();
}

class _FlowRootState extends ConsumerState<_FlowRoot> {
  GamificationFlow get o => widget.owner;

  @override
  void initState() {
    super.initState();
    // Fuera del build: ready y la carga de saldo ocurren una sola vez al montar.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      o.events.emit(const ReadyEvent());
      if (o.showBalance) ref.read(flowControllerProvider.notifier).loadBalance();
    });
  }

  void _close() {
    o.events.emit(const ClosedEvent());
    o.onClose?.call();
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(flowControllerProvider);
    final controller = ref.read(flowControllerProvider.notifier);
    final strings = GamificationStrings.of(o.locale);
    final theme = state.redemption?.campaign.theme ?? o.theme;
    final title = state.redemption?.campaign.title ?? o.title ?? strings.defaultTitle;

    return Theme(
      data: theme.toThemeData(),
      child: GamificationScope(
        theme: theme,
        strings: strings,
        preferences: o.preferences,
        child: Builder(
          builder: (context) => Material(
            color: theme.background,
            child: SafeArea(
              child: Align(
                alignment: Alignment.topCenter,
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 480),
                  child: Column(
                    children: [
                      _Header(
                        balance: o.showBalance ? state.balance : null,
                        // Cerrar se bloquea mientras corre la animación: el premio ya está asegurado.
                        onClose: o.onClose == null || state.animating ? null : _close,
                        note: state.animating ? strings.prizeSecured : null,
                      ),
                      Expanded(child: _stage(context, state, controller, title)),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _stage(BuildContext context, FlowState state, FlowController controller, String title) {
    switch (state.stage) {
      case FlowStage.enterCode:
      case FlowStage.validating:
        return CodeEntryScreen(
          key: const ValueKey('code'),
          title: title,
          helpText: o.helpText,
          demoCodes: o.demoCodes,
        );
      case FlowStage.playing:
        final redemption = state.redemption!;
        final module = o.registry.resolve(redemption.gameType);
        return KeyedSubtree(
          key: ValueKey('game-${redemption.redemptionId}'),
          child: module.build(
            context,
            GameSession(
              redemption: redemption,
              preferences: o.preferences,
              onStarted: controller.gameStarted,
              onFinished: controller.gameFinished,
            ),
          ),
        );
      case FlowStage.result:
        return ResultScreen(
          key: ValueKey('result-${state.redemption!.redemptionId}'),
          redemption: state.redemption!,
          onContinue: () {
            controller.reset();
            o.onContinue?.call();
          },
          onBackHome: () {
            controller.reset();
            if (o.onBackHome != null) {
              o.onBackHome!.call();
            } else if (o.onClose != null) {
              _close();
            }
          },
        );
    }
  }
}

class _Header extends StatelessWidget {
  const _Header({this.balance, this.onClose, this.note});

  final Balance? balance;
  final VoidCallback? onClose;
  final String? note;

  @override
  Widget build(BuildContext context) {
    final scope = GamificationScope.of(context);
    final theme = scope.theme;
    final reduce = GamificationScope.reduceMotion(context);

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 8, 8, 0),
      child: Row(
        children: [
          if (balance != null)
            Expanded(
              child: Semantics(
                container: true,
                label: '${scope.strings.balanceLabel} ${formatCop(balance!.amount)}',
                child: ExcludeSemantics(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(scope.strings.balanceLabel,
                          style: TextStyle(fontSize: 12, color: theme.navy.withValues(alpha: 0.7))),
                      // El saldo hace count-up cuando cambia (premio en dinero).
                      TweenAnimationBuilder<int>(
                        tween: IntTween(end: balance!.amount),
                        duration: reduce ? Duration.zero : const Duration(milliseconds: 900),
                        curve: Curves.easeOut,
                        builder: (_, value, __) => Text(formatCop(value),
                            style: TextStyle(
                                fontSize: 20, fontWeight: FontWeight.w800, color: theme.navy)),
                      ),
                    ],
                  ),
                ),
              ),
            )
          else
            const Spacer(),
          if (note != null)
            Flexible(
              child: Text(note!,
                  textAlign: TextAlign.end,
                  style: TextStyle(fontSize: 12, color: theme.navy.withValues(alpha: 0.7))),
            ),
          if (onClose != null)
            IconButton(
              tooltip: scope.strings.close,
              onPressed: onClose,
              icon: Icon(Icons.close_rounded, color: theme.navy),
            ),
        ],
      ),
    );
  }
}
