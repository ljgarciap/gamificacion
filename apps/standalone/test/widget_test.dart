import 'package:flutter_test/flutter_test.dart';
import 'package:gamification_standalone/main.dart';

void main() {
  testWidgets('la app standalone arranca en la pantalla de código con el panel demo', (tester) async {
    await tester.pumpWidget(const GamificationApp());
    await tester.pumpAndSettle();
    expect(find.text('Validar código'), findsOneWidget);
    expect(find.text('Códigos de prueba'), findsOneWidget);
  });
}
