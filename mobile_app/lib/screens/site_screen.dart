import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:webview_flutter/webview_flutter.dart';
import 'package:webview_flutter_android/webview_flutter_android.dart';
import 'package:webview_flutter_wkwebview/webview_flutter_wkwebview.dart';

/// Where the EduTrack website lives, as the phone sees it.
///
/// The live site is the default. To test against XAMPP instead, pass the PC's
/// address (debug builds only; release builds refuse plain http). The
/// emulator reaches the PC at 10.0.2.2, a phone on the same Wi-Fi at its LAN
/// address:
///
///   flutter run --dart-define=SITE_URL=http://192.168.1.20/EduTrack/
///
/// (In Android Studio: Run > Edit Configurations > Additional run args.)
const String siteUrl = String.fromEnvironment(
  'SITE_URL',
  defaultValue: 'https://edutrack.art/EduTrack/',
);

/// Path segments that the mobile app must never load. The admin panel is a
/// web-only tool; exposing it in the app risks leaking staff credentials on
/// a device that may be shared, screen-recorded, or shoulder-surfed.
///
/// Matching is case-insensitive and checks every segment of the URL path, so
/// `…/admin/login.html`, `…/api/admin/settings.php` and any future sub-path
/// are all caught regardless of how the URL is cased.
const Set<String> _blockedSegments = {'admin'};

/// Returns `true` when [url] points at a path the mobile app is not allowed
/// to open (currently: anything containing an `/admin/` segment).
bool _isBlockedPath(String url) {
  final uri = Uri.tryParse(url);
  if (uri == null) return true; // un-parseable → block to be safe

  // Normalise: split, lower-case, drop empties.
  final segments = uri.pathSegments.map((s) => s.toLowerCase());
  return segments.any(_blockedSegments.contains);
}

/// The whole app: one WebView on the site. Login, the room picker, the 360°
/// walkthrough and the enrollment map all run as web pages, so they share a
/// single cookie jar and a student stays signed in from login to the tour.
class SiteScreen extends StatefulWidget {
  const SiteScreen({super.key});

  @override
  State<SiteScreen> createState() => _SiteScreenState();
}

class _SiteScreenState extends State<SiteScreen> {
  late final WebViewController _controller;
  final Uri _home = Uri.parse(siteUrl);

  int _progress = 0;
  bool _failed = false;

  /// JavaScript injected after every page load to strip any element that
  /// links to the admin panel. Belt-and-suspenders: even if a future page
  /// accidentally includes an admin link, the mobile user never sees it.
  static const String _stripAdminLinksJs = '''
    (function() {
      var links = document.querySelectorAll('a[href]');
      for (var i = 0; i < links.length; i++) {
        var href = links[i].getAttribute('href') || '';
        if (/\\badmin\\b/i.test(href)) {
          links[i].remove();
        }
      }
    })();
  ''';

  @override
  void initState() {
    super.initState();
    _controller = WebViewController()
      ..setJavaScriptMode(JavaScriptMode.unrestricted)
      ..setBackgroundColor(const Color(0xFFEEF2F8))
      ..setUserAgent('EduTrackMobile/1.0')
      ..setNavigationDelegate(NavigationDelegate(
        onProgress: (p) => setState(() => _progress = p),
        onPageStarted: (_) => setState(() => _failed = false),
        onPageFinished: (_) {
          // Remove any admin-panel links from the rendered page.
          _controller.runJavaScript(_stripAdminLinksJs);
        },
        onWebResourceError: (error) {
          // Only a failed page counts; a missing font or image does not.
          if (error.isForMainFrame ?? true) setState(() => _failed = true);
        },
        // Two rules:
        // 1. Stay on the EduTrack site (same host + port).
        // 2. Never open an admin path — it is web-only.
        onNavigationRequest: (request) {
          final uri = Uri.tryParse(request.url);
          final sameSite = uri != null && uri.host == _home.host && uri.port == _home.port;
          if (!sameSite) return NavigationDecision.prevent;
          if (_isBlockedPath(request.url)) return NavigationDecision.prevent;
          return NavigationDecision.navigate;
        },
      ))
      ..loadRequest(_home);
  }

  /// Android back: step back through the site's pages first, and only leave
  /// the app from the first page.
  Future<void> _onBack() async {
    if (await _controller.canGoBack()) {
      await _controller.goBack();
    } else {
      await SystemNavigator.pop();
    }
  }

  void _retry() {
    setState(() => _failed = false);
    _controller.reload();
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) _onBack();
      },
      child: Scaffold(
        body: SafeArea(
          child: Stack(
            children: [
              WebViewWidget(controller: _controller),
              // A thin blue bar along the top while a page loads.
              if (_progress < 100 && !_failed)
                LinearProgressIndicator(
                  value: _progress / 100,
                  minHeight: 3,
                  backgroundColor: Colors.transparent,
                ),
              if (_failed) _Offline(onRetry: _retry),
            ],
          ),
        ),
      ),
    );
  }
}

/// Shown when the site cannot be reached: no Wi-Fi, XAMPP stopped, or the
/// wrong address. Says what to check, and offers one action.
class _Offline extends StatelessWidget {
  const _Offline({required this.onRetry});

  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return ColoredBox(
      color: theme.scaffoldBackgroundColor,
      child: Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(Icons.wifi_off_rounded, size: 48, color: theme.colorScheme.primary),
              const SizedBox(height: 16),
              Text(
                'EduTrack could not load',
                style: theme.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700),
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 8),
              const Text(
                'Check that you are connected to the internet, then try again.',
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 24),
              FilledButton(
                onPressed: onRetry,
                style: FilledButton.styleFrom(
                  minimumSize: const Size(160, 48),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(6)),
                ),
                child: const Text('Try again'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
