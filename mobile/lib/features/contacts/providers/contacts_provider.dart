import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../core/api/api_client.dart';
import '../models/contact.dart';

final contactsProvider = StateNotifierProvider.autoDispose<ContactsNotifier, AsyncValue<PaginatedContacts>>(
  (ref) => ContactsNotifier(ref.watch(apiClientProvider)),
);

final contactDetailProvider = FutureProvider.autoDispose.family<Contact, int>((ref, id) async {
  final client = ref.watch(apiClientProvider);
  try {
    final res = await client.get('/v1/contacts/$id');
    return Contact.fromJson(res.data['data'] as Map<String, dynamic>);
  } on DioException catch (e) {
    throw ApiException.fromDioError(e);
  }
});

class ContactsNotifier extends StateNotifier<AsyncValue<PaginatedContacts>> {
  ContactsNotifier(this._client) : super(const AsyncValue.loading()) {
    load();
  }

  final ApiClient _client;
  int _page = 1;
  String _query = '';

  Future<void> load({bool refresh = false}) async {
    if (refresh) {
      _page = 1;
      state = const AsyncValue.loading();
    }
    try {
      final res = await _client.get('/v1/contacts', queryParameters: {
        if (_query.isNotEmpty) 'q': _query,
        'page': _page,
        'per_page': 25,
      });
      final paginated = PaginatedContacts.fromJson(res.data as Map<String, dynamic>);
      state = AsyncValue.data(paginated);
    } on DioException catch (e) {
      state = AsyncValue.error(ApiException.fromDioError(e), StackTrace.current);
    }
  }

  Future<void> search(String query) async {
    _query = query;
    _page = 1;
    await load();
  }

  Future<Contact> create(Map<String, dynamic> payload) async {
    try {
      final res = await _client.post('/v1/contacts', data: payload);
      final contact = Contact.fromJson(res.data['data'] as Map<String, dynamic>);
      load(refresh: true);
      return contact;
    } on DioException catch (e) {
      throw ApiException.fromDioError(e);
    }
  }

  Future<Contact> update(int id, Map<String, dynamic> payload) async {
    try {
      final res = await _client.put('/v1/contacts/$id', data: payload);
      final contact = Contact.fromJson(res.data['data'] as Map<String, dynamic>);
      load(refresh: true);
      return contact;
    } on DioException catch (e) {
      throw ApiException.fromDioError(e);
    }
  }

  Future<void> delete(int id) async {
    try {
      await _client.delete('/v1/contacts/$id');
      load(refresh: true);
    } on DioException catch (e) {
      throw ApiException.fromDioError(e);
    }
  }
}
