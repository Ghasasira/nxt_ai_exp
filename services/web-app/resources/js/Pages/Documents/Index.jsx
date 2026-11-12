import { Head, Link, router } from '@inertiajs/react';
import { Upload } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';

export default function DocumentsIndex({ documents, collections, filters }) {
    const [collectionId, setCollectionId] = useState(collections[0]?.id || '');
    const [title, setTitle] = useState('');
    const [tags, setTags] = useState('');
    const [files, setFiles] = useState([]);
    const [uploading, setUploading] = useState(false);
    const [statusMsg, setStatusMsg] = useState('');
    const [searchQ, setSearchQ] = useState(filters?.q || '');

    const uploadFiles = async (e) => {
        e.preventDefault();
        if (!collectionId || files.length === 0) return;
        setUploading(true);
        setStatusMsg('');

        try {
            const warnings = [];
            for (const file of files) {
                const fd = new FormData();
                fd.append('collection_id', collectionId);
                if (title && files.length === 1) fd.append('title', title);
                tags.split(',')
                    .map((t) => t.trim())
                    .filter(Boolean)
                    .forEach((t, idx) => fd.append(`tags[${idx}]`, t));
                fd.append('file', file);

                const res = await fetch('/api/v1/documents', {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: fd,
                });
                const data = await res.json();
                if (!res.ok) {
                    warnings.push(`${file.name}: ${data.message || 'Upload failed'}`);
                } else if (data.duplicate_warning) {
                    warnings.push(`${file.name}: duplicate content of "${data.duplicate_warning.title}"`);
                }
            }
            setStatusMsg(warnings.length ? warnings.join(' | ') : `Queued ${files.length} file(s) for ingestion.`);
            setFiles([]);
            setTitle('');
            router.reload({ only: ['documents'] });
        } finally {
            setUploading(false);
        }
    };

    return (
        <AppLayout title="Documents">
            <Head title="Documents" />
            <section className="rounded-xl border border-slate-800 bg-slate-900 p-5">
                <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold text-white">
                    <Upload className="h-4 w-4 text-indigo-400" /> Upload Documents (PDF, DOCX, XLSX, CSV, PPTX, HTML, MD, TXT)
                </h2>
                <form onSubmit={uploadFiles} className="grid gap-3 md:grid-cols-4">
                    <select
                        value={collectionId}
                        onChange={(e) => setCollectionId(e.target.value)}
                        required
                        className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white"
                    >
                        {collections.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.name}
                            </option>
                        ))}
                    </select>
                    <input
                        type="text"
                        placeholder="Title (optional for multi-file)"
                        value={title}
                        onChange={(e) => setTitle(e.target.value)}
                        className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white"
                    />
                    <input
                        type="text"
                        placeholder="Tags (comma-separated)"
                        value={tags}
                        onChange={(e) => setTags(e.target.value)}
                        className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white"
                    />
                    <div className="flex gap-2">
                        <input
                            type="file"
                            multiple
                            onChange={(e) => setFiles(Array.from(e.target.files || []))}
                            className="w-full text-xs text-slate-300 file:mr-2 file:rounded file:border-0 file:bg-slate-800 file:px-2.5 file:py-1.5 file:text-xs file:text-white"
                        />
                        <button
                            type="submit"
                            disabled={uploading || files.length === 0}
                            className="shrink-0 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                        >
                            {uploading ? 'Uploading...' : 'Upload'}
                        </button>
                    </div>
                </form>
                {statusMsg && <p className="mt-2 text-xs text-indigo-300">{statusMsg}</p>}
            </section>

            <section className="rounded-xl border border-slate-800 bg-slate-900 p-5 space-y-4">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get('/documents', { q: searchQ }, { preserveState: true });
                    }}
                    className="flex gap-2"
                >
                    <input
                        type="text"
                        placeholder="Search by title..."
                        value={searchQ}
                        onChange={(e) => setSearchQ(e.target.value)}
                        className="w-72 rounded-lg border border-slate-700 bg-slate-950 px-3 py-1.5 text-sm text-white"
                    />
                    <button type="submit" className="rounded-lg bg-slate-800 px-3 py-1.5 text-sm text-slate-200 hover:bg-slate-700">
                        Filter
                    </button>
                </form>

                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead className="border-b border-slate-800 text-xs text-slate-400">
                            <tr>
                                <th className="py-2 pr-4">Title</th>
                                <th className="py-2 pr-4">Collection</th>
                                <th className="py-2 pr-4">Active Version</th>
                                <th className="py-2 pr-4">Latest Status</th>
                                <th className="py-2">Updated</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-800/70">
                            {documents.data.map((doc) => (
                                <tr key={doc.id} className="hover:bg-slate-800/40">
                                    <td className="py-2.5 pr-4">
                                        <Link href={`/documents/${doc.id}`} className="font-medium text-indigo-300 hover:underline">
                                            {doc.title}
                                        </Link>
                                    </td>
                                    <td className="py-2.5 pr-4 text-slate-300">{doc.collection?.name}</td>
                                    <td className="py-2.5 pr-4 text-slate-300">
                                        {doc.active_version > 0 ? `v${doc.active_version}` : 'Pending'}
                                    </td>
                                    <td className="py-2.5 pr-4">
                                        <span className="rounded bg-slate-800 px-2 py-0.5 text-xs text-slate-200">
                                            {doc.versions?.[0]?.status || 'queued'}
                                        </span>
                                    </td>
                                    <td className="py-2.5 text-xs text-slate-400">
                                        {new Date(doc.updated_at).toLocaleString()}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </section>
        </AppLayout>
    );
}
