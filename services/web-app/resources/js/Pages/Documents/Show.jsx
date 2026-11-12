import { Head, router } from '@inertiajs/react';
import { RefreshCw, Trash2 } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';

export default function DocumentShow({ document, chunks }) {
    const [title, setTitle] = useState(document.title);
    const [tags, setTags] = useState((document.tags || []).join(', '));
    const [effectiveDate, setEffectiveDate] = useState(document.effective_date?.slice(0, 10) || '');
    const [saving, setSaving] = useState(false);

    const saveMeta = async (e) => {
        e.preventDefault();
        setSaving(true);
        await fetch(`/api/v1/documents/${document.id}`, {
            method: 'PATCH',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                title,
                tags: tags.split(',').map((t) => t.trim()).filter(Boolean),
                effective_date: effectiveDate || null,
            }),
        });
        setSaving(false);
        router.reload();
    };

    const reindex = async () => {
        await fetch(`/api/v1/documents/${document.id}/reindex`, { method: 'POST', credentials: 'same-origin' });
        router.visit('/ingestion');
    };

    const destroyDoc = async () => {
        if (!window.confirm('Delete this document and all its Qdrant vectors?')) return;
        const res = await fetch(`/api/v1/documents/${document.id}`, { method: 'DELETE', credentials: 'same-origin' });
        if (res.ok) router.visit('/documents');
    };

    return (
        <AppLayout title={`Document: ${document.title}`}>
            <Head title={document.title} />
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="text-xs text-slate-400">
                    Collection: <span className="text-slate-200">{document.collection?.name}</span> · Active: v
                    {document.active_version}
                </div>
                <div className="flex gap-2">
                    <button
                        type="button"
                        onClick={reindex}
                        className="flex items-center gap-1.5 rounded-lg bg-slate-800 px-3 py-1.5 text-xs text-slate-200 hover:bg-slate-700"
                    >
                        <RefreshCw className="h-3.5 w-3.5" /> Reindex
                    </button>
                    <button
                        type="button"
                        onClick={destroyDoc}
                        className="flex items-center gap-1.5 rounded-lg border border-rose-500/40 bg-rose-950/40 px-3 py-1.5 text-xs text-rose-300 hover:bg-rose-900/50"
                    >
                        <Trash2 className="h-3.5 w-3.5" /> Delete
                    </button>
                </div>
            </div>

            <div className="grid gap-6 lg:grid-cols-3">
                <section className="rounded-xl border border-slate-800 bg-slate-900 p-5 space-y-4">
                    <h2 className="text-sm font-semibold text-white">Metadata</h2>
                    <form onSubmit={saveMeta} className="space-y-3 text-sm">
                        <div>
                            <label className="mb-1 block text-xs text-slate-400">Title</label>
                            <input
                                type="text"
                                value={title}
                                onChange={(e) => setTitle(e.target.value)}
                                className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-1.5 text-white"
                            />
                        </div>
                        <div>
                            <label className="mb-1 block text-xs text-slate-400">Tags</label>
                            <input
                                type="text"
                                value={tags}
                                onChange={(e) => setTags(e.target.value)}
                                className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-1.5 text-white"
                            />
                        </div>
                        <div>
                            <label className="mb-1 block text-xs text-slate-400">Effective Date</label>
                            <input
                                type="date"
                                value={effectiveDate}
                                onChange={(e) => setEffectiveDate(e.target.value)}
                                className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-1.5 text-white"
                            />
                        </div>
                        <button
                            type="submit"
                            disabled={saving}
                            className="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-500"
                        >
                            {saving ? 'Saving...' : 'Save Metadata'}
                        </button>
                    </form>

                    <div className="border-t border-slate-800 pt-3">
                        <h3 className="mb-2 text-xs font-semibold uppercase text-slate-400">Versions</h3>
                        <ul className="space-y-1.5 text-xs">
                            {document.versions.map((v) => (
                                <li key={v.id} className="flex justify-between rounded bg-slate-950 px-2.5 py-1.5">
                                    <span>
                                        v{v.version} · {v.filename}
                                    </span>
                                    <span className="text-slate-400">
                                        {v.status} ({v.chunk_count} chunks)
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                </section>

                <section className="rounded-xl border border-slate-800 bg-slate-900 p-5 lg:col-span-2">
                    <h2 className="mb-3 text-sm font-semibold text-white">Chunk Inspector ({chunks.length})</h2>
                    {chunks.length === 0 ? (
                        <p className="text-xs text-slate-500">No indexed chunks returned from Qdrant yet.</p>
                    ) : (
                        <div className="max-h-[34rem] space-y-3 overflow-y-auto pr-1">
                            {chunks.map((c) => (
                                <div key={c.chunk_id} className="rounded-lg border border-slate-800 bg-slate-950 p-3 text-xs">
                                    <div className="mb-1 flex justify-between text-slate-400">
                                        <span>
                                            #{c.chunk_index} · {c.heading_path}
                                        </span>
                                        <span>{c.page ? `p. ${c.page}` : ''}</span>
                                    </div>
                                    <p className="whitespace-pre-wrap text-slate-200">{c.text}</p>
                                </div>
                            ))}
                        </div>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
