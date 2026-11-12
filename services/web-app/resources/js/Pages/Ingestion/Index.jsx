import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';

export default function IngestionIndex({ jobs }) {
    const badge = (status) => {
        if (status === 'indexed') return 'bg-emerald-500/20 text-emerald-300';
        if (status === 'failed') return 'bg-rose-500/20 text-rose-300';
        return 'bg-amber-500/20 text-amber-300';
    };

    return (
        <AppLayout title="Ingestion Queue">
            <Head title="Ingestion Queue" />
            <div className="rounded-xl border border-slate-800 bg-slate-900 p-5 overflow-x-auto">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-slate-800 text-xs text-slate-400">
                        <tr>
                            <th className="py-2 pr-4">Document</th>
                            <th className="py-2 pr-4">File</th>
                            <th className="py-2 pr-4">Status</th>
                            <th className="py-2 pr-4">Chunks</th>
                            <th className="py-2 pr-4">Error</th>
                            <th className="py-2">Action</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-800/70">
                        {jobs.data.map((job) => (
                            <tr key={job.id}>
                                <td className="py-2.5 pr-4">
                                    <Link href={`/documents/${job.document_id}`} className="text-indigo-300 hover:underline">
                                        {job.document?.title || job.document_id}
                                    </Link>
                                </td>
                                <td className="py-2.5 pr-4 text-xs text-slate-300">
                                    v{job.document_version?.version} · {job.document_version?.filename}
                                </td>
                                <td className="py-2.5 pr-4">
                                    <span className={`rounded-full px-2.5 py-0.5 text-xs ${badge(job.status)}`}>
                                        {job.status}
                                    </span>
                                </td>
                                <td className="py-2.5 pr-4 text-slate-300">{job.document_version?.chunk_count ?? 0}</td>
                                <td className="py-2.5 pr-4 text-xs text-rose-300 max-w-xs truncate">{job.error_message}</td>
                                <td className="py-2.5">
                                    {job.status === 'failed' && (
                                        <button
                                            type="button"
                                            onClick={() => router.post(`/ingestion/${job.id}/retry`)}
                                            className="rounded bg-indigo-600 px-2.5 py-1 text-xs text-white hover:bg-indigo-500"
                                        >
                                            Retry
                                        </button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
