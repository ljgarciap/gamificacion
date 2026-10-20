import 'package:flutter_test/flutter_test.dart';
import 'package:gamification_sdk/gamification_sdk.dart';

void main() {
  group('normalizeCode', () {
    test('mayúsculas y sin espacios', () {
      expect(normalizeCode('  ruleta 50k '), 'RULETA50K');
      expect(normalizeCode('a\tb\nc'), 'ABC');
    });
    test('vacío queda vacío', () => expect(normalizeCode('   '), ''));
  });
}
