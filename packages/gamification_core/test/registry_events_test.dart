import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:gamification_core/gamification_core.dart';

class _FakeGame implements GameModule {
  _FakeGame(this.id);
  @override
  final String id;
  @override
  Widget build(BuildContext context, GameSession session) => const SizedBox();
}

void main() {
  group('GameRegistry (CA-8: juego nuevo sin tocar el núcleo)', () {
    test('registra y resuelve por id', () {
      final reg = GameRegistry()..register(_FakeGame('dice'));
      expect(reg.has('dice'), isTrue);
      expect(reg.resolve('dice').id, 'dice');
      expect(reg.ids, ['dice']);
    });
    test('id duplicado falla', () {
      final reg = GameRegistry()..register(_FakeGame('dice'));
      expect(() => reg.register(_FakeGame('dice')), throwsStateError);
    });
    test('juego desconocido lanza UnknownGameException', () {
      expect(() => GameRegistry().resolve('x'), throwsA(isA<UnknownGameException>()));
    });
  });

  group('GamificationEvents', () {
    test('entrega eventos en orden a varios suscriptores', () async {
      final bus = GamificationEvents();
      final a = <GamificationEvent>[];
      final b = <GamificationEvent>[];
      bus.stream.listen(a.add);
      bus.stream.listen(b.add);
      bus.emit(const ReadyEvent());
      bus.emit(const BalanceChangedEvent(Balance(5)));
      await Future<void>.delayed(Duration.zero);
      expect(a.map((e) => e.runtimeType), [ReadyEvent, BalanceChangedEvent]);
      expect(b, hasLength(2));
      await bus.dispose();
    });
    test('emitir tras dispose no lanza', () async {
      final bus = GamificationEvents();
      await bus.dispose();
      expect(() => bus.emit(const ClosedEvent()), returnsNormally);
    });
  });
}
