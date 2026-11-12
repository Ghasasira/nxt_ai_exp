import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';

export default function AuditLog({ logs, actionFilter }) {
    const [action, setAction] = useState(actionFilter || '');

    return (
        <AppLayout title="Audit Log">
            <Head title="Audit Log" />
            <div className="rounded-xl border border-slate-800 bg-slate-900 p-5 space-y-4">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get('/admin/audit-log', action ? { action } : {});
                    }}
                    className="flex gap-2"
                >
                    <input
                        type="text"
                        placeholder="Filter by action (e.g. document.upload, auth.login)"
                        value={action}
                        onChange={(e) => setAction(e.target.value)}
                        className="w-80 rounded-lg border border-slate-700 bg-slate-950 px-3 py-1.5 text-sm text-white"
                    />
                    <button type="submit" className="rounded-lg bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">
                        Filter
                    </button>
                </form>

                <div className="overflow-x-auto">
                    <table className="w-full text-left text-xs">
                        <thead className="border-b border-slate-800 text-slate-400">
                            <tr>
                                <th className="py-2 pr-4">Time</th>
                                <th className="py-2 pr-4">Action</th>
                                <th className="py-2 pr-4">Actor ID</th>
                                <th className="py-2 pr-4">Target</th>
                                <th className="py-2 pr-4">IP</th>
                                <th className="py-2">Metadata</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-800/70">
                            {logs.data.map((log) => (
                                <tr key={log.id}>
                                    <td className="py-2 pr-4 text-slate-400">{new Date(log.created_at).toLocaleString()}</td>
                                    <td className="py-2 pr-4 font-mono text-indigo-300">{log.action}</td>
                                    <td className="py-2 pr-4 text-slate-300">{log.actor_user_id ?? '-'}</td>
                                    <td className="py-2 pr-4 text-slate-300">
                                        {log.target_type}:{log.target_id}
                                    </td>
                                    <td className="py-2 pr-4 text-slate-400">{log.ip_address}</td>
                                    <td className="py-2 font-mono text-slate-400">{JSON.stringify(log.metadata)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
