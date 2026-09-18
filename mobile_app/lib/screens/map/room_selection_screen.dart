import 'package:flutter/material.dart';
import 'package:webview_flutter/webview_flutter.dart';

class RoomSelectionScreen extends StatefulWidget {
  final bool isGuest;

  const RoomSelectionScreen({super.key, this.isGuest = false});

  @override
  State<RoomSelectionScreen> createState() => _RoomSelectionScreenState();
}

class _RoomSelectionScreenState extends State<RoomSelectionScreen> {
  late final WebViewController _controller;
  bool _isLoading = true;

  @override
  void initState() {
    super.initState();
    // In Android Emulator, localhost for the host machine is 10.0.2.2.
    // For Windows Desktop, it is 127.0.0.1.
    // Assuming 127.0.0.1 for desktop deployment.
    final String url = 'http://127.0.0.1/EduTrack/map/select-room.html';

    _controller = WebViewController()
      ..setJavaScriptMode(JavaScriptMode.unrestricted)
      ..setNavigationDelegate(
        NavigationDelegate(
          onPageStarted: (String url) {
            setState(() => _isLoading = true);
          },
          onPageFinished: (String url) {
            setState(() => _isLoading = false);
            // We could inject JS here to hide web-specific UI like the back button 
            // if we wanted to control it purely through Flutter.
            _controller.runJavaScript("document.querySelector('.back-btn').style.display = 'none';");
          },
          onWebResourceError: (WebResourceError error) {
            setState(() => _isLoading = false);
            print('WebView error: ${error.description}');
          },
        ),
      )
      ..loadRequest(Uri.parse(url));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFF15181D),
      appBar: AppBar(
        title: Text(widget.isGuest ? 'Guest Map' : 'Student Map'),
        backgroundColor: const Color(0xFF1B1E25),
        elevation: 0,
      ),
      body: Stack(
        children: [
          WebViewWidget(controller: _controller),
          if (_isLoading)
            const Center(
              child: CircularProgressIndicator(color: Color(0xFFE8552A)),
            ),
        ],
      ),
    );
  }
}
