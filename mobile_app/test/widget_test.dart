import 'package:flutter_test/flutter_test.dart';
import 'package:mobile_app/screens/site_screen.dart';

void main() {
  test('the site address defaults to XAMPP as the Android emulator sees it', () {
    expect(siteUrl, 'http://10.0.2.2/EduTrack/');
    expect(Uri.parse(siteUrl).path, endsWith('/'));
  });
}
