import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../core/api/api_client.dart';
import '../models/task.dart';

final tasksProvider = StateNotifierProvider.autoDispose<TasksNotifier, AsyncValue<PaginatedTasks>>(
  (ref) => TasksNotifier(ref.watch(apiClientProvider)),
);

final taskDetailProvider = FutureProvider.autoDispose.family<Task, int>((ref, id) async {
  final client = ref.watch(apiClientProvider);
  try {
    final res = await client.get('/v1/tasks/$id');
    return Task.fromJson(res.data['data'] as Map<String, dynamic>);
  } on DioException catch (e) {
    throw ApiException.fromDioError(e);
  }
});

class TasksNotifier extends StateNotifier<AsyncValue<PaginatedTasks>> {
  TasksNotifier(this._client) : super(const AsyncValue.loading()) {
    load();
  }

  final ApiClient _client;
  String _statusFilter = '';

  Future<void> load({bool refresh = false}) async {
    if (refresh) state = const AsyncValue.loading();
    try {
      final res = await _client.get('/v1/tasks', queryParameters: {
        if (_statusFilter.isNotEmpty) 'status': _statusFilter,
        'per_page': 50,
      });
      state = AsyncValue.data(PaginatedTasks.fromJson(res.data as Map<String, dynamic>));
    } on DioException catch (e) {
      state = AsyncValue.error(ApiException.fromDioError(e), StackTrace.current);
    }
  }

  Future<void> filterByStatus(String status) async {
    _statusFilter = status;
    await load(refresh: true);
  }

  Future<Task> create(Map<String, dynamic> payload) async {
    try {
      final res = await _client.post('/v1/tasks', data: payload);
      final task = Task.fromJson(res.data['data'] as Map<String, dynamic>);
      load(refresh: true);
      return task;
    } on DioException catch (e) {
      throw ApiException.fromDioError(e);
    }
  }

  Future<Task> update(int id, Map<String, dynamic> payload) async {
    try {
      final res = await _client.put('/v1/tasks/$id', data: payload);
      final task = Task.fromJson(res.data['data'] as Map<String, dynamic>);
      load(refresh: true);
      return task;
    } on DioException catch (e) {
      throw ApiException.fromDioError(e);
    }
  }

  Future<Task> updateStatus(int id, String status) => update(id, {'status': status});

  Future<void> delete(int id) async {
    try {
      await _client.delete('/v1/tasks/$id');
      load(refresh: true);
    } on DioException catch (e) {
      throw ApiException.fromDioError(e);
    }
  }
}
