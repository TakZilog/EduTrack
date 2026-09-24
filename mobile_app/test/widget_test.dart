import 'package:flutter/foundation.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile_app/main.dart';
import 'package:mobile_app/screens/site_screen.dart';

void main() {
  test('the app opens the live site by default', () {
    expect(siteUrl, 'https://edutrack.art/EduTrack/');
    expect(Uri.parse(siteUrl).path, endsWith('/'));
  });

  testWidgets('Windows gets a note, not the WebView and its red error', (tester) async {
    debugDefaultTargetPlatformOverride = TargetPlatform.windows;
    await tester.pumpWidget(const EduTrackApp());
    expect(find.text('The EduTrack app runs on Android'), findsOneWidget);
    expect(find.text(siteUrl), findsOneWidget);
    debugDefaultTargetPlatformOverride = null;
  });
}
