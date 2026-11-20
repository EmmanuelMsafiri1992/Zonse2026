import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../core/api/api_client.dart';
import '../models/appointment.dart';

final appointmentsProvider =
    StateNotifierProvider.autoDispose<AppointmentsNotifier, AsyncValue<PaginatedAppointments>>(
  (ref) => AppointmentsNotifier(ref.watch(apiClientProvider)),
);

class AppointmentsNotifier extends StateNotifier<AsyncValue<PaginatedAppointments>> {
  AppointmentsNotifier(this._client) : super(const AsyncValue.loading()) {
    load();
  }

  final ApiClient _client;
  String? _status;
  String? _from;
  String? _to;

  Future<void> load({bool refresh = false}) async {
    if (refresh) state = const AsyncValue.loading();
    try {
      final res = await _client.get('/v1/appointments', queryParameters: {
        if (_status != null) 'status': _status,
        if (_from != null) 'from': _from,
        if (_to != null) 'to': _to,
        'per_page': 50,
      });
      state = AsyncValue.data(PaginatedAppointments.fromJson(res.data as Map<String, dynamic>));
    } on DioException catch (e) {
      state = AsyncValue.error(ApiException.fromDioError(e), StackTrace.current);
    }
  }

  Future<void> filterByStatus(String? status) async {
    _status = status;
    await load(refresh: true);
  }

  Future<void> filterByDateRange(String? from, String? to) async {
    _from = from;
    _to = to;
    await load(refresh: true);
  }
}
