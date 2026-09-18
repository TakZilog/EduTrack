import 'dart:convert';
import 'package:http/http.dart' as http;

class ApiService {
  // Use 10.0.2.2 for Android Emulator to connect to localhost XAMPP
  // Use localhost or 127.0.0.1 for Windows Desktop
  static const String baseUrl = 'http://127.0.0.1/EduTrack/api';
  
  static String? _sessionCookie;
  static String? _csrfToken;

  static Map<String, String> _getHeaders() {
    final headers = {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    };
    
    if (_sessionCookie != null) {
      headers['Cookie'] = _sessionCookie!;
    }
    
    if (_csrfToken != null) {
      headers['X-CSRF-Token'] = _csrfToken!;
    }
    
    return headers;
  }

  static void _updateCookie(http.Response response) {
    final rawCookie = response.headers['set-cookie'];
    if (rawCookie != null) {
      final index = rawCookie.indexOf(';');
      _sessionCookie = (index == -1) ? rawCookie : rawCookie.substring(0, index);
    }
  }

  static Future<void> getCsrfToken() async {
    if (_csrfToken != null) return;
    
    try {
      final response = await http.get(
        Uri.parse('$baseUrl/csrf-token.php'),
        headers: _getHeaders(),
      );
      
      _updateCookie(response);
      
      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        if (data['ok'] == true && data['token'] != null) {
          _csrfToken = data['token'];
        }
      }
    } catch (e) {
      print('Error getting CSRF token: $e');
    }
  }

  static Future<Map<String, dynamic>> post(String endpoint, Map<String, dynamic> body) async {
    await getCsrfToken();
    
    try {
      var response = await http.post(
        Uri.parse('$baseUrl/$endpoint'),
        headers: _getHeaders(),
        body: jsonEncode(body),
      );
      
      _updateCookie(response);
      
      var data = jsonDecode(response.body);
      
      // If CSRF token is invalid/expired, refresh it and retry once
      if (data['code'] == 'csrf') {
        _csrfToken = null;
        await getCsrfToken();
        
        response = await http.post(
          Uri.parse('$baseUrl/$endpoint'),
          headers: _getHeaders(),
          body: jsonEncode(body),
        );
        _updateCookie(response);
        data = jsonDecode(response.body);
      }
      
      return {'status': response.statusCode, 'data': data};
    } catch (e) {
      return {
        'status': 500,
        'data': {'ok': false, 'error': 'Network error. Could not reach server.'}
      };
    }
  }
  
  static Future<Map<String, dynamic>> get(String endpoint) async {
    try {
      final response = await http.get(
        Uri.parse('$baseUrl/$endpoint'),
        headers: _getHeaders(),
      );
      
      _updateCookie(response);
      return {'status': response.statusCode, 'data': jsonDecode(response.body)};
    } catch (e) {
      return {
        'status': 500,
        'data': {'ok': false, 'error': 'Network error. Could not reach server.'}
      };
    }
  }
}
