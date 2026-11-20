import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../core/api/api_client.dart';
import '../models/invoice.dart';

final invoicesProvider =
    StateNotifierProvider.autoDispose<InvoicesNotifier, AsyncValue<PaginatedInvoices>>(
  (ref) => InvoicesNotifier(ref.watch(apiClientProvider)),
);

final invoiceDetailProvider = FutureProvider.autoDispose.family<Invoice, int>((ref, id) async {
  final client = ref.watch(apiClientProvider);
  try {
    final res = await client.get('/v1/invoices/$id');
    return Invoice.fromJson(res.data['data'] as Map<String, dynamic>);
  } on DioException catch (e) {
    throw ApiException.fromDioError(e);
  }
});

class InvoicesNotifier extends StateNotifier<AsyncValue<PaginatedInvoices>> {
  InvoicesNotifier(this._client) : super(const AsyncValue.loading()) {
    load();
  }

  final ApiClient _client;
  String? _status;

  Future<void> load({bool refresh = false}) async {
    if (refresh) state = const AsyncValue.loading();
    try {
      final res = await _client.get('/v1/invoices', queryParameters: {
        if (_status != null) 'status': _status,
        'per_page': 50,
      });
      state = AsyncValue.data(PaginatedInvoices.fromJson(res.data as Map<String, dynamic>));
    } on DioException catch (e) {
      state = AsyncValue.error(ApiException.fromDioError(e), StackTrace.current);
    }
  }

  Future<void> filterByStatus(String? status) async {
    _status = status;
    await load(refresh: true);
  }
}
