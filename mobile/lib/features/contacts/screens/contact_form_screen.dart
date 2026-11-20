import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../core/theme/app_theme.dart';
import '../models/contact.dart';
import '../providers/contacts_provider.dart';

class ContactFormScreen extends ConsumerStatefulWidget {
  const ContactFormScreen({super.key, this.contact});

  final Contact? contact;

  @override
  ConsumerState<ContactFormScreen> createState() => _ContactFormScreenState();
}

class _ContactFormScreenState extends ConsumerState<ContactFormScreen> {
  final _formKey = GlobalKey<FormState>();
  late final TextEditingController _nameCtrl;
  late final TextEditingController _emailCtrl;
  late final TextEditingController _phoneCtrl;
  late final TextEditingController _mobileCtrl;
  late final TextEditingController _notesCtrl;
  late String _type;
  late String _kind;
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    final c = widget.contact;
    _nameCtrl = TextEditingController(text: c?.name ?? '');
    _emailCtrl = TextEditingController(text: c?.email ?? '');
    _phoneCtrl = TextEditingController(text: c?.phone ?? '');
    _mobileCtrl = TextEditingController(text: c?.mobile ?? '');
    _notesCtrl = TextEditingController(text: c?.notes ?? '');
    _type = c?.type ?? 'customer';
    _kind = c?.kind ?? 'person';
  }

  @override
  void dispose() {
    _nameCtrl.dispose();
    _emailCtrl.dispose();
    _phoneCtrl.dispose();
    _mobileCtrl.dispose();
    _notesCtrl.dispose();
    super.dispose();
  }

  Map<String, dynamic> _buildPayload() => {
        'name': _nameCtrl.text.trim(),
        'type': _type,
        'kind': _kind,
        if (_emailCtrl.text.trim().isNotEmpty) 'email': _emailCtrl.text.trim(),
        if (_phoneCtrl.text.trim().isNotEmpty) 'phone': _phoneCtrl.text.trim(),
        if (_mobileCtrl.text.trim().isNotEmpty) 'mobile': _mobileCtrl.text.trim(),
        if (_notesCtrl.text.trim().isNotEmpty) 'notes': _notesCtrl.text.trim(),
      };

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _saving = true);
    try {
      final notifier = ref.read(contactsProvider.notifier);
      if (widget.contact != null) {
        await notifier.update(widget.contact!.id, _buildPayload());
      } else {
        await notifier.create(_buildPayload());
      }
      if (mounted) Navigator.pop(context, true);
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(e.toString()), backgroundColor: AppColors.error),
        );
      }
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final isEdit = widget.contact != null;
    return Scaffold(
      appBar: AppBar(
        title: Text(isEdit ? 'Edit Contact' : 'New Contact'),
        actions: [
          Padding(
            padding: const EdgeInsets.only(right: 8),
            child: FilledButton(
              onPressed: _saving ? null : _save,
              child: _saving ? const SizedBox(height: 18, width: 18, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2)) : const Text('Save'),
            ),
          ),
        ],
      ),
      body: Form(
        key: _formKey,
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _section('Type'),
              Row(
                children: [
                  for (final e in {'person': 'Person', 'company': 'Company'}.entries)
                    Expanded(
                      child: Padding(
                        padding: const EdgeInsets.only(right: 8),
                        child: ChoiceChip(
                          label: Text(e.value),
                          selected: _kind == e.key,
                          onSelected: (_) => setState(() => _kind = e.key),
                        ),
                      ),
                    ),
                ],
              ),
              const SizedBox(height: 8),
              Wrap(
                spacing: 8,
                children: [
                  for (final e in {'customer': 'Customer', 'supplier': 'Supplier', 'lead': 'Lead', 'other': 'Other'}.entries)
                    ChoiceChip(
                      label: Text(e.value),
                      selected: _type == e.key,
                      onSelected: (_) => setState(() => _type = e.key),
                    ),
                ],
              ),
              const SizedBox(height: 16),
              _section('Name'),
              TextFormField(
                controller: _nameCtrl,
                decoration: const InputDecoration(labelText: 'Full name *'),
                validator: (v) => (v == null || v.trim().isEmpty) ? 'Name is required' : null,
              ),
              const SizedBox(height: 12),
              TextFormField(
                controller: _emailCtrl,
                keyboardType: TextInputType.emailAddress,
                decoration: const InputDecoration(labelText: 'Email'),
              ),
              const SizedBox(height: 12),
              TextFormField(
                controller: _phoneCtrl,
                keyboardType: TextInputType.phone,
                decoration: const InputDecoration(labelText: 'Phone'),
              ),
              const SizedBox(height: 12),
              TextFormField(
                controller: _mobileCtrl,
                keyboardType: TextInputType.phone,
                decoration: const InputDecoration(labelText: 'Mobile'),
              ),
              const SizedBox(height: 16),
              _section('Notes'),
              TextFormField(
                controller: _notesCtrl,
                maxLines: 4,
                decoration: const InputDecoration(labelText: 'Notes', alignLabelWithHint: true),
              ),
              const SizedBox(height: 40),
            ],
          ),
        ),
      ),
    );
  }

  Widget _section(String label) => Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Text(label, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13, color: AppColors.textSecondary)),
      );
}
