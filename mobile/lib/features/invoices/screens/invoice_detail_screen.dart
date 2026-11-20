import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../core/theme/app_theme.dart';
import '../../../shared/widgets/loading_widget.dart';
import '../../../shared/widgets/status_badge.dart';
import '../models/invoice.dart';
import '../providers/invoices_provider.dart';

class InvoiceDetailScreen extends ConsumerWidget {
  const InvoiceDetailScreen({super.key, required this.invoiceId});

  final int invoiceId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final invoiceAsync = ref.watch(invoiceDetailProvider(invoiceId));

    return Scaffold(
      appBar: AppBar(title: const Text('Invoice')),
      body: invoiceAsync.when(
        loading: () => const LoadingWidget(),
        error: (e, _) => AppErrorWidget(message: e.toString()),
        data: (invoice) => _InvoiceDetail(invoice: invoice),
      ),
    );
  }
}

class _InvoiceDetail extends StatelessWidget {
  const _InvoiceDetail({required this.invoice});

  final Invoice invoice;

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _buildHeader(),
          const SizedBox(height: 12),
          _buildAmounts(),
          if (invoice.lines.isNotEmpty) ...[
            const SizedBox(height: 12),
            _buildLines(),
          ],
        ],
      ),
    );
  }

  Widget _buildHeader() => Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text(invoice.number, style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
                  StatusBadge(label: invoice.statusLabel, color: StatusBadge.colorForStatus(invoice.status)),
                ],
              ),
              const SizedBox(height: 12),
              if (invoice.issueDate != null)
                _row('Issue date', invoice.issueDate!),
              if (invoice.dueDate != null)
                _row('Due date', invoice.dueDate!),
              if (invoice.reference != null && invoice.reference!.isNotEmpty)
                _row('Reference', invoice.reference!),
              if (invoice.currencyCode != null)
                _row('Currency', invoice.currencyCode!),
            ],
          ),
        ),
      );

  Widget _buildAmounts() => Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            children: [
              if (invoice.subtotal != null) _amountRow('Subtotal', invoice.subtotal!),
              if (invoice.discountAmount != null && invoice.discountAmount! > 0)
                _amountRow('Discount', -invoice.discountAmount!, color: AppColors.success),
              if (invoice.taxTotal != null && invoice.taxTotal! > 0)
                _amountRow('Tax', invoice.taxTotal!),
              const Divider(),
              _amountRow('Total', invoice.total, bold: true),
              if (invoice.amountPaid != null && invoice.amountPaid! > 0)
                _amountRow('Amount paid', invoice.amountPaid!, color: AppColors.success),
              if (invoice.balance != null && invoice.balance! > 0)
                _amountRow('Balance due', invoice.balance!, bold: true, color: AppColors.error),
            ],
          ),
        ),
      );

  Widget _buildLines() => Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text('Line items', style: TextStyle(fontWeight: FontWeight.w600, fontSize: 15)),
              const SizedBox(height: 12),
              ...invoice.lines.map((line) => Padding(
                    padding: const EdgeInsets.only(bottom: 10),
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(line.description, style: const TextStyle(fontWeight: FontWeight.w500)),
                              Text(
                                '${line.quantity} × ${line.unitPrice.toStringAsFixed(2)}',
                                style: const TextStyle(fontSize: 12, color: AppColors.textSecondary),
                              ),
                            ],
                          ),
                        ),
                        Text(
                          line.lineTotal.toStringAsFixed(2),
                          style: const TextStyle(fontWeight: FontWeight.w600),
                        ),
                      ],
                    ),
                  )),
            ],
          ),
        ),
      );

  Widget _row(String label, String value) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            SizedBox(
              width: 100,
              child: Text(label,
                  style: const TextStyle(color: AppColors.textSecondary, fontSize: 13)),
            ),
            Expanded(
              child: Text(value,
                  style: const TextStyle(fontWeight: FontWeight.w500, fontSize: 13)),
            ),
          ],
        ),
      );

  Widget _amountRow(String label, double amount, {bool bold = false, Color? color}) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 5),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Text(label,
                style: TextStyle(
                  fontWeight: bold ? FontWeight.w700 : FontWeight.normal,
                  color: color ?? AppColors.textPrimary,
                  fontSize: bold ? 15 : 14,
                )),
            Text(
              '${invoice.currencyCode ?? ''} ${amount.abs().toStringAsFixed(2)}',
              style: TextStyle(
                fontWeight: bold ? FontWeight.w700 : FontWeight.w600,
                color: color ?? AppColors.textPrimary,
                fontSize: bold ? 15 : 14,
              ),
            ),
          ],
        ),
      );
}
