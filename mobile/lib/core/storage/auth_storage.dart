import 'dart:convert';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

final authStorageProvider = Provider<AuthStorage>((_) => AuthStorage());

class AuthStorage {
  static const _tokenKey = 'api_token';
  static const _userKey = 'current_user';
  static const _workspaceKey = 'current_workspace';

  final _storage = const FlutterSecureStorage(
    aOptions: AndroidOptions(encryptedSharedPreferences: true),
  );

  Future<void> saveToken(String token) => _storage.write(key: _tokenKey, value: token);

  Future<String?> getToken() => _storage.read(key: _tokenKey);

  Future<void> saveUser(Map<String, dynamic> user) =>
      _storage.write(key: _userKey, value: jsonEncode(user));

  Future<Map<String, dynamic>?> getUser() async {
    final raw = await _storage.read(key: _userKey);
    if (raw == null) return null;
    return jsonDecode(raw) as Map<String, dynamic>;
  }

  Future<void> saveWorkspace(Map<String, dynamic> workspace) =>
      _storage.write(key: _workspaceKey, value: jsonEncode(workspace));

  Future<Map<String, dynamic>?> getWorkspace() async {
    final raw = await _storage.read(key: _workspaceKey);
    if (raw == null) return null;
    return jsonDecode(raw) as Map<String, dynamic>;
  }

  Future<bool> isLoggedIn() async => (await getToken()) != null;

  Future<void> clear() => _storage.deleteAll();
}
