import { Head, router, useForm } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';

export default function AdminUsers({ users }) {
    const form = useForm({
        name: '',
        email: '',
        password: '',
        role: 'reader',
        daily_query_quota: '',
    });

    return (
        <AppLayout title="Users & Roles">
            <Head title="Users & Roles" />
            <section className="rounded-xl border border-slate-800 bg-slate-900 p-5">
                <h2 className="mb-3 text-sm font-semibold text-white">Create User</h2>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/admin/users', { onSuccess: () => form.reset() });
                    }}
                    className="grid gap-3 md:grid-cols-5 text-sm"
                >
                    <input
                        type="text"
                        required
                        placeholder="Full name"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-white"
                    />
                    <input
                        type="email"
                        required
                        placeholder="Email"
                        value={form.data.email}
                        onChange={(e) => form.setData('email', e.target.value)}
                        className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-white"
                    />
                    <input
                        type="password"
                        required
                        placeholder="Password (min 10)"
                        value={form.data.password}
                        onChange={(e) => form.setData('password', e.target.value)}
                        className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-white"
                    />
                    <select
                        value={form.data.role}
                        onChange={(e) => form.setData('role', e.target.value)}
                        className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-white"
                    >
                        <option value="reader">Reader</option>
                        <option value="manager">Knowledge Manager</option>
                        <option value="admin">Admin</option>
                    </select>
                    <button type="submit" className="rounded-lg bg-indigo-600 px-4 py-2 font-medium text-white">
                        Add User
                    </button>
                </form>
            </section>

            <section className="rounded-xl border border-slate-800 bg-slate-900 p-5 overflow-x-auto">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-slate-800 text-xs text-slate-400">
                        <tr>
                            <th className="py-2 pr-4">Name</th>
                            <th className="py-2 pr-4">Email</th>
                            <th className="py-2 pr-4">Role</th>
                            <th className="py-2 pr-4">2FA</th>
                            <th className="py-2">Status</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-800/70">
                        {users.map((u) => (
                            <tr key={u.id}>
                                <td className="py-2.5 pr-4 text-white">{u.name}</td>
                                <td className="py-2.5 pr-4 text-slate-300">{u.email}</td>
                                <td className="py-2.5 pr-4">
                                    <select
                                        value={u.role}
                                        onChange={(e) =>
                                            router.patch(`/admin/users/${u.id}`, {
                                                role: e.target.value,
                                                daily_query_quota: u.daily_query_quota,
                                                is_active: u.is_active,
                                            })
                                        }
                                        className="rounded border border-slate-700 bg-slate-950 px-2 py-1 text-xs text-white"
                                    >
                                        <option value="reader">reader</option>
                                        <option value="manager">manager</option>
                                        <option value="admin">admin</option>
                                    </select>
                                </td>
                                <td className="py-2.5 pr-4 text-xs text-slate-400">
                                    {u.two_factor_enabled ? 'Enabled' : 'Off'}
                                </td>
                                <td className="py-2.5">
                                    <button
                                        type="button"
                                        onClick={() =>
                                            router.patch(`/admin/users/${u.id}`, {
                                                role: u.role,
                                                daily_query_quota: u.daily_query_quota,
                                                is_active: !u.is_active,
                                            })
                                        }
                                        className={`rounded-full px-2.5 py-0.5 text-xs ${
                                            u.is_active ? 'bg-emerald-500/20 text-emerald-300' : 'bg-rose-500/20 text-rose-300'
                                        }`}
                                    >
                                        {u.is_active ? 'Active' : 'Disabled'}
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </section>
        </AppLayout>
    );
}
