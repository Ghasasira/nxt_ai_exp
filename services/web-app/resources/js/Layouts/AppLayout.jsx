import { Link, usePage, router } from '@inertiajs/react';
import {
    Activity,
    BookOpen,
    Database,
    FileText,
    FlaskConical,
    FolderLock,
    History,
    KeyRound,
    LayoutDashboard,
    LogOut,
    Menu,
    MessageSquare,
    Search,
    Settings,
    ShieldCheck,
    User as UserIcon,
    Users,
    X,
} from 'lucide-react';
import { useState } from 'react';

export default function AppLayout({ title, children }) {
    const { auth, flash } = usePage().props;
    const user = auth?.user;
    const [mobileOpen, setMobileOpen] = useState(false);

    const isManager = user && ['admin', 'manager'].includes(user.role);
    const isAdmin = user && user.role === 'admin';

    const navGroups = [
        {
            label: 'Workspace',
            items: [{ name: 'Chat', href: '/chat', icon: MessageSquare }],
        },
        ...(isManager
            ? [
                  {
                      label: 'Knowledge Ops',
                      items: [
                          { name: 'Dashboard', href: '/dashboard', icon: LayoutDashboard },
                          { name: 'Documents', href: '/documents', icon: FileText },
                          { name: 'Collections', href: '/collections', icon: FolderLock },
                          { name: 'Ingestion Queue', href: '/ingestion', icon: Database },
                          { name: 'Retrieval Playground', href: '/playground', icon: Search },
                          { name: 'Query Logs', href: '/query-logs', icon: BookOpen },
                          { name: 'Evaluation', href: '/evaluation', icon: FlaskConical },
                      ],
                  },
              ]
            : []),
        ...(isAdmin
            ? [
                  {
                      label: 'Administration',
                      items: [
                          { name: 'Settings', href: '/admin/settings', icon: Settings },
                          { name: 'Users & Roles', href: '/admin/users', icon: Users },
                          { name: 'API Keys', href: '/admin/api-keys', icon: KeyRound },
                          { name: 'System Health', href: '/admin/health', icon: Activity },
                          { name: 'Audit Log', href: '/admin/audit-log', icon: History },
                      ],
                  },
              ]
            : []),
    ];

    const currentPath = typeof window !== 'undefined' ? window.location.pathname : '';

    const SidebarContent = () => (
        <div className="flex h-full flex-col justify-between bg-slate-900 border-r border-slate-800 p-4">
            <div className="space-y-6">
                <div className="flex items-center gap-2.5 px-2">
                    <ShieldCheck className="h-6 w-6 text-indigo-400" />
                    <div>
                        <div className="text-sm font-semibold tracking-tight text-white">RAG Knowledge Agent</div>
                        <div className="text-[11px] text-slate-400">On-Prem Hybrid Retrieval</div>
                    </div>
                </div>

                <nav className="space-y-5">
                    {navGroups.map((group) => (
                        <div key={group.label}>
                            <div className="px-2 pb-1.5 text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                                {group.label}
                            </div>
                            <div className="space-y-0.5">
                                {group.items.map((item) => {
                                    const Icon = item.icon;
                                    const active = currentPath === item.href || currentPath.startsWith(`${item.href}/`);
                                    return (
                                        <Link
                                            key={item.href}
                                            href={item.href}
                                            onClick={() => setMobileOpen(false)}
                                            className={`flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm transition ${
                                                active
                                                    ? 'bg-indigo-600/20 text-indigo-300 font-medium'
                                                    : 'text-slate-300 hover:bg-slate-800 hover:text-white'
                                            }`}
                                        >
                                            <Icon className="h-4 w-4 shrink-0" />
                                            <span>{item.name}</span>
                                        </Link>
                                    );
                                })}
                            </div>
                        </div>
                    ))}
                </nav>
            </div>

            {user && (
                <div className="border-t border-slate-800 pt-3 space-y-2">
                    <Link
                        href="/profile"
                        className="flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm text-slate-300 hover:bg-slate-800"
                    >
                        <UserIcon className="h-4 w-4 text-slate-400" />
                        <div className="min-w-0 flex-1">
                            <div className="truncate font-medium text-white">{user.name}</div>
                            <div className="truncate text-xs text-slate-400 capitalize">{user.role}</div>
                        </div>
                    </Link>
                    <button
                        type="button"
                        onClick={() => router.post('/logout')}
                        className="flex w-full items-center gap-2 rounded-lg px-2.5 py-2 text-sm text-slate-400 hover:bg-slate-800 hover:text-rose-300"
                    >
                        <LogOut className="h-4 w-4" />
                        <span>Sign out</span>
                    </button>
                    <Link href="/privacy" className="block px-2.5 pt-1 text-[11px] text-slate-500 hover:text-slate-300">
                        Data Handling &amp; Privacy
                    </Link>
                </div>
            )}
        </div>
    );

    return (
        <div className="flex min-h-screen bg-slate-950 text-slate-100">
            <aside className="hidden w-64 shrink-0 lg:block">
                <div className="sticky top-0 h-screen">
                    <SidebarContent />
                </div>
            </aside>

            {mobileOpen && (
                <div className="fixed inset-0 z-50 flex lg:hidden">
                    <div className="w-64">
                        <SidebarContent />
                    </div>
                    <div className="flex-1 bg-black/60" onClick={() => setMobileOpen(false)} />
                </div>
            )}

            <div className="flex min-w-0 flex-1 flex-col">
                <header className="sticky top-0 z-30 flex items-center justify-between border-b border-slate-800 bg-slate-950/90 px-4 py-3 backdrop-blur lg:px-6">
                    <div className="flex items-center gap-3">
                        <button
                            type="button"
                            onClick={() => setMobileOpen(!mobileOpen)}
                            className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-800 lg:hidden"
                            aria-label="Toggle navigation"
                        >
                            {mobileOpen ? <X className="h-5 w-5" /> : <Menu className="h-5 w-5" />}
                        </button>
                        <h1 className="text-lg font-semibold text-white">{title}</h1>
                    </div>
                </header>

                <main className="flex-1 p-4 lg:p-6 space-y-4">
                    {flash?.success && (
                        <div className="rounded-lg border border-emerald-500/40 bg-emerald-950/50 px-4 py-3 text-sm text-emerald-200">
                            {flash.success}
                        </div>
                    )}
                    {flash?.error && (
                        <div className="rounded-lg border border-rose-500/40 bg-rose-950/50 px-4 py-3 text-sm text-rose-200">
                            {flash.error}
                        </div>
                    )}
                    {flash?.plaintext_token && (
                        <div className="rounded-lg border border-amber-500/50 bg-amber-950/50 px-4 py-3 text-sm text-amber-100">
                            <div className="font-semibold">Copy your API key now (shown once):</div>
                            <code className="mt-1 block break-all rounded bg-slate-900 px-2 py-1 font-mono text-xs text-amber-300">
                                {flash.plaintext_token}
                            </code>
                        </div>
                    )}
                    {children}
                </main>
            </div>
        </div>
    );
}
