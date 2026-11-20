import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../core/api/api_client.dart';
import '../models/ticket.dart';

final ticketsProvider =
    StateNotifierProvider.autoDispose<TicketsNotifier, AsyncValue<PaginatedTickets>>(
  (ref) => TicketsNotifier(ref.watch(apiClientProvider)),
);

final ticketDetailProvider = FutureProvider.autoDispose.family<Ticket, int>((ref, id) async {
  final client = ref.watch(apiClientProvider);
  try {
    final res = await client.get('/v1/tickets/$id');
    return Ticket.fromJson(res.data['data'] as Map<String, dynamic>);
  } on DioException catch (e) {
    throw ApiException.fromDioError(e);
  }
});

class TicketsNotifier extends StateNotifier<AsyncValue<PaginatedTickets>> {
  TicketsNotifier(this._client) : super(const AsyncValue.loading()) {
    load();
  }

  final ApiClient _client;
  String? _status;

  Future<void> load({bool refresh = false}) async {
    if (refresh) state = const AsyncValue.loading();
    try {
      final res = await _client.get('/v1/tickets', queryParameters: {
        if (_status != null) 'status': _status,
        'per_page': 50,
      });
      state = AsyncValue.data(PaginatedTickets.fromJson(res.data as Map<String, dynamic>));
    } on DioException catch (e) {
      state = AsyncValue.error(ApiException.fromDioError(e), StackTrace.current);
    }
  }

  Future<void> filterByStatus(String? status) async {
    _status = status;
    await load(refresh: true);
  }

  Future<Ticket> create(Map<String, dynamic> payload) async {
    try {
      final res = await _client.post('/v1/tickets', data: payload);
      final ticket = Ticket.fromJson(res.data['data'] as Map<String, dynamic>);
      load(refresh: true);
      return ticket;
    } on DioException catch (e) {
      throw ApiException.fromDioError(e);
    }
  }

  Future<Ticket> update(int id, Map<String, dynamic> payload) async {
    try {
      final res = await _client.put('/v1/tickets/$id', data: payload);
      final ticket = Ticket.fromJson(res.data['data'] as Map<String, dynamic>);
      load(refresh: true);
      return ticket;
    } on DioException catch (e) {
      throw ApiException.fromDioError(e);
    }
  }
}
