import 'package:flutter/material.dart';
import 'package:mobile_app/screens/site_screen.dart';

/// Shown instead of the app on Chrome, Windows and any other target the
/// WebView plugin does not support. There the WebView has no platform
/// implementation and would stop with a red error screen; on a computer the
/// website itself is the EduTrack app, so this points there.
class UnsupportedScreen extends StatelessWidget {
  const UnsupportedScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Scaffold(
      body: Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 420),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(Icons.phone_android_rounded, size: 48, color: theme.colorScheme.primary),
                const SizedBox(height: 16),
                Text(
                  'The EduTrack app runs on Android',
                  style: theme.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700),
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 8),
                const Text(
                  'Run it on the Pixel emulator or an Android phone. '
                  'On a computer, open the website instead:',
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 16),
                SelectableText(
                  siteUrl,
                  style: theme.textTheme.titleMedium?.copyWith(
                    color: theme.colorScheme.primary,
                    fontWeight: FontWeight.w600,
                  ),
                  textAlign: TextAlign.center,
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
