import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../storage/auth_storage.dart';

const _defaultBaseUrl = 'https://zonseob.com';

final apiClientProvider = Provider<ApiClient>((ref) {
  final storage = ref.watch(authStorageProvider);
  return ApiClient(storage: storage);
});

class ApiClient {
  ApiClient({required AuthStorage storage}) : _storage = storage {
    _dio = Dio(BaseOptions(
      baseUrl: '$_defaultBaseUrl/api',
      connectTimeout: const Duration(seconds: 15),
      receiveTimeout: const Duration(seconds: 30),
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
      },
    ));

    _dio.interceptors.add(InterceptorsWrapper(
      onRequest: (options, handler) async {
        final token = await _storage.getToken();
        if (token != null) {
          options.headers['Authorization'] = 'Bearer $token';
        }
        handler.next(options);
      },
      onError: (error, handler) {
        handler.next(error);
      },
    ));
  }

  late final Dio _dio;
  final AuthStorage _storage;

  Dio get dio => _dio;

  Future<Response<T>> get<T>(String path, {Map<String, dynamic>? queryParameters}) =>
      _dio.get<T>(path, queryParameters: queryParameters);

  Future<Response<T>> post<T>(String path, {Object? data}) => _dio.post<T>(path, data: data);

  Future<Response<T>> put<T>(String path, {Object? data}) => _dio.put<T>(path, data: data);

  Future<Response<T>> patch<T>(String path, {Object? data}) => _dio.patch<T>(path, data: data);

  Future<Response<T>> delete<T>(String path, {Object? data}) => _dio.delete<T>(path, data: data);
}

class ApiException implements Exception {
  const ApiException(this.message, {this.statusCode});

  final String message;
  final int? statusCode;

  factory ApiException.fromDioError(DioException e) {
    if (e.response != null) {
      final data = e.response!.data;
      String message = 'Something went wrong.';
      if (data is Map<String, dynamic>) {
        if (data.containsKey('message')) {
          message = data['message'] as String? ?? message;
        }
        if (data.containsKey('errors')) {
          final errors = data['errors'] as Map<String, dynamic>?;
          if (errors != null && errors.isNotEmpty) {
            message = (errors.values.first as List).first as String;
          }
        }
      }
      return ApiException(message, statusCode: e.response!.statusCode);
    }
    if (e.type == DioExceptionType.connectionTimeout || e.type == DioExceptionType.receiveTimeout) {
      return const ApiException('Connection timed out. Check your internet.');
    }
    if (e.type == DioExceptionType.connectionError) {
      return const ApiException('Could not connect to the server.');
    }
    return ApiException(e.message ?? 'Unknown error.');
  }

  @override
  String toString() => message;
}
