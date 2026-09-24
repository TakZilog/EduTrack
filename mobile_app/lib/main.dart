import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:mobile_app/screens/site_screen.dart';
import 'package:mobile_app/screens/unsupported_screen.dart';

/// The WebView plugin has an implementation for Android and iOS only. Chrome
/// and Windows, which Android Studio also offers as run targets, get a short
/// note instead of a red error screen.
bool get webViewSupported =>
    !kIsWeb &&
    (defaultTargetPlatform == TargetPlatform.android || defaultTargetPlatform == TargetPlatform.iOS);

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(const EduTrackApp());
}

/// The app is the EduTrack website in a full-screen WebView, so every change
/// made to the site shows up here without rebuilding the app. See
/// [SiteScreen] for where the site address comes from.
class EduTrackApp extends StatelessWidget {
  const EduTrackApp({super.key});

  // The website's own palette (DESIGN.md): Directory Blue on a cool page.
  static const Color blue = Color(0xFF0044D6);
  static const Color page = Color(0xFFEEF2F8);

  @override
  Widget build(BuildContext context) {
    // Dark status bar icons over the light page, like the site's header.
    SystemChrome.setSystemUIOverlayStyle(const SystemUiOverlayStyle(
      statusBarColor: page,
      statusBarIconBrightness: Brightness.dark,
      systemNavigationBarColor: page,
      systemNavigationBarIconBrightness: Brightness.dark,
    ));

    return MaterialApp(
      title: 'EduTrack',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        colorScheme: ColorScheme.fromSeed(seedColor: blue, primary: blue, surface: page),
        scaffoldBackgroundColor: page,
        useMaterial3: true,
      ),
      home: webViewSupported ? const SiteScreen() : const UnsupportedScreen(),
    );
  }
}
