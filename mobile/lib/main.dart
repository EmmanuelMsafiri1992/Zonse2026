import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'core/theme/app_theme.dart';
import 'features/auth/providers/auth_provider.dart';
import 'features/auth/screens/login_screen.dart';
import 'features/dashboard/screens/dashboard_screen.dart';
import 'features/contacts/screens/contacts_screen.dart';
import 'features/tasks/screens/tasks_screen.dart';
import 'features/appointments/screens/appointments_screen.dart';
import 'features/invoices/screens/invoices_screen.dart';
import 'features/helpdesk/screens/tickets_screen.dart';
import 'features/profile/screens/profile_screen.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  SystemChrome.setSystemUIOverlayStyle(const SystemUiOverlayStyle(
    statusBarColor: Colors.transparent,
    statusBarIconBrightness: Brightness.dark,
  ));
  runApp(const ProviderScope(child: ZonseobApp()));
}

class ZonseobApp extends ConsumerWidget {
  const ZonseobApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return MaterialApp(
      title: 'Zonseob',
      theme: AppTheme.light,
      debugShowCheckedModeBanner: false,
      home: const _AppRoot(),
    );
  }
}

class _AppRoot extends ConsumerWidget {
  const _AppRoot();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final authState = ref.watch(authProvider);

    return authState.when(
      loading: () => const _SplashScreen(),
      error: (_, __) => const LoginScreen(),
      data: (session) => session == null ? const LoginScreen() : const _MainShell(),
    );
  }
}

class _SplashScreen extends StatelessWidget {
  const _SplashScreen();

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.white,
      body: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Image.asset('assets/images/logo-mark.png', width: 96, height: 96),
            const SizedBox(height: 20),
            Image.asset('assets/images/logo.png', height: 36, fit: BoxFit.contain),
            const SizedBox(height: 40),
            const CircularProgressIndicator(color: AppColors.primary, strokeWidth: 2),
          ],
        ),
      ),
    );
  }
}

class _MainShell extends ConsumerStatefulWidget {
  const _MainShell();

  @override
  ConsumerState<_MainShell> createState() => _MainShellState();
}

class _MainShellState extends ConsumerState<_MainShell> {
  int _currentIndex = 0;

  static const _tabs = [
    _Tab(icon: Icons.dashboard_outlined, activeIcon: Icons.dashboard_rounded, label: 'Home'),
    _Tab(icon: Icons.people_outline, activeIcon: Icons.people_rounded, label: 'Contacts'),
    _Tab(icon: Icons.task_alt, activeIcon: Icons.task_alt, label: 'Tasks'),
    _Tab(icon: Icons.event_outlined, activeIcon: Icons.event_rounded, label: 'Calendar'),
    _Tab(icon: Icons.receipt_long_outlined, activeIcon: Icons.receipt_long_rounded, label: 'Invoices'),
    _Tab(icon: Icons.support_agent_outlined, activeIcon: Icons.support_agent_rounded, label: 'Help'),
    _Tab(icon: Icons.person_outline, activeIcon: Icons.person_rounded, label: 'Profile'),
  ];

  static const _pages = [
    DashboardScreen(),
    ContactsScreen(),
    TasksScreen(),
    AppointmentsScreen(),
    InvoicesScreen(),
    TicketsScreen(),
    ProfileScreen(),
  ];

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: IndexedStack(index: _currentIndex, children: _pages),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _currentIndex,
        onDestinationSelected: (i) => setState(() => _currentIndex = i),
        labelBehavior: NavigationDestinationLabelBehavior.onlyShowSelected,
        destinations: _tabs.map((t) => NavigationDestination(
          icon: Icon(t.icon),
          selectedIcon: Icon(t.activeIcon),
          label: t.label,
        )).toList(),
      ),
    );
  }
}

class _Tab {
  const _Tab({required this.icon, required this.activeIcon, required this.label});

  final IconData icon;
  final IconData activeIcon;
  final String label;
}
