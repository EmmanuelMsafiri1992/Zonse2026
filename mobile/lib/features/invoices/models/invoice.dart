class InvoiceLine {
  const InvoiceLine({
    required this.description,
    required this.quantity,
    required this.unitPrice,
    required this.lineTotal,
    this.unit,
    this.taxRate,
  });

  final String description;
  final double quantity;
  final double unitPrice;
  final double lineTotal;
  final String? unit;
  final double? taxRate;

  factory InvoiceLine.fromJson(Map<String, dynamic> json) => InvoiceLine(
        description: (json['description'] as String?) ?? '',
        quantity: (json['quantity'] as num?)?.toDouble() ?? 1,
        unitPrice: (json['unit_price'] as num?)?.toDouble() ?? 0,
        lineTotal: (json['line_total'] as num?)?.toDouble() ?? 0,
        unit: json['unit'] as String?,
        taxRate: (json['tax_rate'] as num?)?.toDouble(),
      );
}

class Invoice {
  const Invoice({
    required this.id,
    required this.number,
    required this.status,
    required this.total,
    this.contactId,
    this.issueDate,
    this.dueDate,
    this.currencyCode,
    this.reference,
    this.subtotal,
    this.discountAmount,
    this.taxTotal,
    this.amountPaid,
    this.balance,
    this.lines = const [],
    this.createdAt,
  });

  final int id;
  final String number;
  final String status;
  final double total;
  final int? contactId;
  final String? issueDate;
  final String? dueDate;
  final String? currencyCode;
  final String? reference;
  final double? subtotal;
  final double? discountAmount;
  final double? taxTotal;
  final double? amountPaid;
  final double? balance;
  final List<InvoiceLine> lines;
  final String? createdAt;

  String get statusLabel => switch (status) {
        'draft' => 'Draft',
        'sent' => 'Sent',
        'partial' => 'Partial',
        'paid' => 'Paid',
        'overdue' => 'Overdue',
        'void' => 'Void',
        _ => status,
      };

  factory Invoice.fromJson(Map<String, dynamic> json) => Invoice(
        id: json['id'] as int,
        number: (json['number'] as String?) ?? '',
        status: (json['status'] as String?) ?? 'draft',
        total: (json['total'] as num?)?.toDouble() ?? 0,
        contactId: json['contact_id'] as int?,
        issueDate: json['issue_date'] as String?,
        dueDate: json['due_date'] as String?,
        currencyCode: json['currency_code'] as String?,
        reference: json['reference'] as String?,
        subtotal: (json['subtotal'] as num?)?.toDouble(),
        discountAmount: (json['discount_amount'] as num?)?.toDouble(),
        taxTotal: (json['tax_total'] as num?)?.toDouble(),
        amountPaid: (json['amount_paid'] as num?)?.toDouble(),
        balance: (json['balance'] as num?)?.toDouble(),
        lines: json['lines'] != null
            ? (json['lines'] as List).map((e) => InvoiceLine.fromJson(e as Map<String, dynamic>)).toList()
            : [],
        createdAt: json['created_at'] as String?,
      );
}

class PaginatedInvoices {
  const PaginatedInvoices({required this.data, required this.total, required this.lastPage, required this.currentPage});

  final List<Invoice> data;
  final int total;
  final int lastPage;
  final int currentPage;

  factory PaginatedInvoices.fromJson(Map<String, dynamic> json) {
    final meta = json['meta'] as Map<String, dynamic>? ?? {};
    return PaginatedInvoices(
      data: (json['data'] as List).map((e) => Invoice.fromJson(e as Map<String, dynamic>)).toList(),
      total: (meta['total'] as int?) ?? 0,
      lastPage: (meta['last_page'] as int?) ?? 1,
      currentPage: (meta['current_page'] as int?) ?? 1,
    );
  }
}
