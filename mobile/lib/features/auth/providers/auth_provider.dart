import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../core/api/api_client.dart';
import '../../../core/storage/auth_storage.dart';
import '../models/auth_state.dart';

final authProvider = StateNotifierProvider<AuthNotifier, AsyncValue<AuthSession?>>(
  (ref) => AuthNotifier(
    client: ref.watch(apiClientProvider),
    storage: ref.watch(authStorageProvider),
  ),
);

class AuthSession {
  const AuthSession({required this.user, required this.workspace, required this.token});

  final AuthUser user;
  final AuthWorkspace workspace;
  final String token;
}

class AuthNotifier extends StateNotifier<AsyncValue<AuthSession?>> {
  AuthNotifier({required ApiClient client, required AuthStorage storage})
      : _client = client,
        _storage = storage,
        super(const AsyncValue.loading()) {
    _init();
  }

  final ApiClient _client;
  final AuthStorage _storage;

  Future<void> _init() async {
    final token = await _storage.getToken();
    if (token == null) {
      state = const AsyncValue.data(null);
      return;
    }
    final user = await _storage.getUser();
    final ws = await _storage.getWorkspace();
    if (user != null && ws != null) {
      state = AsyncValue.data(AuthSession(
        user: AuthUser.fromJson(user),
        workspace: AuthWorkspace.fromJson(ws),
        token: token,
      ));
    } else {
      await _storage.clear();
      state = const AsyncValue.data(null);
    }
  }

  /// Returns list of workspaces for the given credentials.
  Future<List<AuthWorkspace>> fetchWorkspaces(String email, String password) async {
    try {
      final res = await _client.post('/mobile/workspaces', data: {
        'email': email,
        'password': password,
      });
      final list = (res.data['data']['workspaces'] as List).cast<Map<String, dynamic>>();
      return list.map(AuthWorkspace.fromJson).toList();
    } on DioException catch (e) {
      throw ApiException.fromDioError(e);
    }
  }

  /// Authenticates fully and stores the session.
  Future<void> login({
    required String email,
    required String password,
    required int workspaceId,
    String? deviceName,
  }) async {
    state = const AsyncValue.loading();
    try {
      final res = await _client.post('/mobile/token', data: {
        'email': email,
        'password': password,
        'workspace_id': workspaceId,
        if (deviceName != null) 'device_name': deviceName,
      });
      final data = res.data['data'] as Map<String, dynamic>;
      final user = AuthUser.fromJson(data['user'] as Map<String, dynamic>);
      final workspace = AuthWorkspace.fromJson(data['workspace'] as Map<String, dynamic>);
      final token = data['token'] as String;

      await _storage.saveToken(token);
      await _storage.saveUser(user.toJson());
      await _storage.saveWorkspace(workspace.toJson());

      state = AsyncValue.data(AuthSession(user: user, workspace: workspace, token: token));
    } on DioException catch (e) {
      state = const AsyncValue.data(null);
      throw ApiException.fromDioError(e);
    } catch (e) {
      state = const AsyncValue.data(null);
      rethrow;
    }
  }

  Future<void> logout() async {
    try {
      await _client.delete('/mobile/token');
    } catch (_) {}
    await _storage.clear();
    state = const AsyncValue.data(null);
  }
}
