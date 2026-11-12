import { Head, router, useForm } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';

export default function AdminApiKeys({ apiKeys, users }) {
    const form = useForm({
        name: '',
        user_id: users[0]?.id || '',
        scopes: ['chat', 'search'],
        expires_at: '',
    });

    const toggleScope = (scope) => {
        const next = form.data.scopes.includes(scope)
            ? form.data.scopes.filter((s) => s !== scope)
            : [...form.data.scopes, scope];
        form.setData('scopes', next);
    };

    return (
        <AppLayout title="API Keys">
            <Head title="API Keys" />
            <section className="rounded-xl border border-slate-800 bg-slate-900 p-5">
                <h2 className="mb-3 text-sm font-semibold text-white">Issue Scoped API Key</h2>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/admin/api-keys', { onSuccess: () => form.reset('name', 'expires_at') });
                    }}
                    className="space-y-3 text-sm"
                >
                    <div className="grid gap-3 md:grid-cols-3">
                        <input
                            type="text"
                            required
                            placeholder="Key label (e.g. Intranet Bot)"
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-white"
                        />
                        <select
                            value={form.data.user_id}
                            onChange={(e) => form.setData('user_id', e.target.value)}
                            className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-white"
                        >
                            {users.map((u) => (
                                <option key={u.id} value={u.id}>
                                    Owner: {u.name}
                                </option>
                            ))}
                        </select>
                        <input
                            type="date"
                            value={form.data.expires_at}
                            onChange={(e) => form.setData('expires_at', e.target.value)}
                            className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-white"
                        />
                    </div>
                    <div className="flex flex-wrap items-center gap-4 text-xs text-slate-300">
                        <span className="text-slate-400">Scopes:</span>
                        {['chat', 'search', 'documents', 'admin'].map((s) => (
                            <label key={s} className="flex items-center gap-1.5">
                                <input
                                    type="checkbox"
                                    checked={form.data.scopes.includes(s)}
                                    onChange={() => toggleScope(s)}
                                />
                                {s}
                            </label>
                        ))}
                        <button type="submit" className="ml-auto rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white">
                            Generate Key
                        </button>
                    </div>
                </form>
            </section>

            <section className="rounded-xl border border-slate-800 bg-slate-900 p-5 overflow-x-auto">
                <table className="w-full text-left text-xs">
                    <thead className="border-b border-slate-800 text-slate-400">
                        <tr>
                            <th className="py-2 pr-4">Name</th>
                            <th className="py-2 pr-4">Prefix</th>
                            <th className="py-2 pr-4">Owner</th>
                            <th className="py-2 pr-4">Scopes</th>
                            <th className="py-2 pr-4">Last Used</th>
                            <th className="py-2">Action</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-800/70">
                        {apiKeys.map((k) => (
                            <tr key={k.id}>
                                <td className="py-2.5 pr-4 text-white">{k.name}</td>
                                <td className="py-2.5 pr-4 font-mono text-slate-300">{k.key_prefix}...</td>
                                <td className="py-2.5 pr-4 text-slate-300">{k.user?.name}</td>
                                <td className="py-2.5 pr-4 text-slate-300">{(k.scopes || []).join(', ')}</td>
                                <td className="py-2.5 pr-4 text-slate-400">
                                    {k.last_used_at ? new Date(k.last_used_at).toLocaleString() : 'Never'}
                                </td>
                                <td className="py-2.5">
                                    {k.revoked_at ? (
                                        <span className="text-rose-400">Revoked</span>
                                    ) : (
                                        <button
                                            type="button"
                                            onClick={() => router.delete(`/admin/api-keys/${k.id}`)}
                                            className="rounded bg-rose-950/60 px-2.5 py-1 text-rose-300 hover:bg-rose-900/60"
                                        >
                                            Revoke
                                        </button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </section>
        </AppLayout>
    );
}
